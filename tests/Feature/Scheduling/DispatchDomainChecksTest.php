<?php

namespace Tests\Feature\Scheduling;

use App\Enums\DomainClassification;
use App\Jobs\Monitoring\CheckDomainDns;
use App\Jobs\Monitoring\CheckDomainHttp;
use App\Jobs\Monitoring\CheckDomainTls;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Server;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DispatchDomainChecksTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, class-string}> */
    public static function checkTypes(): array
    {
        return [
            'HTTP' => ['http', CheckDomainHttp::class],
            'DNS' => ['dns', CheckDomainDns::class],
            'TLS' => ['tls', CheckDomainTls::class],
        ];
    }

    #[DataProvider('checkTypes')]
    public function test_it_queues_the_requested_check_type_for_an_eligible_domain(string $type, string $jobClass): void
    {
        Queue::fake();
        $domain = Domain::factory()->create();

        $this->artisan("estate:dispatch-domain-checks {$type}")->assertSuccessful();

        Queue::assertPushed($jobClass, fn ($job) => $job->domainId === $domain->id);
        $this->assertSame(0, DomainCheck::count());
    }

    public function test_it_applies_complete_estate_eligibility_without_classification_filtering(): void
    {
        Queue::fake();
        $server = Server::factory()->create(['enabled' => true]);
        $account = CpanelAccount::factory()->for($server)->create();
        $eligible = Domain::factory()->for($account)->create([
            'classification' => DomainClassification::Alias,
            'monitoring_enabled' => true,
        ]);
        Domain::factory()->for($account)->removed()->create();
        Domain::factory()->for($account)->create(['is_active' => false]);
        Domain::factory()->for($account)->create(['monitoring_enabled' => false]);
        Domain::factory()->for(CpanelAccount::factory()->removed()->for($server))->create();
        Domain::factory()->for(CpanelAccount::factory()->for(Server::factory()->state(['enabled' => false])))->create();

        $this->artisan('estate:dispatch-domain-checks http')->assertSuccessful();

        Queue::assertPushed(CheckDomainHttp::class, fn (CheckDomainHttp $job) => $job->domainId === $eligible->id);
        Queue::assertPushed(CheckDomainHttp::class, 1);
    }

    public function test_invalid_type_is_rejected(): void
    {
        Queue::fake();

        $this->artisan('estate:dispatch-domain-checks smtp')->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_chunked_dispatch_does_not_duplicate_jobs(): void
    {
        Queue::fake();
        $account = CpanelAccount::factory()->create();
        Domain::factory()->count(205)->for($account)->create();

        $this->artisan('estate:dispatch-domain-checks dns')->assertSuccessful();

        Queue::assertPushed(CheckDomainDns::class, 205);
        $ids = collect(Queue::pushed(CheckDomainDns::class))->pluck('domainId');
        $this->assertCount(205, $ids->unique());
    }
}
