<?php

namespace App\Console\Commands;

use App\Support\Operations\OperationsStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('estate:record-scheduler-heartbeat')]
#[Description('Record that the Laravel scheduler is running')]
class RecordSchedulerHeartbeat extends Command
{
    public function handle(): int
    {
        Cache::forever(OperationsStatus::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
