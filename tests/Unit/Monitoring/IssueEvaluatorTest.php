<?php

namespace Tests\Unit\Monitoring;

use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Services\Monitoring\IssueEvaluator;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class IssueEvaluatorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private IssueEvaluator $evaluator;

    private Server $server;

    private CpanelAccount $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-17 10:00:00 UTC'));
        $this->server = Server::factory()->create();
        $this->account = CpanelAccount::factory()->for($this->server)->create();
        $this->domain = Domain::factory()->for($this->account)->create();
        $this->evaluator = app(IssueEvaluator::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_issue_type_is_a_backed_model_cast(): void
    {
        $issue = Issue::factory()->forDomain($this->domain)->create(['type' => IssueType::HttpSlow]);

        $this->assertSame(IssueType::HttpSlow, $issue->type);
        $this->assertSame('http_slow', $issue->getRawOriginal('type'));
    }

    public function test_http_unavailable_requires_consecutive_failures_and_recovers_after_two_good_responses(): void
    {
        $this->http(false);
        $this->assertNoActive(IssueType::HttpUnavailable);

        $this->http(false);
        $issue = $this->active(IssueType::HttpUnavailable);
        $this->assertSame(IssueSeverity::Critical, $issue->severity);
        $this->assertSame('Website unavailable', $issue->title);
        $this->assertOnlyDomainTarget($issue);

        $this->http(true, 200);
        $this->http(false);
        $this->http(true, 200);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->http(true, 200);
        $this->assertNotNull($issue->fresh()->resolved_at);
    }

    public function test_http_issue_is_touched_and_recurrence_creates_a_new_incident(): void
    {
        $this->http(false);
        $this->http(false);
        $first = $this->active(IssueType::HttpUnavailable);
        $firstDetected = $first->first_detected_at;

        CarbonImmutable::setTestNow(now()->addMinute());
        $this->http(false);
        $this->assertTrue($first->fresh()->first_detected_at->equalTo($firstDetected));
        $this->assertTrue($first->fresh()->last_detected_at->equalTo(now()));

        $this->http(true, 200);
        $this->http(true, 200);
        $this->http(false);
        $this->http(false);

        $second = $this->active(IssueType::HttpUnavailable);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_http_5xx_uses_same_debounce_and_intervening_success_breaks_sequence(): void
    {
        $this->http(true, 500);
        $this->assertNoActive(IssueType::HttpUnavailable);
        $this->http(true, 200);
        $this->http(true, 503);
        $this->assertNoActive(IssueType::HttpUnavailable);

        $this->http(true, 500);
        $issue = $this->active(IssueType::HttpUnavailable);
        $this->assertSame('HTTP 500 returned.', $issue->details);
    }

    public function test_http_client_error_debounces_and_transport_failure_is_not_recovery(): void
    {
        $this->http(true, 404);
        $this->assertNoActive(IssueType::HttpClientError);
        $this->http(true, 401);
        $issue = $this->active(IssueType::HttpClientError);
        $this->assertSame(IssueSeverity::Warning, $issue->severity);
        $this->assertSame('Homepage returned HTTP 401.', $issue->details);

        $this->http(false);
        $this->http(true, 200);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->http(true, 500);
        $this->assertNotNull($issue->fresh()->resolved_at);
        $this->http(true, 503);
        $this->active(IssueType::HttpUnavailable);
    }

    public function test_http_slow_uses_configured_opening_and_recovery_and_fast_check_breaks_sequence(): void
    {
        $this->http(true, 200, 2_841);
        $this->http(true, 200, 1_000);
        $this->http(true, 200, 2_500);
        $this->http(true, 200, 2_600);
        $this->assertNoActive(IssueType::HttpSlow);
        $this->http(true, 200, 2_700);
        $issue = $this->active(IssueType::HttpSlow);
        $this->assertSame(IssueSeverity::Warning, $issue->severity);
        $this->assertSame('Response time was 2,700 ms.', $issue->details);

        $this->http(true, 200, 1_500);
        $this->http(false);
        $this->http(true, 200, 1_500);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->http(true, 200, 2_000);
        $this->assertNotNull($issue->fresh()->resolved_at);
    }

    public function test_null_response_time_neither_opens_nor_recovers_slow_issue(): void
    {
        $this->http(true, 200, null);
        $this->http(true, 200, null);
        $this->http(true, 200, null);
        $this->assertNoActive(IssueType::HttpSlow);

        $this->http(true, 200, 3_000);
        $this->http(true, 200, 3_000);
        $this->http(true, 200, 3_000);
        $issue = $this->active(IssueType::HttpSlow);
        $this->http(true, 200, null);
        $this->http(true, 200, 1_000);
        $this->assertNull($issue->fresh()->resolved_at);
    }

    public function test_dns_debounce_recovery_sequence_and_recurrence_create_incident_history(): void
    {
        $this->dns(false);
        $this->assertNoActive(IssueType::DnsUnresolved);
        $this->dns(true);
        $this->dns(false);
        $this->assertNoActive(IssueType::DnsUnresolved);
        $this->dns(false);
        $first = $this->active(IssueType::DnsUnresolved);
        $this->assertSame(IssueSeverity::Critical, $first->severity);

        $this->dns(true);
        $this->assertNull($first->fresh()->resolved_at);
        $this->dns(true);
        $this->assertNotNull($first->fresh()->resolved_at);

        $this->dns(false);
        $this->dns(false);
        $second = $this->active(IssueType::DnsUnresolved);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotNull($first->fresh()->resolved_at);
        $this->assertSame(2, Issue::where('type', IssueType::DnsUnresolved)->count());
    }

    public function test_consecutive_checks_use_checked_at_then_id_descending(): void
    {
        $this->httpCheck(false, checkedAt: now());
        $olderFailure = $this->httpCheck(false, checkedAt: now()->subMinute());
        $this->httpCheck(true, 200, checkedAt: now()->addMinute());
        $this->evaluator->evaluateDomainCheck($olderFailure);
        $this->assertNoActive(IssueType::HttpUnavailable);

        $secondDomain = Domain::factory()->for($this->account)->create();
        $checkedAt = now()->addMinutes(2);
        $this->httpCheck(true, 200, checkedAt: $checkedAt, domain: $secondDomain);
        $this->httpCheck(false, checkedAt: $checkedAt, domain: $secondDomain);
        $newestFailure = $this->httpCheck(false, checkedAt: $checkedAt, domain: $secondDomain);
        $this->evaluator->evaluateDomainCheck($newestFailure);

        $this->assertSame(1, Issue::where('domain_id', $secondDomain->id)
            ->where('type', IssueType::HttpUnavailable)
            ->whereNull('resolved_at')
            ->count());
    }

    public function test_tls_failure_types_open_immediately_resolve_and_are_mutually_exclusive(): void
    {
        $this->tls(false, false, 'tls_invalid');
        $invalid = $this->active(IssueType::TlsInvalid);
        $this->assertSame(IssueSeverity::Critical, $invalid->severity);

        $this->tls(true, true, null, 60);
        $this->assertNotNull($invalid->fresh()->resolved_at);

        $this->tls(false, false, 'timeout');
        $unavailable = $this->active(IssueType::TlsUnavailable);
        $this->assertNoActive(IssueType::TlsInvalid);

        $this->tls(true, true, null, 60);
        $this->assertNotNull($unavailable->fresh()->resolved_at);

        $this->tls(false, false, 'tls_invalid');
        $invalid = $this->active(IssueType::TlsInvalid);
        $this->tls(false, false, 'certificate_parse_error');
        $this->assertNotNull($invalid->fresh()->resolved_at);
        $unavailable = $this->active(IssueType::TlsUnavailable);
        $this->assertNoActive(IssueType::TlsInvalid);

        $this->tls(false, false, 'tls_invalid');
        $this->assertNotNull($unavailable->fresh()->resolved_at);
        $this->active(IssueType::TlsInvalid);
        $this->assertNoActive(IssueType::TlsUnavailable);

        $this->tls(true, true, null, 60);
        $this->assertNoActive(IssueType::TlsInvalid);
        $this->assertNoActive(IssueType::TlsUnavailable);
    }

    public function test_tls_expiry_boundaries_and_severity_changes_reuse_active_issue(): void
    {
        $this->tls(true, true, null, 30);
        $this->assertNoActive(IssueType::TlsExpiring);

        $this->tls(true, true, null, 29);
        $issue = $this->active(IssueType::TlsExpiring);
        $this->assertSame(IssueSeverity::Warning, $issue->severity);
        $this->assertSame('Certificate expires in 29 days.', $issue->details);

        $this->tls(true, true, null, 7);
        $this->assertSame(IssueSeverity::Warning, $issue->fresh()->severity);
        $this->tls(true, true, null, 6);
        $this->assertSame(IssueSeverity::Critical, $issue->fresh()->severity);
        $this->assertSame(1, Issue::whereNull('resolved_at')->where('type', IssueType::TlsExpiring)->count());

        $this->tls(true, true, null, 20);
        $this->assertSame(IssueSeverity::Warning, $issue->fresh()->severity);
        $this->tls(false, false, 'connection_failed');
        $this->assertNull($issue->fresh()->resolved_at);
        $this->tls(true, true, null, 30);
        $this->assertNotNull($issue->fresh()->resolved_at);
    }

    public function test_server_health_debounce_sequence_and_recovery(): void
    {
        $this->serverCheck(false);
        $this->assertNoActive(IssueType::ServerHealthUnavailable);
        $this->serverCheck(true);
        $this->serverCheck(false);
        $this->assertNoActive(IssueType::ServerHealthUnavailable);
        $this->serverCheck(false);
        $issue = $this->active(IssueType::ServerHealthUnavailable);
        $this->assertSame(IssueSeverity::Critical, $issue->severity);

        $this->serverCheck(true);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->serverCheck(true);
        $this->assertNotNull($issue->fresh()->resolved_at);
    }

    public function test_disk_boundaries_worst_partition_and_severity_updates_reuse_issue(): void
    {
        $this->serverCheck(true, 84);
        $this->assertNoActive(IssueType::DiskUsage);
        $this->serverCheck(true, 85);
        $issue = $this->active(IssueType::DiskUsage);
        $this->assertSame(IssueSeverity::Warning, $issue->severity);

        $this->serverCheck(true, 94);
        $this->assertSame(IssueSeverity::Warning, $issue->fresh()->severity);
        $this->serverCheck(true, 95);
        $this->assertSame(IssueSeverity::Critical, $issue->fresh()->severity);
        $this->assertSame('/ is 95% used.', $issue->fresh()->details);

        $this->serverCheck(true, 86, 97);
        $this->assertSame(IssueSeverity::Critical, $issue->fresh()->severity);
        $this->assertSame('/home is 97% used.', $issue->fresh()->details);
        $this->serverCheck(true, 90);
        $this->assertSame(IssueSeverity::Warning, $issue->fresh()->severity);
        $this->assertSame(1, Issue::whereNull('resolved_at')->where('type', IssueType::DiskUsage)->count());

        $this->serverCheck(true);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->serverCheck(false);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->serverCheck(true, 84);
        $this->assertNotNull($issue->fresh()->resolved_at);
    }

    public function test_high_load_alone_never_creates_issue(): void
    {
        $check = ServerCheck::factory()->for($this->server)->create([
            'reachable' => true,
            'load_1m' => 999,
            'load_5m' => 888,
            'load_15m' => 777,
            'partitions' => [],
        ]);

        $this->evaluator->evaluateServerCheck($check);

        $this->assertSame(0, Issue::query()->count());
    }

    public function test_account_suspension_touches_resolves_and_targets_only_account(): void
    {
        $this->account->update(['suspended' => true]);
        $this->evaluator->evaluateAccount($this->account->fresh());
        $issue = $this->active(IssueType::AccountSuspended);
        $this->assertSame(IssueSeverity::Warning, $issue->severity);
        $this->assertOnlyAccountTarget($issue);
        $firstDetected = $issue->first_detected_at;

        CarbonImmutable::setTestNow(now()->addMinute());
        $this->evaluator->evaluateAccount($this->account->fresh());
        $this->assertTrue($issue->fresh()->first_detected_at->equalTo($firstDetected));
        $this->assertTrue($issue->fresh()->last_detected_at->equalTo(now()));

        $this->account->update(['suspended' => false]);
        $this->evaluator->evaluateAccount($this->account->fresh());
        $this->assertNotNull($issue->fresh()->resolved_at);

        $this->account->update(['suspended' => true, 'removed_at' => null]);
        $this->evaluator->evaluateAccount($this->account->fresh());
        $second = $this->active(IssueType::AccountSuspended);
        $this->account->update(['removed_at' => now()]);
        $this->evaluator->evaluateAccount($this->account->fresh());
        $this->assertNotNull($second->fresh()->resolved_at);
    }

    public function test_repeated_evaluation_is_idempotent_and_never_copies_secrets(): void
    {
        $check = DomainCheck::factory()->for($this->domain)->create([
            'check_type' => 'tls',
            'successful' => false,
            'ssl_valid' => false,
            'error_type' => 'tls_invalid',
            'error_message' => 'Authorization: whm root:TASK14_SUPER_SECRET_TOKEN raw response body <html> stack trace',
        ]);

        $this->evaluator->evaluateDomainCheck($check);
        CarbonImmutable::setTestNow(now()->addMinute());
        $this->evaluator->evaluateDomainCheck($check);

        $issues = Issue::whereNull('resolved_at')->get();
        $this->assertCount(1, $issues);
        $serialized = serialize($issues->firstOrFail()->toArray());
        $this->assertStringNotContainsString('TASK14_SUPER_SECRET_TOKEN', $serialized);
        $this->assertStringNotContainsString('Authorization', $serialized);
        $this->assertStringNotContainsString('response body', $serialized);
        $this->assertStringNotContainsString('stack trace', $serialized);
    }

    private function http(bool $successful, ?int $status = null, ?int $responseTime = null): void
    {
        $check = $this->httpCheck($successful, $status, $responseTime);
        $this->evaluator->evaluateDomainCheck($check);
    }

    private function httpCheck(
        bool $successful,
        ?int $status = null,
        ?int $responseTime = null,
        ?DateTimeInterface $checkedAt = null,
        ?Domain $domain = null,
    ): DomainCheck {
        return DomainCheck::factory()->for($domain ?? $this->domain)->create([
            'check_type' => 'http',
            'successful' => $successful,
            'http_status' => $status,
            'response_time_ms' => $responseTime,
            'checked_at' => $checkedAt ?? now(),
        ]);
    }

    private function dns(bool $successful): void
    {
        $check = DomainCheck::factory()->for($this->domain)->create([
            'check_type' => 'dns',
            'successful' => $successful,
            'checked_at' => now(),
        ]);
        $this->evaluator->evaluateDomainCheck($check);
    }

    private function tls(bool $successful, bool $valid, ?string $errorType, ?int $days = null): void
    {
        $check = DomainCheck::factory()->for($this->domain)->create([
            'check_type' => 'tls',
            'successful' => $successful,
            'ssl_valid' => $valid,
            'ssl_days_remaining' => $days,
            'ssl_expires_at' => $days === null ? null : now()->addDays($days),
            'error_type' => $errorType,
            'checked_at' => now(),
        ]);
        $this->evaluator->evaluateDomainCheck($check);
    }

    private function serverCheck(bool $reachable, ?float $percent = null, ?float $secondPercent = null): void
    {
        $partitions = $percent === null ? ($reachable ? [] : null) : [[
            'filesystem' => '/dev/vda1', 'mount' => '/', 'total' => 100.0,
            'used' => $percent, 'available' => 100 - $percent, 'used_percent' => $percent,
        ]];
        if ($secondPercent !== null) {
            $partitions[] = [
                'filesystem' => '/dev/vdb1', 'mount' => '/home', 'total' => 100.0,
                'used' => $secondPercent, 'available' => 100 - $secondPercent, 'used_percent' => $secondPercent,
            ];
        }
        $check = ServerCheck::factory()->for($this->server)->create([
            'reachable' => $reachable,
            'partitions' => $partitions,
            'checked_at' => now(),
        ]);
        $this->evaluator->evaluateServerCheck($check);
    }

    private function active(IssueType $type): Issue
    {
        return Issue::where('type', $type)->whereNull('resolved_at')->sole();
    }

    private function assertNoActive(IssueType $type): void
    {
        $this->assertSame(0, Issue::where('type', $type)->whereNull('resolved_at')->count());
    }

    private function assertOnlyDomainTarget(Issue $issue): void
    {
        $this->assertSame($this->domain->id, $issue->domain_id);
        $this->assertNull($issue->server_id);
        $this->assertNull($issue->cpanel_account_id);
    }

    private function assertOnlyAccountTarget(Issue $issue): void
    {
        $this->assertSame($this->account->id, $issue->cpanel_account_id);
        $this->assertNull($issue->server_id);
        $this->assertNull($issue->domain_id);
    }
}
