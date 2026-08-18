<?php

namespace Tests\Feature\Ui;

use App\Enums\IssueSeverity;
use App\Models\Issue;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ServerMonitoringUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_latest_health_load_partitions_active_issues_and_history_render(): void
    {
        $server = Server::factory()->create(['api_token' => 'TASK16_SUPER_SECRET_TOKEN']);
        ServerCheck::factory()->for($server)->create(['checked_at' => now()->subHour(), 'reachable' => false]);
        ServerCheck::factory()->for($server)->create([
            'checked_at' => now(), 'reachable' => true, 'load_1m' => 1.1, 'load_5m' => 2.2, 'load_15m' => 3.3,
            'partitions' => [[
                'filesystem' => '/dev/vda1', 'mount' => '/home', 'total' => 1000,
                'used' => 910, 'available' => 90, 'used_percent' => 91,
            ]],
        ]);
        Issue::factory()->forServer($server)->create(['severity' => IssueSeverity::Critical, 'title' => 'Disk incident']);
        Issue::factory()->forServer($server)->resolved()->create(['title' => 'Resolved incident']);

        $this->actingAs(User::factory()->create())->get(route('servers.show', $server))
            ->assertOk()->assertSee('Latest server health')->assertSee('Reachable')->assertSee('1.1')->assertSee('2.2')->assertSee('3.3')
            ->assertSee('/dev/vda1')->assertSee('/home')->assertSee('91%')->assertSee('Disk incident')->assertDontSee('Resolved incident')
            ->assertSeeInOrder(['Reachable', 'WHM health check failed'])->assertDontSee('TASK16_SUPER_SECRET_TOKEN');
    }

    public function test_failed_and_unchecked_health_wording_is_accurate(): void
    {
        $unchecked = Server::factory()->create();
        $failed = Server::factory()->create();
        ServerCheck::factory()->for($failed)->create(['reachable' => false, 'error_message' => 'WHM server health check failed.']);

        $this->actingAs(User::factory()->create())->get(route('servers.show', $unchecked))->assertSee('Not checked yet');
        $this->actingAs(User::factory()->create())->get(route('servers.show', $failed))
            ->assertSee('WHM health check failed')->assertDontSee('Physical server offline');
    }
}
