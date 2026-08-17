<?php

namespace Tests\Feature\Database;

use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\ServerCheck;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MonitoringRetentionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_monitoring_checks_older_than_retention_are_mass_pruned_at_an_exclusive_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 UTC');
        $oldDomain = DomainCheck::factory()->create(['checked_at' => now()->subDays(91)]);
        $boundaryDomain = DomainCheck::factory()->create(['checked_at' => now()->subDays(90)]);
        $newDomain = DomainCheck::factory()->create(['checked_at' => now()->subDays(89)]);
        $oldServer = ServerCheck::factory()->create(['checked_at' => now()->subDays(91)]);
        $boundaryServer = ServerCheck::factory()->create(['checked_at' => now()->subDays(90)]);
        $newServer = ServerCheck::factory()->create(['checked_at' => now()->subDays(89)]);
        $issue = Issue::factory()->create(['created_at' => now()->subDays(91)]);
        $syncRun = SyncRun::factory()->create(['started_at' => now()->subDays(91), 'completed_at' => now()->subDays(91)]);

        $this->artisan('model:prune', [
            '--model' => [DomainCheck::class, ServerCheck::class],
        ])->assertSuccessful();

        $this->assertModelMissing($oldDomain);
        $this->assertModelMissing($oldServer);
        $this->assertModelExists($boundaryDomain);
        $this->assertModelExists($newDomain);
        $this->assertModelExists($boundaryServer);
        $this->assertModelExists($newServer);
        $this->assertModelExists($issue);
        $this->assertModelExists($syncRun);
    }

    public function test_prunable_queries_use_checked_at_and_configured_retention(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 UTC');
        config()->set('estate.retention.check_days', 30);
        $domain = DomainCheck::factory()->create(['checked_at' => now()->subDays(31), 'created_at' => now()]);
        $server = ServerCheck::factory()->create(['checked_at' => now()->subDays(31), 'created_at' => now()]);

        $this->assertTrue((new DomainCheck)->prunable()->whereKey($domain)->exists());
        $this->assertTrue((new ServerCheck)->prunable()->whereKey($server)->exists());
    }
}
