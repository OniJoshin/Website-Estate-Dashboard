<?php

namespace Tests\Feature\Monitoring;

use App\Data\Monitoring\DnsResult;
use App\Enums\DomainClassification;
use App\Enums\DomainType;
use App\Jobs\Monitoring\CheckDomainDns;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\Server;
use App\Services\Monitoring\Contracts\DnsResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CheckDomainDnsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Server $server;

    private CpanelAccount $account;

    private Domain $domain;

    private FakeDnsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::factory()->create(['api_token' => 'task-eleven-secret-token']);
        $this->account = CpanelAccount::factory()->for($this->server)->create();
        $this->domain = Domain::factory()->for($this->account)->create([
            'domain' => 'example.invalid',
            'monitoring_enabled' => true,
            'is_active' => true,
            'removed_at' => null,
        ]);
        $this->resolver = new FakeDnsResolver(DnsResult::resolved(
            ['192.0.2.1'], ['2001:db8::1'], ['origin.example.invalid'],
        ));
        $this->app->instance(DnsResolver::class, $this->resolver);
    }

    public function test_active_monitored_domain_persists_complete_dns_observation(): void
    {
        $this->runJob();

        $check = DomainCheck::query()->sole();
        $this->assertTrue($check->domain->is($this->domain));
        $this->assertSame('dns', $check->check_type);
        $this->assertTrue($check->successful);
        $this->assertSame([
            'a' => ['192.0.2.1'],
            'aaaa' => ['2001:db8::1'],
            'cname' => ['origin.example.invalid'],
        ], $check->resolved_ips);
        $this->assertTrue($check->checked_at->isCurrentSecond());
        $this->assertNull($check->http_status);
        $this->assertNull($check->response_time_ms);
        $this->assertNull($check->final_url);
        $this->assertNull($check->redirect_count);
        $this->assertNull($check->ssl_valid);
        $this->assertNull($check->ssl_expires_at);
        $this->assertNull($check->ssl_days_remaining);
        $this->assertNull($check->error_type);
        $this->assertNull($check->error_message);
        $this->assertSame(['example.invalid'], $this->resolver->hostnames);
        $this->assertSame(0, Issue::query()->count());
    }

    #[DataProvider('failureResultProvider')]
    public function test_expected_dns_failure_is_persisted_and_returns_normally(string $errorType, string $message): void
    {
        $this->resolver->result = DnsResult::failure($errorType, $message);

        $this->runJob();

        $check = DomainCheck::query()->sole();
        $this->assertFalse($check->successful);
        $this->assertSame(['a' => [], 'aaaa' => [], 'cname' => []], $check->resolved_ips);
        $this->assertSame($errorType, $check->error_type);
        $this->assertSame($message, $check->error_message);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failureResultProvider(): iterable
    {
        yield 'no records' => ['no_records', 'No relevant DNS records were found.'];
        yield 'resolver error' => ['resolver_error', 'DNS resolution failed.'];
    }

    public function test_repeated_executions_append_immutable_checks(): void
    {
        $this->runJob();
        $firstCheck = DomainCheck::query()->sole();
        $this->resolver->result = DnsResult::failure('no_records', 'No relevant DNS records were found.');
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
        $this->assertSame([], $this->resolver->hostnames);
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
        $this->runJob(new CheckDomainDns(999_999));

        $this->assertSame(0, DomainCheck::query()->count());
        $this->assertSame([], $this->resolver->hostnames);
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
        $this->assertSame(['example.invalid'], $this->resolver->hostnames);
    }

    public function test_job_serializes_only_domain_id_and_never_server_token(): void
    {
        $job = new CheckDomainDns($this->domain->id);
        $serialized = serialize($job);

        $this->assertSame($this->domain->id, $job->domainId);
        $this->assertObjectNotHasProperty('domain', $job);
        $this->assertObjectNotHasProperty('account', $job);
        $this->assertObjectNotHasProperty('server', $job);
        $this->assertStringNotContainsString('task-eleven-secret-token', $serialized);
        $this->assertStringNotContainsString('example.invalid', $serialized);
    }

    public function test_unexpected_resolver_exception_fails_job_without_check(): void
    {
        $this->resolver->exception = new RuntimeException('Unexpected resolver fixture failure.');
        $this->expectException(RuntimeException::class);

        try {
            $this->runJob();
        } finally {
            $this->assertSame(0, DomainCheck::query()->count());
        }
    }

    private function runJob(?CheckDomainDns $job = null): void
    {
        $this->app->call([$job ?? new CheckDomainDns($this->domain->id), 'handle']);
    }
}

final class FakeDnsResolver implements DnsResolver
{
    /** @var list<string> */
    public array $hostnames = [];

    public ?RuntimeException $exception = null;

    public function __construct(public DnsResult $result) {}

    public function resolve(string $hostname): DnsResult
    {
        $this->hostnames[] = $hostname;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result;
    }
}
