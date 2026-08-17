<?php

namespace Tests\Feature\Scheduling;

use App\Enums\SyncRunStatus;
use App\Jobs\Inventory\SyncServerInventory;
use App\Models\Server;
use App\Models\SyncRun;
use App\Services\Whm\Contracts\WhmClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchInventorySyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_queues_enabled_servers_except_those_with_a_running_inventory_sync(): void
    {
        Queue::fake();
        $this->mock(WhmClient::class)->shouldNotReceive('listAccounts');
        $eligible = Server::factory()->create(['enabled' => true]);
        $running = Server::factory()->create(['enabled' => true]);
        $completed = Server::factory()->create(['enabled' => true]);
        Server::factory()->create(['enabled' => false]);
        SyncRun::factory()->for($running)->create(['status' => SyncRunStatus::Running, 'completed_at' => null]);
        SyncRun::factory()->for($completed)->create();

        $this->artisan('estate:dispatch-inventory')->assertSuccessful();

        Queue::assertPushed(SyncServerInventory::class, fn (SyncServerInventory $job) => $job->serverId === $eligible->id);
        Queue::assertPushed(SyncServerInventory::class, fn (SyncServerInventory $job) => $job->serverId === $completed->id);
        Queue::assertPushed(SyncServerInventory::class, 2);
    }

    public function test_zero_enabled_servers_succeeds(): void
    {
        Queue::fake();

        $this->artisan('estate:dispatch-inventory')
            ->expectsOutput('Queued inventory sync for 0 servers.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
