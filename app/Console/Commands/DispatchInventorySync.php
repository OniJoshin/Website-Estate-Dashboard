<?php

namespace App\Console\Commands;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use App\Jobs\Inventory\SyncServerInventory;
use App\Models\Server;
use Illuminate\Console\Command;

class DispatchInventorySync extends Command
{
    protected $signature = 'estate:dispatch-inventory';

    protected $description = 'Queue inventory synchronization for eligible WHM servers';

    public function handle(): int
    {
        $queued = 0;

        Server::query()
            ->where('enabled', true)
            ->whereDoesntHave('syncRuns', fn ($query) => $query
                ->where('type', SyncRunType::Inventory)
                ->where('status', SyncRunStatus::Running)
                ->whereNull('completed_at'))
            ->select('id')
            ->chunkById(200, function ($servers) use (&$queued): void {
                foreach ($servers as $server) {
                    SyncServerInventory::dispatch($server->id);
                    $queued++;
                }
            });

        $this->info("Queued inventory sync for {$queued} servers.");

        return self::SUCCESS;
    }
}
