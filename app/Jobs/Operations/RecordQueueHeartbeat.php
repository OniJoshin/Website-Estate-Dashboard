<?php

namespace App\Jobs\Operations;

use App\Support\Operations\OperationsStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RecordQueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function handle(): void
    {
        Cache::forever(OperationsStatus::QUEUE_HEARTBEAT_KEY, now()->toIso8601String());
    }

    public function uniqueId(): string
    {
        return 'queue-worker-heartbeat';
    }
}
