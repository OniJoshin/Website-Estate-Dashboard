<?php

namespace App\Jobs\Inventory;

use App\Models\CpanelAccount;
use App\Models\SyncRun;
use App\Services\Inventory\InventoryReconciler;
use App\Services\Inventory\SyncRunRecorder;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCpanelAccountDomains implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public function __construct(
        public int $cpanelAccountId,
        public int $syncRunId,
    ) {}

    public function handle(
        WhmClient $whm,
        InventoryReconciler $reconciler,
        SyncRunRecorder $recorder,
    ): void {
        $account = CpanelAccount::with('server')->find($this->cpanelAccountId);
        $runExists = SyncRun::whereKey($this->syncRunId)->exists();

        if ($account === null || $account->removed_at !== null || ! $runExists || ! $account->server->enabled) {
            return;
        }

        try {
            $inventory = $whm->listDomains($account->server, $account->username);
        } catch (WhmApiException) {
            $recorder->recordError(
                $this->syncRunId,
                "Domain inventory failed for cPanel account {$account->username}.",
            );

            return;
        }

        $outcome = $reconciler->reconcileDomains($account, $inventory);
        $recorder->increment($this->syncRunId, [
            'domains_found' => $outcome->found,
            'domains_created' => $outcome->created,
            'domains_updated' => $outcome->updated,
            'domains_removed' => $outcome->removed,
        ]);
    }
}
