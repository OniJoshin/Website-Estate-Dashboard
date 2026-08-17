<?php

namespace Tests\Feature\Monitoring;

use App\Enums\DomainClassification;
use App\Enums\DomainType;
use App\Jobs\Monitoring\CheckDomainHttp;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\Server;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CheckDomainHttpTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Server $server;

    private CpanelAccount $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->server = Server::factory()->create(['api_token' => 'task-ten-secret-token']);
        $this->account = CpanelAccount::factory()->for($this->server)->create();
        $this->domain = Domain::factory()->for($this->account)->create([
            'domain' => 'example.invalid',
            'monitoring_enabled' => true,
            'is_active' => true,
            'removed_at' => null,
        ]);
    }

    public function test_active_monitored_domain_persists_one_complete_http_observation(): void
    {
        Http::fake(['*' => Http::response('body is ignored', 200)]);

        $this->runJob();

        $check = DomainCheck::query()->sole();
        $this->assertTrue($check->domain->is($this->domain));
        $this->assertSame('http', $check->check_type);
        $this->assertTrue($check->successful);
        $this->assertSame(200, $check->http_status);
        $this->assertIsInt($check->response_time_ms);
        $this->assertSame('https://example.invalid/', $check->final_url);
        $this->assertSame(0, $check->redirect_count);
        $this->assertTrue($check->checked_at->isCurrentSecond());
        $this->assertNull($check->resolved_ips);
        $this->assertNull($check->ssl_valid);
        $this->assertNull($check->ssl_expires_at);
        $this->assertNull($check->ssl_days_remaining);
        $this->assertNull($check->error_type);
        $this->assertNull($check->error_message);
        $this->assertSame(0, Issue::query()->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.invalid/');
    }

    #[DataProvider('receivedResponseProvider')]
    public function test_received_error_status_is_still_a_successful_exchange(int $status): void
    {
        Http::fake(['*' => Http::response('error body is ignored', $status)]);

        $this->runJob();

        $check = DomainCheck::query()->sole();
        $this->assertTrue($check->successful);
        $this->assertSame($status, $check->http_status);
        $this->assertNull($check->error_type);
    }

    /** @return iterable<string, array{int}> */
    public static function receivedResponseProvider(): iterable
    {
        yield 'not found' => [404];
        yield 'server error' => [500];
    }

    public function test_expected_connection_failure_is_persisted_and_returns_normally(): void
    {
        Http::fake(['*' => Http::failedConnection('Could not resolve host task-ten-secret-token')]);

        $this->runJob();

        $check = DomainCheck::query()->sole();
        $this->assertFalse($check->successful);
        $this->assertNull($check->http_status);
        $this->assertSame('connection_failed', $check->error_type);
        $this->assertSame('Unable to connect to the domain.', $check->error_message);
        $this->assertStringNotContainsString('task-ten-secret-token', serialize($check->toArray()));
    }

    public function test_repeated_executions_append_immutable_checks(): void
    {
        Http::fakeSequence('*')
            ->push('', 200)
            ->push('', 500);

        $this->runJob();
        $firstCheck = DomainCheck::query()->sole();
        $this->runJob();

        $this->assertSame(2, DomainCheck::query()->count());
        $this->assertSame(200, $firstCheck->fresh()->http_status);
        $this->assertSame([200, 500], DomainCheck::query()->oldest('id')->pluck('http_status')->all());
    }

    #[DataProvider('ineligibleStateProvider')]
    public function test_ineligible_estate_state_is_skipped_without_a_check_or_request(string $target, string $attribute, mixed $value): void
    {
        $model = match ($target) {
            'domain' => $this->domain,
            'account' => $this->account,
            'server' => $this->server,
        };
        $model->update([$attribute => $value === 'now' ? now() : $value]);
        Http::fake();

        $this->runJob();

        $this->assertSame(0, DomainCheck::query()->count());
        Http::assertNothingSent();
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

    public function test_missing_domain_is_skipped_without_a_request(): void
    {
        Http::fake();

        $this->runJob(new CheckDomainHttp(999_999));

        $this->assertSame(0, DomainCheck::query()->count());
        Http::assertNothingSent();
    }

    public function test_classification_does_not_override_the_monitoring_switch(): void
    {
        $this->domain->update([
            'type' => DomainType::Alias,
            'classification' => DomainClassification::Alias,
            'monitoring_enabled' => true,
        ]);
        Http::fake(['*' => Http::response('', 200)]);

        $this->runJob();

        $this->assertSame(1, DomainCheck::query()->count());
        Http::assertSentCount(1);
    }

    public function test_job_serializes_only_domain_id_and_never_the_server_token(): void
    {
        $job = new CheckDomainHttp($this->domain->id);
        $serialized = serialize($job);

        $this->assertSame($this->domain->id, $job->domainId);
        $this->assertObjectNotHasProperty('domain', $job);
        $this->assertObjectNotHasProperty('account', $job);
        $this->assertObjectNotHasProperty('server', $job);
        $this->assertStringNotContainsString('task-ten-secret-token', $serialized);
        $this->assertStringNotContainsString('example.invalid', $serialized);
    }

    public function test_unexpected_programming_failure_is_not_converted_into_an_observation(): void
    {
        Http::fake(fn () => throw new RuntimeException('Unexpected fixture failure.'));

        $this->expectException(RuntimeException::class);

        $this->runJob();
    }

    private function runJob(?CheckDomainHttp $job = null): void
    {
        $this->app->call([$job ?? new CheckDomainHttp($this->domain->id), 'handle']);
    }
}
