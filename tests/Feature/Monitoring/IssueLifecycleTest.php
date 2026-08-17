<?php

namespace Tests\Feature\Monitoring;

use App\Data\Monitoring\DnsResult;
use App\Data\Monitoring\TlsResult;
use App\Data\Whm\WhmAccountData;
use App\Enums\IssueType;
use App\Jobs\Inventory\SyncServerInventory;
use App\Jobs\Monitoring\CheckDomainDns;
use App\Jobs\Monitoring\CheckDomainHttp;
use App\Jobs\Monitoring\CheckDomainTls;
use App\Jobs\Monitoring\CheckServerHealth;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\SyncRun;
use App\Services\Inventory\SyncRunRecorder;
use App\Services\Monitoring\Contracts\DnsResolver;
use App\Services\Monitoring\Contracts\TlsInspector;
use App\Services\Monitoring\IssueEvaluator;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fakes\FakeWhmClient;
use Tests\TestCase;

class IssueLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Server $server;

    private CpanelAccount $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::factory()->create(['api_token' => 'TASK14_SUPER_SECRET_TOKEN']);
        $this->account = CpanelAccount::factory()->for($this->server)->create();
        $this->domain = Domain::factory()->for($this->account)->create(['domain' => 'example.invalid']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_http_observation_and_issue_evaluation_are_integrated(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::failedConnection('Authorization: whm root:TASK14_SUPER_SECRET_TOKEN')]);

        $this->runJob(new CheckDomainHttp($this->domain->id));
        $this->runJob(new CheckDomainHttp($this->domain->id));

        $issue = Issue::query()->sole();
        $this->assertSame(IssueType::HttpUnavailable, $issue->type);
        $this->assertSame($this->domain->id, $issue->domain_id);
        $this->assertNull($issue->server_id);
        $this->assertNull($issue->cpanel_account_id);
        $this->assertStringNotContainsString('TASK14_SUPER_SECRET_TOKEN', $issue->title.' '.$issue->details);
    }

    public function test_dns_and_tls_observations_are_evaluated(): void
    {
        $dns = $this->mock(DnsResolver::class);
        $dns->expects('resolve')->twice()->with('example.invalid')->andReturn(
            DnsResult::failure('resolver_error', 'raw resolver failure'),
        );

        $this->runJob(new CheckDomainDns($this->domain->id));
        $this->runJob(new CheckDomainDns($this->domain->id));

        $tls = $this->mock(TlsInspector::class);
        $tls->expects('inspect')->once()->with('example.invalid', 443)->andReturn(
            TlsResult::failure('tls_invalid', 'raw OpenSSL warning TASK14_SUPER_SECRET_TOKEN'),
        );

        $this->runJob(new CheckDomainTls($this->domain->id));

        $this->assertTrue(Issue::where('type', IssueType::DnsUnresolved)->exists());
        $tlsIssue = Issue::where('type', IssueType::TlsInvalid)->sole();
        $this->assertStringNotContainsString('TASK14_SUPER_SECRET_TOKEN', $tlsIssue->title.' '.$tlsIssue->details);
    }

    public function test_server_health_observations_are_evaluated_without_exposing_credentials(): void
    {
        $client = $this->mock(WhmClient::class);
        $client->expects('getServerHealth')->twice()->andThrow(new WhmApiException('remote_failure', [
            'server_hostname' => 'whm.example.invalid',
            'reason' => 'Authorization: whm root:TASK14_SUPER_SECRET_TOKEN',
        ]));

        $this->runJob(new CheckServerHealth($this->server->id));
        $this->runJob(new CheckServerHealth($this->server->id));

        $issue = Issue::query()->sole();
        $this->assertSame(IssueType::ServerHealthUnavailable, $issue->type);
        $this->assertSame($this->server->id, $issue->server_id);
        $this->assertNull($issue->cpanel_account_id);
        $this->assertNull($issue->domain_id);
        $this->assertStringNotContainsString('TASK14_SUPER_SECRET_TOKEN', $issue->title.' '.$issue->details);
    }

    #[DataProvider('monitoringJobTypeProvider')]
    public function test_local_evaluation_failure_rolls_back_each_observation_and_retry_creates_only_one(string $type): void
    {
        $remoteCalls = 0;
        $job = $this->prepareRollbackScenario($type, $remoteCalls);
        $domainChecksBefore = DomainCheck::query()->count();
        $serverChecksBefore = ServerCheck::query()->count();
        $event = 'eloquent.creating: '.Issue::class;
        Event::listen($event, function () use (&$remoteCalls): void {
            $this->assertSame(1, $remoteCalls, 'Remote work must finish before issue evaluation begins.');

            throw new RuntimeException('Local evaluator failed.');
        });

        try {
            $this->runJob($job);
            $this->fail('The local evaluator exception did not escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local evaluator failed.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame($domainChecksBefore, DomainCheck::query()->count());
        $this->assertSame($serverChecksBefore, ServerCheck::query()->count());
        $this->assertSame(0, Issue::query()->count());

        $this->runJob($job);

        $this->assertSame($domainChecksBefore + ($type === 'server' ? 0 : 1), DomainCheck::query()->count());
        $this->assertSame($serverChecksBefore + ($type === 'server' ? 1 : 0), ServerCheck::query()->count());
        $this->assertSame(1, Issue::query()->count());
        $this->assertSame(2, $remoteCalls);
    }

    /** @return iterable<string, array{string}> */
    public static function monitoringJobTypeProvider(): iterable
    {
        yield 'HTTP' => ['http'];
        yield 'DNS' => ['dns'];
        yield 'TLS' => ['tls'];
        yield 'server health' => ['server'];
    }

    public function test_observation_lock_timeout_escapes_without_creating_an_unevaluated_check(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 500)]);
        $lock = Mockery::mock(Lock::class);
        $lock->expects('block')
            ->with(5, Mockery::type(Closure::class))
            ->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')
            ->once()
            ->with("estate:observation:domain:{$this->domain->id}:http", 10)
            ->andReturn($lock);

        try {
            $this->runJob(new CheckDomainHttp($this->domain->id));
            $this->fail('The lock timeout did not escape.');
        } catch (LockTimeoutException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DomainCheck::query()->count());
        $this->assertSame(0, Issue::query()->count());
        Http::assertSentCount(1);
    }

    public function test_issue_lock_timeout_rolls_back_the_observation_and_escapes(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 200)]);
        $observationLock = Mockery::mock(Lock::class);
        $observationLock->expects('block')
            ->with(5, Mockery::type(Closure::class))
            ->andReturnUsing(static fn (int $seconds, Closure $callback) => $callback());
        $issueLock = Mockery::mock(Lock::class);
        $issueLock->expects('block')
            ->with(5, Mockery::type(Closure::class))
            ->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')
            ->once()
            ->with("estate:observation:domain:{$this->domain->id}:http", 10)
            ->andReturn($observationLock);
        Cache::shouldReceive('lock')
            ->once()
            ->with("estate:issue:domain:{$this->domain->id}:http_unavailable", 10)
            ->andReturn($issueLock);

        try {
            $this->runJob(new CheckDomainHttp($this->domain->id));
            $this->fail('The issue lock timeout did not escape.');
        } catch (LockTimeoutException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DomainCheck::query()->count());
        $this->assertSame(0, Issue::query()->count());
        Http::assertSentCount(1);
    }

    public function test_successful_authoritative_inventory_evaluates_suspension_and_removed_accounts(): void
    {
        Bus::fake();
        $whm = new FakeWhmClient;
        $whm->accounts = [new WhmAccountData(
            username: $this->account->username,
            primaryDomain: 'example.invalid',
            homeDirectory: '/home/fixture',
            package: 'fixture',
            owner: 'root',
            suspended: true,
            suspensionReason: 'fixture',
            metadata: [],
        )];
        $this->app->instance(WhmClient::class, $whm);

        $this->runJob(new SyncServerInventory($this->server->id));
        $issue = Issue::query()->sole();
        $this->assertSame(IssueType::AccountSuspended, $issue->type);

        app(SyncRunRecorder::class)->finalize(SyncRun::query()->sole()->id);
        $whm->accounts = [];
        $this->runJob(new SyncServerInventory($this->server->id));

        $this->assertNotNull($issue->fresh()->resolved_at);
    }

    public function test_failed_authoritative_inventory_does_not_change_suspension_issue(): void
    {
        Bus::fake();
        $this->account->update(['suspended' => true]);
        app(IssueEvaluator::class)->evaluateAccount($this->account->fresh());
        $issue = Issue::query()->sole();
        $whm = new FakeWhmClient;
        $whm->accountsException = new WhmApiException('remote_failure', [
            'server_hostname' => 'whm.example.invalid',
        ]);
        $this->app->instance(WhmClient::class, $whm);
        CarbonImmutable::setTestNow(now()->addHour());

        $this->runJob(new SyncServerInventory($this->server->id));

        $this->assertNull($issue->fresh()->resolved_at);
        $this->assertTrue($issue->last_detected_at->equalTo($issue->fresh()->last_detected_at));
    }

    private function runJob(object $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    private function prepareRollbackScenario(string $type, int &$remoteCalls): object
    {
        if ($type === 'http') {
            DomainCheck::factory()->for($this->domain)->create([
                'check_type' => 'http',
                'checked_at' => now()->subMinute(),
                'successful' => true,
                'http_status' => 500,
            ]);
            Http::preventStrayRequests();
            Http::fake(function () use (&$remoteCalls) {
                $remoteCalls++;

                return Http::response('', 503);
            });

            return new CheckDomainHttp($this->domain->id);
        }

        if ($type === 'dns') {
            DomainCheck::factory()->for($this->domain)->create([
                'check_type' => 'dns',
                'checked_at' => now()->subMinute(),
                'successful' => false,
            ]);
            $resolver = $this->mock(DnsResolver::class);
            $resolver->expects('resolve')->twice()->andReturnUsing(function () use (&$remoteCalls): DnsResult {
                $remoteCalls++;

                return DnsResult::failure('resolver_error', 'fixture failure');
            });

            return new CheckDomainDns($this->domain->id);
        }

        if ($type === 'tls') {
            $inspector = $this->mock(TlsInspector::class);
            $inspector->expects('inspect')->twice()->andReturnUsing(function () use (&$remoteCalls): TlsResult {
                $remoteCalls++;

                return TlsResult::failure('tls_invalid', 'fixture failure');
            });

            return new CheckDomainTls($this->domain->id);
        }

        ServerCheck::factory()->for($this->server)->create([
            'checked_at' => now()->subMinute(),
            'reachable' => false,
        ]);
        $client = $this->mock(WhmClient::class);
        $client->expects('getServerHealth')->twice()->andReturnUsing(function () use (&$remoteCalls): never {
            $remoteCalls++;

            throw new WhmApiException('remote_failure', ['server_hostname' => 'whm.example.invalid']);
        });

        return new CheckServerHealth($this->server->id);
    }
}
