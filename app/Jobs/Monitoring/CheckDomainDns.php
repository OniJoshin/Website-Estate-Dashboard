<?php

namespace App\Jobs\Monitoring;

use App\Models\Domain;
use App\Services\Monitoring\Contracts\DnsResolver;
use App\Services\Monitoring\IssueEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CheckDomainDns implements ShouldQueue
{
    use Queueable;

    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 5;

    public int $tries = 1;

    public function __construct(public int $domainId) {}

    public function handle(DnsResolver $resolver, IssueEvaluator $evaluator): void
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

        Cache::lock("estate:observation:domain:{$domain->id}:dns", self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($domain, $result, $evaluator): void {
                DB::transaction(function () use ($domain, $result, $evaluator): void {
                    $check = $domain->domainChecks()->create([
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

                    $evaluator->evaluateDomainCheck($check);
                });
            });
    }
}
