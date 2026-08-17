<?php

namespace App\Jobs\Monitoring;

use App\Models\Domain;
use App\Services\Monitoring\HttpProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckDomainHttp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $domainId) {}

    public function handle(HttpProbe $probe): void
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

        $domain->domainChecks()->create([
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
    }
}
