<?php

namespace App\Jobs\Monitoring;

use App\Models\Domain;
use App\Services\Monitoring\Contracts\TlsInspector;
use App\Services\Monitoring\IssueEvaluator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CheckDomainTls implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 5;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(public int $domainId) {}

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(TlsInspector $inspector, IssueEvaluator $evaluator): void
    {
        $domain = Domain::query()
            ->with('cpanelAccount.server')
            ->find($this->domainId);

        if ($domain === null
            || $domain->removed_at !== null
            || ! $domain->is_active
            || ! $domain->monitoring_enabled
        ) {
            return;
        }

        $account = $domain->cpanelAccount;

        if ($account === null || $account->removed_at !== null) {
            return;
        }

        $server = $account->server;

        if ($server === null || ! $server->enabled) {
            return;
        }

        $result = $inspector->inspect($domain->domain, 443);

        Cache::lock("estate:observation:domain:{$domain->id}:tls", self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($domain, $result, $evaluator): void {
                DB::transaction(function () use ($domain, $result, $evaluator): void {
                    $check = $domain->domainChecks()->create([
                        'check_type' => 'tls',
                        'checked_at' => now(),
                        'successful' => $result->successful,
                        'ssl_valid' => $result->sslValid,
                        'ssl_expires_at' => $result->expiresAt,
                        'ssl_days_remaining' => $result->daysRemaining,
                        'error_type' => $result->errorType,
                        'error_message' => $result->errorMessage,
                    ]);

                    $evaluator->evaluateDomainCheck($check);
                });
            });
    }
}
