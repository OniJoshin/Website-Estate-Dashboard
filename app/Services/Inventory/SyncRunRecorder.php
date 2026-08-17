<?php

namespace App\Services\Inventory;

use App\Enums\SyncRunStatus;
use App\Models\Server;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SyncRunRecorder
{
    /** @param array<string, int> $counters */
    public function increment(int $syncRunId, array $counters): void
    {
        if ($counters !== []) {
            SyncRun::whereKey($syncRunId)->incrementEach($counters);
        }
    }

    public function recordError(int $syncRunId, string $summary): void
    {
        DB::transaction(function () use ($syncRunId, $summary): void {
            $run = SyncRun::query()->lockForUpdate()->find($syncRunId);

            if ($run === null) {
                return;
            }

            $safeSummary = Str::limit(Str::squish($summary), 1_000, '');
            $messages = array_filter([$run->error_summary, $safeSummary]);
            $run->forceFill([
                'errors_count' => $run->errors_count + 1,
                'error_summary' => Str::limit(implode(PHP_EOL, $messages), 60_000, ''),
            ])->save();
        });
    }

    public function finalize(int $syncRunId, bool $unexpectedBatchFailure = false): void
    {
        DB::transaction(function () use ($syncRunId, $unexpectedBatchFailure): void {
            $run = SyncRun::query()->lockForUpdate()->find($syncRunId);

            if ($run === null || $run->completed_at !== null) {
                return;
            }

            if ($unexpectedBatchFailure) {
                $run->errors_count++;
                $run->error_summary = Str::limit(implode(PHP_EOL, array_filter([
                    $run->error_summary,
                    'One or more account domain jobs failed unexpectedly.',
                ])), 60_000, '');
            }

            $status = $run->errors_count === 0 ? SyncRunStatus::Successful : SyncRunStatus::Partial;
            $completedAt = now();
            $run->forceFill(['status' => $status, 'completed_at' => $completedAt])->save();

            $serverValues = ['last_synced_at' => $completedAt];
            if ($status === SyncRunStatus::Successful) {
                $serverValues['last_successful_sync_at'] = $completedAt;
            }
            Server::whereKey($run->server_id)->update($serverValues);
        });
    }

    public function fail(int $syncRunId, string $summary): void
    {
        $this->recordError($syncRunId, $summary);
        $completedAt = now();
        $run = SyncRun::find($syncRunId);

        if ($run === null) {
            return;
        }

        $run->update(['status' => SyncRunStatus::Failed, 'completed_at' => $completedAt]);
        Server::whereKey($run->server_id)->update(['last_synced_at' => $completedAt]);
    }
}
