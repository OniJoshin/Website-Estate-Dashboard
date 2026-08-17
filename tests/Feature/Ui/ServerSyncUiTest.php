<?php

namespace Tests\Feature\Ui;

use App\Enums\SyncRunStatus;
use App\Jobs\Inventory\SyncServerInventory;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Server;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Whm\Contracts\WhmClient;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class ServerSyncUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_cannot_view_server_detail(): void
    {
        $server = Server::factory()->create();

        $this->get(route('servers.show', $server))->assertRedirect('/login');
    }

    public function test_staff_and_admin_can_view_local_server_details_and_index_link(): void
    {
        $server = Server::factory()->create([
            'name' => 'London WHM',
            'hostname' => 'whm1.example.invalid',
            'api_token' => 'never-render-this-fixture-token',
            'enabled' => true,
        ]);
        $this->preventWhmCalls();

        foreach ([$this->staff(), $this->admin()] as $user) {
            $this->actingAs($user)
                ->get(route('servers.show', $server))
                ->assertOk()
                ->assertSee('London WHM')
                ->assertSee('whm1.example.invalid')
                ->assertSee('Enabled')
                ->assertDontSee('never-render-this-fixture-token');
        }

        $this->actingAs($this->staff())
            ->get(route('servers.index'))
            ->assertSee(route('servers.show', $server));
    }

    public function test_detail_shows_current_local_estate_counts(): void
    {
        $server = Server::factory()->create();
        $currentAccount = CpanelAccount::factory()->for($server)->create();
        CpanelAccount::factory()->for($server)->removed()->create();
        Domain::factory()->for($currentAccount)->count(2)->create(['monitoring_enabled' => true]);
        Domain::factory()->for($currentAccount)->create(['monitoring_enabled' => false]);
        Domain::factory()->for($currentAccount)->removed()->create(['monitoring_enabled' => true]);

        $this->actingAs($this->staff())
            ->get(route('servers.show', $server))
            ->assertSeeHtml('data-test="current-accounts" data-count="1"')
            ->assertSeeHtml('data-test="current-domains" data-count="3"')
            ->assertSeeHtml('data-test="current-monitored-domains" data-count="2"');
    }

    public function test_recent_sync_history_is_newest_first_complete_and_paginated(): void
    {
        $server = Server::factory()->create(['api_token' => 'history-secret-token']);
        SyncRun::factory()->for($server)->create([
            'status' => SyncRunStatus::Failed,
            'started_at' => now()->subHours(2),
            'accounts_found' => 11,
            'accounts_created' => 3,
            'accounts_updated' => 4,
            'accounts_removed' => 5,
            'domains_found' => 21,
            'domains_created' => 6,
            'domains_updated' => 7,
            'domains_removed' => 8,
            'errors_count' => 2,
            'error_summary' => 'Sanitized fixture failure.',
        ]);
        SyncRun::factory()->for($server)->create(['status' => SyncRunStatus::Partial, 'started_at' => now()->subHour()]);
        SyncRun::factory()->for($server)->create(['status' => SyncRunStatus::Successful, 'started_at' => now()->subMinutes(5)]);
        SyncRun::factory()->for($server)->count(17)->create(['started_at' => now()->subDays(2)]);
        SyncRun::factory()->for($server)->create([
            'started_at' => now()->subDays(3),
            'error_summary' => 'Old hidden history marker',
        ]);

        $response = $this->actingAs($this->staff())->get(route('servers.show', $server));

        $response->assertOk()
            ->assertSeeInOrder(['Successful', 'Partial', 'Failed'])
            ->assertSee('Sanitized fixture failure.')
            ->assertSeeHtml('data-test="accounts-found" data-count="11"')
            ->assertSeeHtml('data-test="domains-removed" data-count="8"')
            ->assertDontSee('Old hidden history marker')
            ->assertDontSee('history-secret-token');
    }

    public function test_detail_displays_all_freshness_states(): void
    {
        $never = Server::factory()->create(['last_successful_sync_at' => null]);
        $current = Server::factory()->create(['last_successful_sync_at' => now()->subHours(25)]);
        $stale = Server::factory()->create(['last_successful_sync_at' => now()->subHours(26)]);
        $syncing = Server::factory()->create(['last_successful_sync_at' => now()->subDays(2)]);
        SyncRun::factory()->for($syncing)->create(['status' => SyncRunStatus::Running, 'completed_at' => null]);

        foreach ([$never->id => 'Never synced', $current->id => 'Current', $stale->id => 'Stale', $syncing->id => 'Syncing'] as $serverId => $expected) {
            $this->actingAs($this->staff())->get(route('servers.show', $serverId))->assertSee($expected);
        }
    }

    public function test_index_eager_loads_latest_runs_and_displays_status_without_per_server_queries(): void
    {
        $servers = Server::factory()->count(4)->create(['last_successful_sync_at' => now()->subHours(2)]);

        foreach ($servers as $server) {
            SyncRun::factory()->for($server)->create(['status' => SyncRunStatus::Successful]);
        }

        $syncRunQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$syncRunQueries): void {
            if (str_contains($query->sql, 'sync_runs')) {
                $syncRunQueries[] = $query->sql;
            }
        });

        $this->actingAs($this->staff())
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Current')
            ->assertSee('Successful');

        $this->assertLessThanOrEqual(2, count($syncRunQueries));
    }

    public function test_staff_cannot_see_or_directly_invoke_manual_sync(): void
    {
        Queue::fake();
        $server = Server::factory()->create();
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('servers.show', $server))->assertDontSee('Sync now');
        Livewire::actingAs($staff)
            ->test('pages::servers.show', ['server' => $server])
            ->call('syncNow')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_admin_can_queue_manual_sync_by_scalar_server_id_without_calling_whm(): void
    {
        Queue::fake();
        $server = Server::factory()->create();
        $this->preventWhmCalls();

        Livewire::actingAs($this->admin())
            ->test('pages::servers.show', ['server' => $server])
            ->assertSee('Sync now')
            ->call('syncNow')
            ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => $parameters['slots']['text'] === 'Inventory sync queued');

        Queue::assertPushed(SyncServerInventory::class, fn (SyncServerInventory $job): bool => $job->serverId === $server->id);
    }

    public function test_running_sync_prevents_duplicate_dispatch_and_reports_it(): void
    {
        Queue::fake();
        $server = Server::factory()->create();
        SyncRun::factory()->for($server)->create(['status' => SyncRunStatus::Running, 'completed_at' => null]);

        Livewire::actingAs($this->admin())
            ->test('pages::servers.show', ['server' => $server])
            ->call('syncNow')
            ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => $parameters['slots']['text'] === 'An inventory sync is already running.');

        Queue::assertNothingPushed();
    }

    public function test_disabled_server_remains_viewable_but_cannot_be_manually_synced(): void
    {
        Queue::fake();
        $server = Server::factory()->create(['enabled' => false]);
        SyncRun::factory()->for($server)->create(['error_summary' => 'Historical sanitized error.']);

        $this->actingAs($this->staff())
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('Disabled')
            ->assertSee('Historical sanitized error.');

        Livewire::actingAs($this->admin())
            ->test('pages::servers.show', ['server' => $server])
            ->call('syncNow')
            ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => $parameters['slots']['text'] === 'Disabled servers cannot be synchronized.');

        Queue::assertNothingPushed();
    }

    private function preventWhmCalls(): void
    {
        $this->mock(WhmClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('listAccounts', 'listDomains', 'getServerHealth', 'getAccountDiskUsage', 'testConnection');
        });
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create();
    }
}
