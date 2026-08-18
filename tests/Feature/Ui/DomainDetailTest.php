<?php

namespace Tests\Feature\Ui;

use App\Enums\IssueSeverity;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DomainDetailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_can_view_identity_context_active_issues_and_removed_history(): void
    {
        $server = Server::factory()->create(['name' => 'London WHM', 'api_token' => 'TASK16_SUPER_SECRET_TOKEN']);
        $account = CpanelAccount::factory()->for($server)->create(['username' => 'fixtureuser']);
        $domain = Domain::factory()->for($account)->removed()->create([
            'domain' => 'removed.example.invalid',
            'document_root' => '/home/fixture/public_html',
        ]);
        Issue::factory()->forDomain($domain)->create(['severity' => IssueSeverity::Critical, 'title' => 'Domain incident']);
        Issue::factory()->forDomain($domain)->resolved()->create(['title' => 'Old incident']);

        $this->get(route('domains.show', $domain))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get(route('domains.show', $domain))
            ->assertOk()->assertSee('removed.example.invalid')->assertSee('Removed')->assertSee('fixtureuser')->assertSee('London WHM')
            ->assertSee('/home/fixture/public_html')->assertSee('Domain incident')->assertDontSee('Old incident')
            ->assertDontSee('TASK16_SUPER_SECRET_TOKEN');
    }

    public function test_detail_renders_latest_http_dns_tls_and_newest_first_history(): void
    {
        $domain = Domain::factory()->create(['domain' => 'site.example.invalid']);
        DomainCheck::factory()->for($domain)->create([
            'check_type' => 'http', 'checked_at' => now()->subMinutes(3), 'http_status' => 301,
            'response_time_ms' => 2841, 'final_url' => 'https://www.site.example.invalid/', 'redirect_count' => 1,
        ]);
        DomainCheck::factory()->for($domain)->create([
            'check_type' => 'dns', 'checked_at' => now()->subMinutes(2), 'resolved_ips' => [
                'a' => ['192.0.2.1'], 'aaaa' => ['2001:db8::1'], 'cname' => ['origin.example.invalid'],
            ],
        ]);
        DomainCheck::factory()->for($domain)->create([
            'check_type' => 'tls', 'checked_at' => now()->subMinute(), 'ssl_valid' => true,
            'ssl_expires_at' => now()->addDays(63), 'ssl_days_remaining' => 63,
        ]);

        $this->actingAs(User::factory()->create())->get(route('domains.show', $domain))
            ->assertOk()->assertSee('HTTP 301')->assertSee('2,841 ms')->assertSee('https://www.site.example.invalid/')
            ->assertSee('1 redirect')->assertSee('192.0.2.1')->assertSee('2001:db8::1')->assertSee('origin.example.invalid')
            ->assertSee('63 days remaining')->assertSeeInOrder(['TLS', 'DNS', 'HTTP']);
    }

    public function test_failure_and_no_check_states_are_safe_and_descriptive(): void
    {
        $empty = Domain::factory()->create(['domain' => 'empty.invalid']);
        $failed = Domain::factory()->create(['domain' => 'failed.invalid']);
        DomainCheck::factory()->for($failed)->create([
            'check_type' => 'http', 'successful' => false, 'http_status' => null, 'error_type' => 'connection_failed',
            'error_message' => 'Connection failed safely.',
        ]);
        DomainCheck::factory()->for($failed)->create([
            'check_type' => 'dns', 'successful' => false, 'resolved_ips' => ['a' => [], 'aaaa' => [], 'cname' => []],
            'error_type' => 'no_records', 'error_message' => 'No DNS records found.',
        ]);
        DomainCheck::factory()->for($failed)->create([
            'check_type' => 'tls', 'successful' => false, 'ssl_valid' => false, 'ssl_expires_at' => null,
            'ssl_days_remaining' => null, 'error_type' => 'tls_invalid', 'error_message' => 'TLS certificate validation failed.',
        ]);

        $this->actingAs(User::factory()->create())->get(route('domains.show', $empty))->assertSee('Not checked yet')->assertSee('No active issues');
        $this->actingAs(User::factory()->create())->get(route('domains.show', $failed))
            ->assertSee('Connection failed safely.')->assertSee('No DNS records found.')->assertSee('TLS certificate validation failed.')
            ->assertSee('connection_failed')->assertSee('no_records')->assertSee('tls_invalid');
    }

    public function test_tls_history_never_labels_an_invalid_observation_as_valid(): void
    {
        $domain = Domain::factory()->create();
        DomainCheck::factory()->for($domain)->create([
            'check_type' => 'tls',
            'successful' => true,
            'ssl_valid' => false,
            'error_type' => 'tls_invalid',
        ]);

        $this->actingAs(User::factory()->create())->get(route('domains.show', $domain))
            ->assertSee('Invalid / Failed')
            ->assertDontSee('Valid · expires');
    }
}
