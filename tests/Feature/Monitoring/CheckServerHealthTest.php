<?php

namespace Tests\Feature\Monitoring;

use App\Data\Whm\WhmServerHealthData;
use App\Jobs\Monitoring\CheckServerHealth;
use App\Models\Issue;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CheckServerHealthTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Server $server;

    private MockInterface&WhmClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->server = Server::factory()->create([
            'enabled' => true,
            'api_token' => 'TASK13_SUPER_SECRET_TOKEN',
        ]);
        $this->client = $this->mock(WhmClient::class);
    }

    public function test_enabled_server_appends_complete_normalized_health_observation(): void
    {
        $health = $this->healthData();
        $this->expectOnlyHealthCallReturning($health);

        $this->runJob();

        $check = ServerCheck::query()->sole();
        $this->assertTrue($check->server->is($this->server));
        $this->assertTrue($check->reachable);
        $this->assertSame(12.34, (float) $check->load_1m);
        $this->assertSame(23.45, (float) $check->load_5m);
        $this->assertSame(34.56, (float) $check->load_15m);
        $this->assertEquals($health->partitions, $check->partitions);
        $this->assertIsNumeric($check->partitions[0]['used_percent']);
        $this->assertIsNumeric($check->partitions[1]['used_percent']);
        $this->assertNull($check->error_message);
        $this->assertTrue($check->checked_at->isCurrentSecond());
        $this->assertSame(0, Issue::query()->count());
        Http::assertNothingSent();
    }

    public function test_high_load_and_disk_values_are_observations_not_issues(): void
    {
        $health = new WhmServerHealthData(
            load1: 99.75,
            load5: 88.5,
            load15: 77.25,
            partitions: [[
                'filesystem' => '/dev/vda1',
                'mount' => '/',
                'total' => 1_000_000.0,
                'used' => 960_000.0,
                'available' => 40_000.0,
                'used_percent' => 96.0,
            ]],
        );
        $this->expectOnlyHealthCallReturning($health);

        $this->runJob();

        $check = ServerCheck::query()->sole();
        $this->assertSame(99.75, (float) $check->load_1m);
        $this->assertSame(96.0, (float) $check->partitions[0]['used_percent']);
        $this->assertSame(0, Issue::query()->count());
    }

    public function test_repeated_executions_append_immutable_checks(): void
    {
        $this->client->shouldReceive('getServerHealth')
            ->twice()
            ->withArgs(fn (Server $server): bool => $server->is($this->server))
            ->andReturn(
                $this->healthData(),
                new WhmServerHealthData(1.0, 2.0, 3.0, []),
            );

        $this->runJob();
        $firstCheck = ServerCheck::query()->sole();
        $this->runJob();

        $this->assertSame(2, ServerCheck::query()->count());
        $this->assertSame(12.34, (float) $firstCheck->fresh()->load_1m);
        $this->assertSame([12.34, 1.0], ServerCheck::query()->oldest('id')->pluck('load_1m')->map(fn (mixed $load): float => (float) $load)->all());
    }

    public function test_missing_server_is_skipped_without_client_call(): void
    {
        $this->client->shouldNotReceive('getServerHealth');

        $this->runJob(new CheckServerHealth(999_999));

        $this->assertSame(0, ServerCheck::query()->count());
    }

    public function test_disabled_server_is_skipped_without_client_call(): void
    {
        $this->server->update(['enabled' => false]);
        $this->client->shouldNotReceive('getServerHealth');

        $this->runJob();

        $this->assertSame(0, ServerCheck::query()->count());
    }

    public function test_expected_whm_failure_appends_sanitized_unreachable_observation_and_returns_normally(): void
    {
        $this->client->shouldReceive('getServerHealth')
            ->once()
            ->andThrow(new WhmApiException('connection_failure', [
                'server_id' => $this->server->id,
                'server_hostname' => $this->server->hostname,
                'function' => 'systemloadavg/getdiskusage',
            ]));

        $this->runJob();

        $check = ServerCheck::query()->sole();
        $this->assertFalse($check->reachable);
        $this->assertNull($check->load_1m);
        $this->assertNull($check->load_5m);
        $this->assertNull($check->load_15m);
        $this->assertNull($check->partitions);
        $this->assertSame('WHM server health check failed.', $check->error_message);
        $this->assertStringNotContainsString('TASK13_SUPER_SECRET_TOKEN', serialize($check->toArray()));
        $this->assertSame(0, Issue::query()->count());
    }

    public function test_unexpected_runtime_exception_escapes_without_observation(): void
    {
        $this->client->shouldReceive('getServerHealth')
            ->once()
            ->andThrow(new RuntimeException('Unexpected fixture failure.'));
        $this->expectException(RuntimeException::class);

        try {
            $this->runJob();
        } finally {
            $this->assertSame(0, ServerCheck::query()->count());
        }
    }

    public function test_job_serializes_only_scalar_server_id_without_credentials_or_dto(): void
    {
        $job = new CheckServerHealth($this->server->id);
        $serialized = serialize($job);

        $this->assertSame($this->server->id, $job->serverId);
        $this->assertObjectNotHasProperty('server', $job);
        $this->assertObjectNotHasProperty('health', $job);
        $this->assertStringNotContainsString('TASK13_SUPER_SECRET_TOKEN', $serialized);
        $this->assertStringNotContainsString($this->server->hostname, $serialized);
        $this->assertStringNotContainsString($this->server->api_username, $serialized);
    }

    private function expectOnlyHealthCallReturning(WhmServerHealthData $health): void
    {
        $this->client->shouldReceive('getServerHealth')
            ->once()
            ->withArgs(fn (Server $server): bool => $server->is($this->server))
            ->andReturn($health);
        $this->client->shouldNotReceive('listAccounts');
        $this->client->shouldNotReceive('getAccountDiskUsage');
        $this->client->shouldNotReceive('testConnection');
        $this->client->shouldNotReceive('listDomains');
    }

    private function healthData(): WhmServerHealthData
    {
        return new WhmServerHealthData(
            load1: 12.34,
            load5: 23.45,
            load15: 34.56,
            partitions: [
                [
                    'filesystem' => '/dev/vda1',
                    'mount' => '/',
                    'total' => 1_000_000.0,
                    'used' => 670_000.0,
                    'available' => 330_000.0,
                    'used_percent' => 67.0,
                ],
                [
                    'filesystem' => '/dev/vdb1',
                    'mount' => '/home',
                    'total' => 2_000_000.0,
                    'used' => 1_820_000.0,
                    'available' => 180_000.0,
                    'used_percent' => 91.0,
                ],
            ],
        );
    }

    private function runJob(?CheckServerHealth $job = null): void
    {
        $this->app->call([$job ?? new CheckServerHealth($this->server->id), 'handle']);
    }
}
