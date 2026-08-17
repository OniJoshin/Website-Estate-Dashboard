<?php

namespace App\Jobs\Monitoring;

use App\Models\Domain;
use App\Services\Monitoring\Contracts\DnsResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckDomainDns implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $domainId) {}

    public function handle(DnsResolver $resolver): void
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

        $result = $resolver->resolve($domain->domain);

        $domain->domainChecks()->create([
            'check_type' => 'dns',
            'checked_at' => now(),
            'successful' => $result->successful,
            'resolved_ips' => [
                'a' => $result->a,
                'aaaa' => $result->aaaa,
                'cname' => $result->cname,
            ],
            'error_type' => $result->errorType,
            'error_message' => $result->errorMessage,
        ]);
    }
}
