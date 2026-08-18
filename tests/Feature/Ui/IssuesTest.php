<?php

namespace Tests\Feature\Ui;

use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Issue;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IssuesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_redirected_and_staff_can_view_active_issues_by_default(): void
    {
        $active = Issue::factory()->create(['title' => 'Active issue marker']);
        Issue::factory()->resolved()->create(['title' => 'Resolved issue marker']);

        $this->get(route('issues.index'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get(route('issues.index'))
            ->assertOk()->assertSee($active->title)->assertDontSee('Resolved issue marker');
    }

    public function test_status_severity_and_type_filters_work(): void
    {
        $server = Server::factory()->create();
        Issue::factory()->forServer($server)->create([
            'title' => 'Critical HTTP marker',
            'severity' => IssueSeverity::Critical,
            'type' => IssueType::HttpUnavailable,
        ]);
        Issue::factory()->forServer($server)->resolved()->create([
            'title' => 'Resolved warning marker',
            'severity' => IssueSeverity::Warning,
            'type' => IssueType::DiskUsage,
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test('pages::issues.index')
            ->set('status', 'resolved')->assertSee('Resolved warning marker')->assertDontSee('Critical HTTP marker')
            ->set('status', 'all')->assertSee('Resolved warning marker')->assertSee('Critical HTTP marker')
            ->set('severity', 'critical')->assertSee('Critical HTTP marker')->assertDontSee('Resolved warning marker')
            ->set('severity', 'all')->set('type', IssueType::DiskUsage->value)
            ->assertSee('Resolved warning marker')->assertDontSee('Critical HTTP marker');
    }

    public function test_server_filter_includes_each_target_level_and_labels_are_human_readable(): void
    {
        $server = Server::factory()->create(['name' => 'London WHM', 'hostname' => 'whm1.example.invalid']);
        $other = Server::factory()->create();
        $account = CpanelAccount::factory()->for($server)->create(['username' => 'fixtureuser', 'primary_domain' => 'primary.invalid']);
        $domain = Domain::factory()->for($account)->create(['domain' => 'site.example.invalid']);
        Issue::factory()->forServer($server)->create(['title' => 'Direct server marker']);
        Issue::factory()->forAccount($account)->create(['title' => 'Account marker']);
        Issue::factory()->forDomain($domain)->create(['title' => 'Domain marker']);
        Issue::factory()->forServer($other)->create(['title' => 'Other marker']);

        Livewire::actingAs(User::factory()->create())
            ->test('pages::issues.index')
            ->set('serverId', (string) $server->id)
            ->assertSee('Direct server marker')->assertSee('Account marker')->assertSee('Domain marker')->assertDontSee('Other marker')
            ->assertSee('London WHM')->assertSee('fixtureuser')->assertSee('primary.invalid')->assertSee('site.example.invalid');
    }

    public function test_resolved_state_pagination_and_filter_reset_are_available_without_secrets(): void
    {
        $server = Server::factory()->create(['api_token' => 'TASK16_SUPER_SECRET_TOKEN']);
        Issue::factory()->count(26)->forServer($server)->create();
        Issue::factory()->forServer($server)->resolved()->create([
            'title' => 'Historical issue',
            'resolved_at' => now()->subMinute(),
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test('pages::issues.index')
            ->assertSee('Next')
            ->set('status', 'resolved')
            ->assertSet('paginators.page', 1)
            ->assertSee('Historical issue')->assertSee('Resolved')
            ->assertDontSee('TASK16_SUPER_SECRET_TOKEN');
    }
}
