<?php

namespace Tests\Feature\Ui;

use App\Enums\DomainClassification;
use App\Enums\IssueSeverity;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class DomainsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_and_current_domains_are_shown_by_default(): void
    {
        $current = Domain::factory()->create(['domain' => 'current.example.invalid']);
        Domain::factory()->removed()->create(['domain' => 'removed.example.invalid']);

        $this->get(route('domains.index'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get(route('domains.index'))
            ->assertOk()->assertSee($current->domain)->assertDontSee('removed.example.invalid');
    }

    public function test_domain_filters_cover_search_server_classification_monitoring_and_removed_state(): void
    {
        $server = Server::factory()->create(['name' => 'London WHM']);
        $other = Server::factory()->create();
        $account = CpanelAccount::factory()->for($server)->create(['username' => 'searchuser', 'primary_domain' => 'primary.invalid']);
        $match = Domain::factory()->for($account)->create([
            'domain' => 'alias.example.invalid',
            'classification' => DomainClassification::Alias,
            'monitoring_enabled' => true,
        ]);
        Domain::factory()->for(CpanelAccount::factory()->for($other))->create(['domain' => 'other.invalid']);
        Domain::factory()->for($account)->removed()->create(['domain' => 'removed.invalid']);

        Livewire::actingAs(User::factory()->create())->test('pages::domains.index')
            ->set('search', 'searchuser')->assertSee($match->domain)->assertDontSee('other.invalid')
            ->set('search', '')->set('serverId', (string) $server->id)->assertSee($match->domain)->assertDontSee('other.invalid')
            ->set('classification', 'alias')->assertSee($match->domain)
            ->set('monitoring', 'monitored')->assertSee($match->domain)->assertSee('Monitored')
            ->set('classification', 'all')->set('monitoring', 'all')->set('estateStatus', 'removed')
            ->assertSee('removed.invalid')->assertDontSee($match->domain);
    }

    public function test_latest_checks_and_worst_active_issue_state_render_without_n_plus_one(): void
    {
        $domains = Domain::factory()->count(5)->create();
        foreach ($domains as $domain) {
            DomainCheck::factory()->for($domain)->create(['check_type' => 'http', 'http_status' => 404, 'response_time_ms' => 284]);
            DomainCheck::factory()->for($domain)->create(['check_type' => 'dns', 'successful' => true]);
            DomainCheck::factory()->for($domain)->create(['check_type' => 'tls', 'successful' => true, 'ssl_valid' => true, 'ssl_days_remaining' => 63]);
        }
        Issue::factory()->forDomain($domains->first())->create(['severity' => IssueSeverity::Warning]);
        Issue::factory()->forDomain($domains->first())->create(['severity' => IssueSeverity::Critical]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs(User::factory()->create())->get(route('domains.index'));

        $response->assertOk()->assertSee('HTTP 404')->assertSee('284 ms')->assertSee('Resolved')->assertSee('Valid')->assertSee('Critical');
        $this->assertLessThan(16, count($queries));
    }

    public function test_no_checks_no_issues_pagination_and_secret_safety(): void
    {
        $server = Server::factory()->create(['api_token' => 'TASK16_SUPER_SECRET_TOKEN']);
        $account = CpanelAccount::factory()->for($server)->create();
        Domain::factory()->count(26)->for($account)->create();

        $this->actingAs(User::factory()->create())->get(route('domains.index'))
            ->assertOk()->assertSee('Not checked yet')->assertSee('No active issues')->assertSee('Next')
            ->assertDontSee('TASK16_SUPER_SECRET_TOKEN');
    }
}
