<?php

namespace App\Jobs\Monitoring;

use App\Models\Domain;
use App\Services\Monitoring\HttpProbe;
use App\Services\Monitoring\IssueEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CheckDomainHttp implements ShouldQueue
{
    use Queueable;

    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 5;

    public int $tries = 1;

    public function __construct(public int $domainId) {}

    public function handle(HttpProbe $probe, IssueEvaluator $evaluator): void
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

        $result = $probe->check($domain->domain);

        Cache::lock("estate:observation:domain:{$domain->id}:http", self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($domain, $result, $evaluator): void {
                DB::transaction(function () use ($domain, $result, $evaluator): void {
                    $check = $domain->domainChecks()->create([
                        'check_type' => 'http',
                        'checked_at' => now(),
                        'successful' => $result->successful,
                        'http_status' => $result->httpStatus,
                        'response_time_ms' => $result->responseTimeMs,
                        'final_url' => $result->finalUrl,
                        'redirect_count' => $result->redirectCount,
                        'error_type' => $result->errorType,
                        'error_message' => $result->errorMessage,
                    ]);

                    $evaluator->evaluateDomainCheck($check);
                });
            });
    }
}
