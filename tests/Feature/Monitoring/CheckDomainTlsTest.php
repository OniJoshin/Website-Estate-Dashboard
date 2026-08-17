<?php

namespace Tests\Feature\Monitoring;

use App\Data\Monitoring\TlsResult;
use App\Enums\DomainClassification;
use App\Enums\DomainType;
use App\Jobs\Monitoring\CheckDomainTls;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\Server;
use App\Services\Monitoring\Contracts\TlsInspector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CheckDomainTlsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Server $server;

    private CpanelAccount $account;

    private Domain $domain;

    private FakeTlsInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::factory()->create(['api_token' => 'TASK12_SUPER_SECRET_TOKEN']);
        $this->account = CpanelAccount::factory()->for($this->server)->create();
        $this->domain = Domain::factory()->for($this->account)->create([
            'domain' => 'example.invalid',
            'monitoring_enabled' => true,
            'is_active' => true,
            'removed_at' => null,
        ]);
        $this->inspector = new FakeTlsInspector(TlsResult::valid(
            CarbonImmutable::parse('2026-02-01 12:00:00 UTC'),
            30,
        ));
        $this->app->instance(TlsInspector::class, $this->inspector);
    }

    public function test_active_monitored_domain_persists_complete_tls_observation(): void
    {
        $this->runJob();

        $check = DomainCheck::query()->sole();
        $this->assertTrue($check->domain->is($this->domain));
        $this->assertSame('tls', $check->check_type);
        $this->assertTrue($check->successful);
        $this->assertTrue($check->ssl_valid);
        $this->assertSame('2026-02-01T12:00:00+00:00', $check->ssl_expires_at->toIso8601String());
        $this->assertSame(30, $check->ssl_days_remaining);
        $this->assertTrue($check->checked_at->isCurrentSecond());
        $this->assertNull($check->resolved_ips);
        $this->assertNull($check->http_status);
        $this->assertNull($check->response_time_ms);
        $this->assertNull($check->final_url);
        $this->assertNull($check->redirect_count);
        $this->assertNull($check->error_type);
        $this->assertNull($check->error_message);
        $this->assertSame([['example.invalid', 443]], $this->inspector->calls);
        $this->assertSame(0, Issue::query()->count());
    }

    public function test_expected_tls_failure_is_persisted_and_returns_normally_without_secret(): void
    {
        $this->inspector->result = TlsResult::failure('tls_invalid', 'TLS certificate validation failed.');

        $this->runJob();

        $check = DomainCheck::query()->sole();
        $this->assertFalse($check->successful);
        $this->assertFalse($check->ssl_valid);
        $this->assertNull($check->ssl_expires_at);
        $this->assertNull($check->ssl_days_remaining);
        $this->assertSame('tls_invalid', $check->error_type);
        $this->assertSame('TLS certificate validation failed.', $check->error_message);
        $this->assertStringNotContainsString('TASK12_SUPER_SECRET_TOKEN', serialize($check->toArray()));
    }

    public function test_repeated_executions_append_immutable_checks(): void
    {
        $this->runJob();
        $firstCheck = DomainCheck::query()->sole();
        $this->inspector->result = TlsResult::failure('timeout', 'TLS connection timed out.');
        $this->runJob();

        $this->assertSame(2, DomainCheck::query()->count());
        $this->assertTrue($firstCheck->fresh()->successful);
        $this->assertSame([true, false], DomainCheck::query()->oldest('id')->pluck('successful')->all());
    }

    #[DataProvider('ineligibleStateProvider')]
    public function test_ineligible_estate_state_is_skipped_without_check(string $target, string $attribute, mixed $value): void
    {
        $model = match ($target) {
            'domain' => $this->domain,
            'account' => $this->account,
            'server' => $this->server,
        };
        $model->update([$attribute => $value === 'now' ? now() : $value]);

        $this->runJob();

        $this->assertSame(0, DomainCheck::query()->count());
        $this->assertSame([], $this->inspector->calls);
    }

    /** @return iterable<string, array{string, string, mixed}> */
    public static function ineligibleStateProvider(): iterable
    {
        yield 'removed domain' => ['domain', 'removed_at', 'now'];
        yield 'inactive domain' => ['domain', 'is_active', false];
        yield 'monitoring disabled' => ['domain', 'monitoring_enabled', false];
        yield 'removed account' => ['account', 'removed_at', 'now'];
        yield 'disabled server' => ['server', 'enabled', false];
    }

    public function test_missing_domain_is_skipped(): void
    {
        $this->runJob(new CheckDomainTls(999_999));

        $this->assertSame(0, DomainCheck::query()->count());
        $this->assertSame([], $this->inspector->calls);
    }

    public function test_classification_does_not_override_monitoring_switch(): void
    {
        $this->domain->update([
            'type' => DomainType::Alias,
            'classification' => DomainClassification::Alias,
            'monitoring_enabled' => true,
        ]);

        $this->runJob();

        $this->assertSame(1, DomainCheck::query()->count());
        $this->assertSame([['example.invalid', 443]], $this->inspector->calls);
    }

    public function test_job_serializes_only_domain_id_and_never_server_token(): void
    {
        $job = new CheckDomainTls($this->domain->id);
        $serialized = serialize($job);

        $this->assertSame($this->domain->id, $job->domainId);
        $this->assertObjectNotHasProperty('domain', $job);
        $this->assertObjectNotHasProperty('account', $job);
        $this->assertObjectNotHasProperty('server', $job);
        $this->assertStringNotContainsString('TASK12_SUPER_SECRET_TOKEN', $serialized);
        $this->assertStringNotContainsString('example.invalid', $serialized);
    }

    public function test_unexpected_inspector_exception_fails_job_without_check(): void
    {
        $this->inspector->exception = new RuntimeException('Unexpected TLS fixture failure.');
        $this->expectException(RuntimeException::class);

        try {
            $this->runJob();
        } finally {
            $this->assertSame(0, DomainCheck::query()->count());
        }
    }

    private function runJob(?CheckDomainTls $job = null): void
    {
        $this->app->call([$job ?? new CheckDomainTls($this->domain->id), 'handle']);
    }
}

final class FakeTlsInspector implements TlsInspector
{
    /** @var list<array{string, int}> */
    public array $calls = [];

    public ?RuntimeException $exception = null;

    public function __construct(public TlsResult $result) {}

    public function inspect(string $hostname, int $port = 443): TlsResult
    {
        $this->calls[] = [$hostname, $port];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result;
    }
}
