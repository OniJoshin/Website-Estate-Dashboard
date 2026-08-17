<?php

namespace Tests\Feature\Scheduling;

use App\Jobs\Monitoring\CheckServerHealth;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Services\Whm\Contracts\WhmClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchServerChecksTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_queues_only_enabled_servers_without_running_checks(): void
    {
        Queue::fake();
        $this->mock(WhmClient::class)->shouldNotReceive('getServerHealth');
        $enabled = Server::factory()->create(['enabled' => true]);
        Server::factory()->create(['enabled' => false]);

        $this->artisan('estate:dispatch-server-checks')
            ->expectsOutput('Queued 1 server health check.')
            ->assertSuccessful();

        Queue::assertPushed(CheckServerHealth::class, fn (CheckServerHealth $job) => $job->serverId === $enabled->id);
        Queue::assertPushed(CheckServerHealth::class, 1);
        $this->assertSame(0, ServerCheck::count());
    }

    public function test_zero_enabled_servers_succeeds(): void
    {
        Queue::fake();

        $this->artisan('estate:dispatch-server-checks')
            ->expectsOutput('Queued 0 server health checks.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
