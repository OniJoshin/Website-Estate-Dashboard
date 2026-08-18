<?php

namespace Tests\Unit\Ui;

use App\Enums\IssueType;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Server;
use App\Models\ServerCheck;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MonitoringPresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_issue_types_have_readable_labels(): void
    {
        $this->assertSame('HTTP unavailable', IssueType::HttpUnavailable->label());
        $this->assertSame('Account suspended', IssueType::AccountSuspended->label());
    }

    public function test_domain_latest_check_relationships_are_scoped_by_type(): void
    {
        $domain = Domain::factory()->create();
        $oldHttp = DomainCheck::factory()->for($domain)->create(['check_type' => 'http', 'checked_at' => now()->subHour()]);
        $newHttp = DomainCheck::factory()->for($domain)->create(['check_type' => 'http', 'checked_at' => now()]);
        $dns = DomainCheck::factory()->for($domain)->create(['check_type' => 'dns']);
        $tls = DomainCheck::factory()->for($domain)->create(['check_type' => 'tls']);

        $this->assertTrue($domain->latestHttpCheck->is($newHttp));
        $this->assertFalse($domain->latestHttpCheck->is($oldHttp));
        $this->assertTrue($domain->latestDnsCheck->is($dns));
        $this->assertTrue($domain->latestTlsCheck->is($tls));
    }

    public function test_server_latest_health_relationship_returns_newest_observation(): void
    {
        $server = Server::factory()->create();
        ServerCheck::factory()->for($server)->create(['checked_at' => now()->subHour()]);
        $latest = ServerCheck::factory()->for($server)->create(['checked_at' => now()]);

        $this->assertTrue($server->latestServerCheck->is($latest));
    }
}
