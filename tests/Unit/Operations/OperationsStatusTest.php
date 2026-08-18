<?php

namespace Tests\Unit\Operations;

use App\Support\Operations\OperationsStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OperationsStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_heartbeats_are_classified_independently(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 12:00:00');
        config()->set('estate.operations.heartbeat_stale_minutes', 3);
        Cache::forget(OperationsStatus::SCHEDULER_HEARTBEAT_KEY);
        Cache::put(OperationsStatus::QUEUE_HEARTBEAT_KEY, now()->subMinute()->toIso8601String());

        $status = new OperationsStatus;

        $this->assertSame('Never seen', $status->schedulerHeartbeat()->state);
        $this->assertSame('Current', $status->queueHeartbeat()->state);
    }

    public function test_heartbeat_at_threshold_boundary_is_stale(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 12:00:00');
        config()->set('estate.operations.heartbeat_stale_minutes', 3);
        Cache::put(OperationsStatus::SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(3)->toIso8601String());

        $heartbeat = (new OperationsStatus)->schedulerHeartbeat();

        $this->assertSame('Stale', $heartbeat->state);
        $this->assertTrue($heartbeat->recordedAt?->equalTo(now()->subMinutes(3)));
    }

    public function test_recent_and_old_heartbeats_have_current_and_stale_states(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 12:00:00');
        config()->set('estate.operations.heartbeat_stale_minutes', 3);
        Cache::put(OperationsStatus::SCHEDULER_HEARTBEAT_KEY, now()->subSeconds(30)->toIso8601String());
        Cache::put(OperationsStatus::QUEUE_HEARTBEAT_KEY, now()->subMinutes(4)->toIso8601String());

        $status = new OperationsStatus;

        $this->assertSame('Current', $status->schedulerHeartbeat()->state);
        $this->assertSame('Stale', $status->queueHeartbeat()->state);
    }

    public function test_heartbeat_threshold_must_be_positive(): void
    {
        config()->set('estate.operations.heartbeat_stale_minutes', 0);

        $this->expectException(\InvalidArgumentException::class);

        new OperationsStatus;
    }

    public function test_application_information_reports_database_driver_not_connection_configuration(): void
    {
        config()->set('database.connections.diagnostics', config('database.connections.sqlite'));
        config()->set('database.default', 'diagnostics');

        $information = (new OperationsStatus)->applicationInformation();

        $this->assertSame('sqlite', $information['database']);
        $this->assertArrayNotHasKey('host', $information);
        $this->assertArrayNotHasKey('password', $information);
    }
}
