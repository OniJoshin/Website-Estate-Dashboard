<?php

namespace App\Jobs\Inventory;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use App\Models\Server;
use App\Models\SyncRun;
use App\Services\Inventory\InventoryReconciler;
use App\Services\Inventory\SyncRunRecorder;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncServerInventory implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $serverId) {}

    public function handle(
        WhmClient $whm,
        InventoryReconciler $reconciler,
        SyncRunRecorder $recorder,
    ): void {
        $server = Server::find($this->serverId);

        if ($server === null || ! $server->enabled) {
            return;
        }

        $syncRun = DB::transaction(function () use ($server): ?SyncRun {
            Server::query()->lockForUpdate()->findOrFail($server->id);
            $alreadyRunning = SyncRun::whereBelongsTo($server)
                ->where('type', SyncRunType::Inventory)
                ->where('status', SyncRunStatus::Running)
                ->whereNull('completed_at')
                ->exists();

            if ($alreadyRunning) {
                return null;
            }

            return SyncRun::create([
                'server_id' => $server->id,
                'type' => SyncRunType::Inventory,
                'status' => SyncRunStatus::Running,
                'started_at' => now(),
            ]);
        });

        if ($syncRun === null) {
            return;
        }

        try {
            $accounts = $whm->listAccounts($server);
        } catch (WhmApiException) {
            $recorder->fail($syncRun->id, 'Authoritative account inventory failed.');

            return;
        } catch (Throwable $exception) {
            $recorder->fail($syncRun->id, 'Unexpected failure before account inventory was established.');

            throw $exception;
        }

        $diskUsage = [];
        try {
            $diskUsage = $whm->getAccountDiskUsage($server);
        } catch (WhmApiException) {
            $recorder->recordError($syncRun->id, 'Account disk usage enrichment failed.');
        }

        try {
            $outcome = $reconciler->reconcileAccounts($server, $accounts, $diskUsage);
            $recorder->increment($syncRun->id, [
                'accounts_found' => $outcome->found,
                'accounts_created' => $outcome->created,
                'accounts_updated' => $outcome->updated,
                'accounts_removed' => $outcome->removed,
                'domains_removed' => $outcome->relatedRemoved,
            ]);

            if ($outcome->currentIds === []) {
                $recorder->finalize($syncRun->id);

                return;
            }

            $jobs = array_map(
                fn (int $accountId): SyncCpanelAccountDomains => new SyncCpanelAccountDomains($accountId, $syncRun->id),
                $outcome->currentIds,
            );
            $syncRunId = $syncRun->id;
            $batch = Bus::batch($jobs)
                ->name('Server inventory '.$server->id)
                ->allowFailures()
                ->finally(static function (Batch $batch) use ($syncRunId): void {
                    app(SyncRunRecorder::class)->finalize($syncRunId, $batch->failedJobs > 0);
                })
                ->dispatch();
            $syncRun->update(['batch_id' => $batch->id]);
        } catch (Throwable $exception) {
            $recorder->fail($syncRun->id, 'Unexpected inventory reconciliation failure.');

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = SyncRun::where('server_id', $this->serverId)
            ->where('type', SyncRunType::Inventory)
            ->where('status', SyncRunStatus::Running)
            ->latest('id')
            ->first();

        if ($run !== null) {
            app(SyncRunRecorder::class)->fail($run->id, 'Inventory job failed unexpectedly.');
        }
    }
}
