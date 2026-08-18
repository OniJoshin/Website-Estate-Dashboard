<?php

namespace Tests\Feature\Ui;

use App\Enums\IssueSeverity;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Issue;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\User;
use App\Services\Monitoring\Contracts\DnsResolver;
use App\Services\Monitoring\Contracts\TlsInspector;
use App\Services\Whm\Contracts\WhmClient;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_and_staff_and_admin_can_view_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect('/login');

        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Estate overview');
        }
    }

    public function test_dashboard_shows_current_totals_ordered_issues_and_server_state(): void
    {
        $server = Server::factory()->create([
            'name' => 'London WHM',
            'hostname' => 'whm1.example.invalid',
            'enabled' => true,
            'last_successful_sync_at' => now()->subHour(),
            'api_token' => 'TASK16_SUPER_SECRET_TOKEN',
        ]);
        Server::factory()->create(['enabled' => false]);
        $account = CpanelAccount::factory()->for($server)->create();
        CpanelAccount::factory()->for($server)->removed()->create();
        $domain = Domain::factory()->for($account)->create(['domain' => 'site.example.invalid']);
        Domain::factory()->for($account)->create(['monitoring_enabled' => false]);
        Domain::factory()->for($account)->removed()->create();
        ServerCheck::factory()->for($server)->create(['load_1m' => 1.25]);
        Issue::factory()->forDomain($domain)->create([
            'severity' => IssueSeverity::Warning,
            'title' => 'Warning marker',
            'last_detected_at' => now(),
        ]);
        Issue::factory()->forServer($server)->create([
            'severity' => IssueSeverity::Critical,
            'title' => 'Critical marker',
            'last_detected_at' => now()->subMinute(),
        ]);
        Issue::factory()->forServer($server)->resolved()->create(['severity' => IssueSeverity::Critical]);
        $this->preventRemoteCalls();

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeHtml('data-test="enabled-servers" data-count="1"')
            ->assertSeeHtml('data-test="current-accounts" data-count="1"')
            ->assertSeeHtml('data-test="current-domains" data-count="2"')
            ->assertSeeHtml('data-test="monitored-domains" data-count="1"')
            ->assertSeeHtml('data-test="critical-issues" data-count="1"')
            ->assertSeeHtml('data-test="warning-issues" data-count="1"')
            ->assertSeeInOrder(['Critical marker', 'Warning marker'])
            ->assertSee('site.example.invalid')
            ->assertSee('Current')
            ->assertSee('Reachable')
            ->assertSee('1.25')
            ->assertDontSee('TASK16_SUPER_SECRET_TOKEN');
    }

    public function test_dashboard_has_descriptive_empty_and_unchecked_states(): void
    {
        Server::factory()->create(['name' => 'Unchecked WHM', 'last_successful_sync_at' => null]);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No active issues')
            ->assertSee('Never synced')
            ->assertSee('Not checked yet')
            ->assertSeeHtml('scope="col"');
    }

    public function test_dashboard_server_query_count_remains_stable_as_servers_grow(): void
    {
        Server::factory()->count(6)->create();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();

        $this->assertLessThan(20, count($queries));
    }

    private function preventRemoteCalls(): void
    {
        $this->mock(WhmClient::class)->shouldNotReceive('getServerHealth', 'listAccounts', 'listDomains', 'getAccountDiskUsage', 'testConnection');
        $this->mock(DnsResolver::class)->shouldNotReceive('resolve');
        $this->mock(TlsInspector::class)->shouldNotReceive('inspect');
    }
}
