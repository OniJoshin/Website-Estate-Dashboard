<?php

namespace App\Services\Monitoring;

use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Models\CpanelAccount;
use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\ServerCheck;
use App\Support\MonitoringThresholds;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class IssueEvaluator
{
    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly MonitoringThresholds $thresholds) {}

    public function evaluateDomainCheck(DomainCheck $check): void
    {
        match ($check->check_type) {
            'http' => $this->evaluateHttp($check),
            'dns' => $this->evaluateDns($check),
            'tls' => $this->evaluateTls($check),
            default => null,
        };
    }

    public function evaluateServerCheck(ServerCheck $check): void
    {
        $this->evaluateServerHealth($check);
        $this->evaluateDisk($check);
    }

    public function evaluateAccount(CpanelAccount $account): void
    {
        $this->withIssueLock('account', $account->id, IssueType::AccountSuspended, function () use ($account): void {
            $active = $this->activeIssue('cpanel_account_id', $account->id, IssueType::AccountSuspended);

            if ($account->removed_at !== null || ! $account->suspended) {
                $this->resolve($active);

                return;
            }

            $this->openOrTouch(
                $active,
                'cpanel_account_id',
                $account->id,
                IssueType::AccountSuspended,
                IssueSeverity::Warning,
                'cPanel account suspended',
                'cPanel account is suspended.',
            );
        });
    }

    private function evaluateHttp(DomainCheck $check): void
    {
        $this->evaluateDebouncedDomainIssue(
            $check,
            IssueType::HttpUnavailable,
            IssueSeverity::Critical,
            'Website unavailable',
            $check->http_status !== null && $check->http_status >= 500
                ? "HTTP {$check->http_status} returned."
                : 'HTTP monitoring could not complete.',
            $this->thresholds->httpFailureDebounce(),
            $this->thresholds->httpRecoveryDebounce(),
            static fn (DomainCheck $candidate): bool => ! $candidate->successful
                || ($candidate->http_status !== null && $candidate->http_status >= 500),
            static fn (DomainCheck $candidate): bool => $candidate->successful
                && $candidate->http_status !== null
                && $candidate->http_status < 500,
        );

        $this->evaluateDebouncedDomainIssue(
            $check,
            IssueType::HttpClientError,
            IssueSeverity::Warning,
            'Website returning client error',
            $check->http_status !== null ? "Homepage returned HTTP {$check->http_status}." : 'Homepage returned a client error.',
            $this->thresholds->httpFailureDebounce(),
            $this->thresholds->httpRecoveryDebounce(),
            static fn (DomainCheck $candidate): bool => $candidate->successful
                && $candidate->http_status !== null
                && $candidate->http_status >= 400
                && $candidate->http_status < 500,
            static fn (DomainCheck $candidate): bool => $candidate->successful
                && $candidate->http_status !== null
                && ($candidate->http_status < 400 || $candidate->http_status >= 500),
        );

        $this->evaluateDebouncedDomainIssue(
            $check,
            IssueType::HttpSlow,
            IssueSeverity::Warning,
            'Website responding slowly',
            $check->response_time_ms !== null
                ? 'Response time was '.number_format($check->response_time_ms).' ms.'
                : 'Website response was slow.',
            $this->thresholds->slowHttpDebounce(),
            $this->thresholds->httpRecoveryDebounce(),
            fn (DomainCheck $candidate): bool => $candidate->successful
                && $candidate->response_time_ms !== null
                && $candidate->response_time_ms > $this->thresholds->slowHttpMilliseconds(),
            fn (DomainCheck $candidate): bool => $candidate->successful
                && $candidate->response_time_ms !== null
                && $candidate->response_time_ms <= $this->thresholds->slowHttpMilliseconds(),
        );
    }

    private function evaluateDns(DomainCheck $check): void
    {
        $this->evaluateDebouncedDomainIssue(
            $check,
            IssueType::DnsUnresolved,
            IssueSeverity::Critical,
            'DNS resolution failed',
            'DNS resolution failed.',
            $this->thresholds->dnsFailureDebounce(),
            $this->thresholds->dnsRecoveryDebounce(),
            static fn (DomainCheck $candidate): bool => ! $candidate->successful,
            static fn (DomainCheck $candidate): bool => $candidate->successful,
        );
    }

    private function evaluateTls(DomainCheck $check): void
    {
        $this->withTargetLock('domain', $check->domain_id, 'tls', function () use ($check): void {
            $isInvalid = ! $check->successful && $check->error_type === 'tls_invalid';
            $isUnavailable = ! $check->successful && ! $isInvalid;
            $invalid = $this->activeIssue('domain_id', $check->domain_id, IssueType::TlsInvalid);
            $unavailable = $this->activeIssue('domain_id', $check->domain_id, IssueType::TlsUnavailable);

            if ($isInvalid) {
                $this->resolve($unavailable);
                $this->openOrTouch($invalid, 'domain_id', $check->domain_id, IssueType::TlsInvalid, IssueSeverity::Critical, 'TLS certificate invalid', 'TLS certificate validation failed.');
            } elseif ($isUnavailable) {
                $this->resolve($invalid);
                $this->openOrTouch($unavailable, 'domain_id', $check->domain_id, IssueType::TlsUnavailable, IssueSeverity::Critical, 'TLS inspection unavailable', 'TLS inspection could not be completed.');
            } else {
                $this->resolve($invalid);
                $this->resolve($unavailable);
            }

            if (! $check->successful || ! $check->ssl_valid || $check->ssl_days_remaining === null) {
                return;
            }

            $days = $check->ssl_days_remaining;
            $severity = match (true) {
                $days < $this->thresholds->sslCriticalDays() => IssueSeverity::Critical,
                $days < $this->thresholds->sslWarningDays() => IssueSeverity::Warning,
                default => null,
            };
            $expiring = $this->activeIssue('domain_id', $check->domain_id, IssueType::TlsExpiring);

            if ($severity === null) {
                $this->resolve($expiring);

                return;
            }

            $this->openOrTouch($expiring, 'domain_id', $check->domain_id, IssueType::TlsExpiring, $severity, 'TLS certificate expiring', "Certificate expires in {$days} days.");
        });
    }

    private function evaluateServerHealth(ServerCheck $check): void
    {
        $this->withIssueLock('server', $check->server_id, IssueType::ServerHealthUnavailable, function () use ($check): void {
            $active = $this->activeIssue('server_id', $check->server_id, IssueType::ServerHealthUnavailable);
            $checks = $this->latestServerChecks($check, max(
                $this->thresholds->serverFailureDebounce(),
                $this->thresholds->serverRecoveryDebounce(),
            ));

            if (! $check->reachable && ($active !== null || $this->consecutiveServerChecks(
                $checks,
                $this->thresholds->serverFailureDebounce(),
                static fn (ServerCheck $candidate): bool => ! $candidate->reachable,
            ))) {
                $this->openOrTouch(
                    $active,
                    'server_id',
                    $check->server_id,
                    IssueType::ServerHealthUnavailable,
                    IssueSeverity::Critical,
                    'WHM health check unavailable',
                    'WHM server health check failed.',
                );

                return;
            }

            if ($active !== null && $this->consecutiveServerChecks(
                $checks,
                $this->thresholds->serverRecoveryDebounce(),
                static fn (ServerCheck $candidate): bool => $candidate->reachable,
            )) {
                $this->resolve($active);
            }
        });
    }

    private function evaluateDisk(ServerCheck $check): void
    {
        /** @var array<int, mixed>|null $partitions */
        $partitions = $check->getAttribute('partitions');

        if (! $check->reachable || $partitions === null) {
            return;
        }

        $worst = null;
        foreach ($partitions as $partition) {
            $percentage = is_array($partition) ? ($partition['used_percent'] ?? null) : null;
            if (! is_numeric($percentage)) {
                continue;
            }

            $candidate = [
                'identifier' => $this->partitionIdentifier($partition),
                'percentage' => (float) $percentage,
            ];
            if ($worst === null || $candidate['percentage'] > $worst['percentage']) {
                $worst = $candidate;
            }
        }

        if ($worst === null) {
            return;
        }

        $highest = $worst['percentage'];
        $severity = match (true) {
            $highest >= $this->thresholds->diskCriticalPercent() => IssueSeverity::Critical,
            $highest >= $this->thresholds->diskWarningPercent() => IssueSeverity::Warning,
            default => null,
        };

        $this->withIssueLock('server', $check->server_id, IssueType::DiskUsage, function () use ($check, $highest, $severity, $worst): void {
            $active = $this->activeIssue('server_id', $check->server_id, IssueType::DiskUsage);

            if ($severity === null) {
                $this->resolve($active);

                return;
            }

            $formatted = rtrim(rtrim(number_format($highest, 2, '.', ''), '0'), '.');
            $this->openOrTouch(
                $active,
                'server_id',
                $check->server_id,
                IssueType::DiskUsage,
                $severity,
                'Server disk usage high',
                "{$worst['identifier']} is {$formatted}% used.",
            );
        });
    }

    /** @param array<string, mixed> $partition */
    private function partitionIdentifier(array $partition): string
    {
        $identifier = $partition['mount'] ?? $partition['filesystem'] ?? null;

        if (! is_string($identifier) || trim($identifier) === '') {
            return 'Partition';
        }

        return mb_substr(trim($identifier), 0, 100);
    }

    private function evaluateDebouncedDomainIssue(
        DomainCheck $check,
        IssueType $type,
        IssueSeverity $severity,
        string $title,
        string $details,
        int $openingCount,
        int $recoveryCount,
        Closure $isFailure,
        Closure $isRecovery,
    ): void {
        $this->withIssueLock('domain', $check->domain_id, $type, function () use (
            $check, $type, $severity, $title, $details, $openingCount, $recoveryCount, $isFailure, $isRecovery,
        ): void {
            $active = $this->activeIssue('domain_id', $check->domain_id, $type);
            $checks = $this->latestDomainChecks($check, max($openingCount, $recoveryCount));

            if ($isFailure($check) && ($active !== null || $this->consecutiveDomainChecks($checks, $openingCount, $isFailure))) {
                $this->openOrTouch($active, 'domain_id', $check->domain_id, $type, $severity, $title, $details);

                return;
            }

            if ($active !== null && $isRecovery($check) && $this->consecutiveDomainChecks($checks, $recoveryCount, $isRecovery)) {
                $this->resolve($active);
            }
        });
    }

    /** @return Collection<int, DomainCheck> */
    private function latestDomainChecks(DomainCheck $check, int $limit): Collection
    {
        return DomainCheck::where('domain_id', $check->domain_id)
            ->where('check_type', $check->check_type)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, ServerCheck> */
    private function latestServerChecks(ServerCheck $check, int $limit): Collection
    {
        return ServerCheck::where('server_id', $check->server_id)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @param Collection<int, DomainCheck> $checks */
    private function consecutiveDomainChecks(Collection $checks, int $required, Closure $predicate): bool
    {
        return $checks->count() >= $required && $checks->take($required)->every($predicate);
    }

    /** @param Collection<int, ServerCheck> $checks */
    private function consecutiveServerChecks(Collection $checks, int $required, Closure $predicate): bool
    {
        return $checks->count() >= $required && $checks->take($required)->every($predicate);
    }

    private function activeIssue(string $foreignKey, int $targetId, IssueType $type): ?Issue
    {
        return Issue::query()
            ->where($foreignKey, $targetId)
            ->where('type', $type)
            ->whereNull('resolved_at')
            ->latest('id')
            ->first();
    }

    private function openOrTouch(
        ?Issue $issue,
        string $foreignKey,
        int $targetId,
        IssueType $type,
        IssueSeverity $severity,
        string $title,
        string $details,
    ): Issue {
        if ($issue === null) {
            return Issue::create([
                $foreignKey => $targetId,
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'details' => $details,
                'first_detected_at' => now(),
                'last_detected_at' => now(),
            ]);
        }

        $issue->update([
            'severity' => $severity,
            'title' => $title,
            'details' => $details,
            'last_detected_at' => now(),
        ]);

        return $issue;
    }

    private function resolve(?Issue $issue): void
    {
        if ($issue !== null) {
            $issue->update(['resolved_at' => now()]);
        }
    }

    private function withIssueLock(string $targetType, int $targetId, IssueType $type, Closure $callback): void
    {
        $this->withTargetLock($targetType, $targetId, $type->value, $callback);
    }

    private function withTargetLock(string $targetType, int $targetId, string $scope, Closure $callback): void
    {
        Cache::lock(
            "estate:issue:{$targetType}:{$targetId}:{$scope}",
            self::LOCK_SECONDS,
        )->block(self::LOCK_WAIT_SECONDS, function () use ($callback): void {
            DB::transaction(function () use ($callback): void {
                $callback();
            });
        });
    }
}
