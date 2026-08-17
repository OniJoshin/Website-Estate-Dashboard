<?php

namespace App\Console\Commands;

use App\Jobs\Monitoring\CheckServerHealth;
use App\Models\Server;
use Illuminate\Console\Command;

class DispatchServerChecks extends Command
{
    protected $signature = 'estate:dispatch-server-checks';

    protected $description = 'Queue health checks for enabled WHM servers';

    public function handle(): int
    {
        $queued = 0;

        Server::query()->where('enabled', true)->select('id')->chunkById(200, function ($servers) use (&$queued): void {
            foreach ($servers as $server) {
                CheckServerHealth::dispatch($server->id);
                $queued++;
            }
        });

        $this->info("Queued {$queued} server health ".($queued === 1 ? 'check.' : 'checks.'));

        return self::SUCCESS;
    }
}
