<?php

namespace Tests\Feature\Operations;

use App\Jobs\Operations\RecordQueueHeartbeat;
use App\Support\Operations\OperationsStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_scheduler_command_and_queue_job_record_current_timestamps(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 12:00:00');

        $this->artisan('estate:record-scheduler-heartbeat')->assertSuccessful();
        (new RecordQueueHeartbeat)->handle();

        $this->assertSame(now()->toIso8601String(), Cache::get(OperationsStatus::SCHEDULER_HEARTBEAT_KEY));
        $this->assertSame(now()->toIso8601String(), Cache::get(OperationsStatus::QUEUE_HEARTBEAT_KEY));
    }

    public function test_queue_heartbeat_is_unique_and_contains_no_models_or_secrets(): void
    {
        $job = new RecordQueueHeartbeat;
        $serialized = serialize($job);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('queue-worker-heartbeat', $job->uniqueId());
        $this->assertSame(300, $job->uniqueFor);
        $this->assertStringNotContainsString('TASK17_SUPER_SECRET_TOKEN', $serialized);
        $this->assertStringNotContainsString('App\\Models\\', $serialized);
    }

    public function test_heartbeat_events_run_every_minute_without_overlap_or_one_server(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        foreach (['estate:record-scheduler-heartbeat', RecordQueueHeartbeat::class] as $needle) {
            $event = $events->first(fn (Event $event) => str_contains($event->getSummaryForDisplay(), $needle));
            $this->assertNotNull($event);
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertFalse($event->onOneServer);
        }
    }
}
