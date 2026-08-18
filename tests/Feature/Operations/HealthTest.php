<?php

namespace Tests\Feature\Operations;

use App\Services\Monitoring\Contracts\DnsResolver;
use App\Services\Monitoring\Contracts\TlsInspector;
use App\Services\Whm\Contracts\WhmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_up_checks_database_and_cache_without_remote_services_or_secrets(): void
    {
        $this->mock(WhmClient::class)->shouldNotReceive('getServerHealth');
        $this->mock(DnsResolver::class)->shouldNotReceive('resolve');
        $this->mock(TlsInspector::class)->shouldNotReceive('inspect');

        $response = $this->getJson('/up');

        $response->assertOk()->assertExactJson(['status' => 'up']);
        $this->assertStringNotContainsString('TASK17_SUPER_SECRET_TOKEN', $response->getContent());
    }

    public function test_database_failure_makes_up_fail_without_exposing_connection_details(): void
    {
        config()->set('app.debug', false);
        config()->set('database.default', 'missing-health-connection');

        $response = $this->getJson('/up');

        $response->assertStatus(500)->assertExactJson(['status' => 'down']);
    }

    public function test_cache_failure_makes_up_fail(): void
    {
        config()->set('app.debug', false);
        Exceptions::fake();
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('DB_PASSWORD=TASK17_SUPER_SECRET_TOKEN'));
        Cache::shouldReceive('forget')->once();

        $response = $this->getJson('/up');

        $response->assertStatus(500)->assertExactJson(['status' => 'down']);
        $this->assertStringNotContainsString('TASK17_SUPER_SECRET_TOKEN', $response->getContent());
        Exceptions::assertNothingReported();
    }

    public function test_cache_probe_round_trips_and_cleans_up_its_namespaced_key(): void
    {
        Cache::shouldReceive('put')->once()->withArgs(fn (string $key, string $value): bool => str_starts_with($key, 'estate:health:cache:') && $value === 'ok');
        Cache::shouldReceive('get')->once()->andReturn('ok');
        Cache::shouldReceive('forget')->once()->withArgs(fn (string $key): bool => str_starts_with($key, 'estate:health:cache:'));

        $this->getJson('/up')->assertOk();
    }
}
