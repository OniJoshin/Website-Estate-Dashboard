<?php

namespace App\Console\Commands;

use App\Jobs\Monitoring\CheckDomainDns;
use App\Jobs\Monitoring\CheckDomainHttp;
use App\Jobs\Monitoring\CheckDomainTls;
use App\Models\Domain;
use Illuminate\Console\Command;

class DispatchDomainChecks extends Command
{
    protected $signature = 'estate:dispatch-domain-checks {type : http, dns, or tls}';

    protected $description = 'Queue a monitoring check type for eligible domains';

    public function handle(): int
    {
        $type = $this->argument('type');
        $jobClass = match ($type) {
            'http' => CheckDomainHttp::class,
            'dns' => CheckDomainDns::class,
            'tls' => CheckDomainTls::class,
            default => null,
        };

        if ($jobClass === null) {
            $this->error('Unsupported check type. Use http, dns, or tls.');

            return self::FAILURE;
        }

        $queued = 0;
        Domain::query()
            ->whereNull('removed_at')
            ->where('is_active', true)
            ->where('monitoring_enabled', true)
            ->whereHas('cpanelAccount', fn ($query) => $query
                ->whereNull('removed_at')
                ->whereHas('server', fn ($query) => $query->where('enabled', true)))
            ->select('id')
            ->chunkById(200, function ($domains) use ($jobClass, &$queued): void {
                foreach ($domains as $domain) {
                    $jobClass::dispatch($domain->id);
                    $queued++;
                }
            });

        $this->info('Queued '.$queued.' '.strtoupper($type).' domain '.($queued === 1 ? 'check.' : 'checks.'));

        return self::SUCCESS;
    }
}
