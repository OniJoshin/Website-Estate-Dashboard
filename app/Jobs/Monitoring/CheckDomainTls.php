<?php

namespace App\Jobs\Monitoring;

use App\Models\Domain;
use App\Services\Monitoring\Contracts\TlsInspector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckDomainTls implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $domainId) {}

    public function handle(TlsInspector $inspector): void
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

        $domain->domainChecks()->create([
            'check_type' => 'tls',
            'checked_at' => now(),
            'successful' => $result->successful,
            'ssl_valid' => $result->sslValid,
            'ssl_expires_at' => $result->expiresAt,
            'ssl_days_remaining' => $result->daysRemaining,
            'error_type' => $result->errorType,
            'error_message' => $result->errorMessage,
        ]);
    }
}
