<?php

namespace Tests\Feature\Inventory;

use App\Data\Whm\WhmDomainData;
use App\Data\Whm\WhmDomainInventory;
use App\Enums\DomainClassification;
use App\Enums\DomainClassificationSource;
use App\Enums\DomainType;
use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use App\Jobs\Inventory\SyncCpanelAccountDomains;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Server;
use App\Models\SyncRun;
use App\Services\Inventory\SyncRunRecorder;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeWhmClient;
use Tests\TestCase;

class SyncCpanelAccountDomainsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private FakeWhmClient $whm;

    private Server $server;

    private CpanelAccount $account;

    private SyncRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->whm = new FakeWhmClient;
        $this->app->instance(WhmClient::class, $this->whm);
        $this->server = Server::factory()->create();
        $this->account = CpanelAccount::factory()->for($this->server)->create(['username' => 'alpha']);
        $this->run = SyncRun::factory()->for($this->server)->create([
            'type' => SyncRunType::Inventory,
            'status' => SyncRunStatus::Running,
            'completed_at' => null,
            'domains_found' => 0,
            'domains_created' => 0,
            'domains_updated' => 0,
            'domains_removed' => 0,
            'errors_count' => 0,
            'error_summary' => null,
        ]);
    }

    public function test_successful_inventory_creates_all_domain_types_and_resolves_parents(): void
    {
        $this->whm->domainInventories['alpha'] = new WhmDomainInventory([
            $this->domain('alpha.example.invalid', DomainType::Primary, '/home/alpha/public_html'),
            $this->domain('addon.invalid', DomainType::Addon, '/home/alpha/addon'),
            $this->domain('staging.alpha.example.invalid', DomainType::Subdomain, '/home/alpha/staging', 'alpha.example.invalid'),
            $this->domain('alias.invalid', DomainType::Alias, null, 'missing.invalid'),
            $this->domain('mail.alpha.example.invalid', DomainType::Subdomain, null),
            $this->domain('portal.alpha.example.invalid', DomainType::Subdomain, null),
        ]);

        $this->runJob();

        $primary = Domain::where('domain', 'alpha.example.invalid')->sole();
        $staging = Domain::where('domain', 'staging.alpha.example.invalid')->sole();
        $this->assertSame(DomainType::Primary, $primary->type);
        $this->assertSame(DomainType::Addon, Domain::where('domain', 'addon.invalid')->sole()->type);
        $this->assertSame(DomainType::Subdomain, $staging->type);
        $this->assertSame(DomainType::Alias, Domain::where('domain', 'alias.invalid')->sole()->type);
        $this->assertSame('/home/alpha/public_html', $primary->document_root);
        $this->assertSame(['source' => 'fake'], $primary->metadata);
        $this->assertSame($primary->id, $staging->parent_domain_id);
        $this->assertNull(Domain::where('domain', 'alias.invalid')->sole()->parent_domain_id);
        $this->assertDomainPolicy('alpha.example.invalid', DomainClassification::Website, true);
        $this->assertDomainPolicy('addon.invalid', DomainClassification::Website, true);
        $this->assertDomainPolicy('staging.alpha.example.invalid', DomainClassification::Development, true);
        $this->assertDomainPolicy('portal.alpha.example.invalid', DomainClassification::Unknown, true);
        $this->assertDomainPolicy('alias.invalid', DomainClassification::Alias, false);
        $this->assertDomainPolicy('mail.alpha.example.invalid', DomainClassification::Service, false);
        $this->assertSame(6, $this->run->fresh()->domains_found);
        $this->assertSame(6, $this->run->fresh()->domains_created);
    }

    public function test_existing_manual_classification_and_monitoring_are_preserved_while_auto_may_change(): void
    {
        $manual = Domain::factory()->for($this->account)->create([
            'domain' => 'staging.alpha.example.invalid',
            'type' => DomainType::Subdomain,
            'classification' => DomainClassification::Website,
            'classification_source' => DomainClassificationSource::Manual,
            'monitoring_enabled' => false,
            'document_root' => null,
            'metadata' => ['source' => 'fake'],
        ]);
        $auto = Domain::factory()->for($this->account)->create([
            'domain' => 'mail.alpha.example.invalid',
            'type' => DomainType::Primary,
            'classification' => DomainClassification::Website,
            'classification_source' => DomainClassificationSource::Auto,
            'monitoring_enabled' => true,
            'document_root' => null,
            'metadata' => ['source' => 'fake'],
        ]);
        $this->whm->domainInventories['alpha'] = new WhmDomainInventory([
            $this->domain($manual->domain, DomainType::Subdomain),
            $this->domain($auto->domain, DomainType::Subdomain),
        ]);

        $this->runJob();

        $this->assertSame(DomainClassification::Website, $manual->fresh()->classification);
        $this->assertFalse($manual->fresh()->monitoring_enabled);
        $this->assertSame(DomainClassification::Service, $auto->fresh()->classification);
        $this->assertTrue($auto->fresh()->monitoring_enabled);
        $this->assertSame(1, $this->run->fresh()->domains_updated);
    }

    public function test_failed_inventory_preserves_domains_and_last_seen_and_records_error_safely(): void
    {
        $domain = Domain::factory()->for($this->account)->create();
        $lastSeenAt = $domain->last_seen_at;
        $this->server->update(['api_token' => 'TASK8_SUPER_SECRET_TOKEN']);
        $this->whm->domainExceptions['alpha'] = new WhmApiException('remote_failure', [
            'server_id' => $this->server->id,
            'server_hostname' => $this->server->hostname,
            'function' => 'uapi_cpanel',
            'username' => 'alpha',
        ]);

        $job = new SyncCpanelAccountDomains($this->account->id, $this->run->id);
        $this->assertIsInt($job->cpanelAccountId);
        $this->assertIsInt($job->syncRunId);
        $this->assertObjectNotHasProperty('cpanelAccount', $job);
        $this->assertObjectNotHasProperty('syncRun', $job);
        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', serialize($job));
        $this->runJob($job);

        $this->assertNull($domain->fresh()->removed_at);
        $this->assertTrue($lastSeenAt->equalTo($domain->fresh()->last_seen_at));
        $this->assertSame(1, $this->run->fresh()->errors_count);
        $this->assertStringContainsString('alpha', $this->run->fresh()->error_summary ?? '');
        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', $this->run->fresh()->error_summary ?? '');
    }

    public function test_successful_missing_domain_is_removed_only_once(): void
    {
        $domain = Domain::factory()->for($this->account)->create();
        $this->whm->domainInventories['alpha'] = new WhmDomainInventory([]);

        $this->runJob();
        $this->assertNotNull($domain->fresh()->removed_at);
        $this->assertFalse($domain->fresh()->is_active);
        $this->assertSame(1, $this->run->fresh()->domains_removed);

        $this->runJob();
        $this->assertSame(1, $this->run->fresh()->domains_removed);
    }

    public function test_removed_domain_reappears_preserving_discovery_manual_classification_and_monitoring(): void
    {
        $domain = Domain::factory()->removed()->for($this->account)->create([
            'domain' => 'staging.alpha.example.invalid',
            'classification' => DomainClassification::Ignored,
            'classification_source' => DomainClassificationSource::Manual,
            'monitoring_enabled' => false,
        ]);
        $discoveredAt = $domain->discovered_at;
        $this->whm->domainInventories['alpha'] = new WhmDomainInventory([
            $this->domain($domain->domain, DomainType::Subdomain),
        ]);

        $this->runJob();

        $domain->refresh();
        $this->assertNull($domain->removed_at);
        $this->assertTrue($domain->is_active);
        $this->assertTrue($discoveredAt->equalTo($domain->discovered_at));
        $this->assertSame(DomainClassification::Ignored, $domain->classification);
        $this->assertFalse($domain->monitoring_enabled);
        $this->assertSame(1, $this->run->fresh()->domains_updated);
    }

    public function test_identical_inventory_is_idempotent_but_source_change_counts_as_updated(): void
    {
        $this->whm->domainInventories['alpha'] = new WhmDomainInventory([
            $this->domain('alpha.example.invalid', DomainType::Primary, '/one'),
        ]);
        $this->runJob();
        $domain = Domain::sole();
        $discoveredAt = $domain->discovered_at;
        $this->runJob();

        $this->assertSame(1, Domain::count());
        $this->assertTrue($discoveredAt->equalTo($domain->fresh()->discovered_at));
        $this->assertSame(1, $this->run->fresh()->domains_created);
        $this->assertSame(0, $this->run->fresh()->domains_updated);

        $this->whm->domainInventories['alpha'] = new WhmDomainInventory([
            $this->domain('alpha.example.invalid', DomainType::Primary, '/two'),
        ]);
        $this->runJob();
        $this->assertSame(1, $this->run->fresh()->domains_updated);
    }

    public function test_removed_account_is_skipped_without_remote_call(): void
    {
        $this->account->update(['removed_at' => now()]);

        $this->runJob();

        $this->assertSame([], $this->whm->domainCalls);
    }

    public function test_one_expected_account_failure_does_not_prevent_another_account_sync(): void
    {
        $other = CpanelAccount::factory()->for($this->server)->create(['username' => 'bravo']);
        $this->whm->domainExceptions['alpha'] = new WhmApiException('remote_failure', [
            'server_id' => $this->server->id,
            'server_hostname' => $this->server->hostname,
            'function' => 'uapi_cpanel',
            'username' => 'alpha',
        ]);
        $this->whm->domainInventories['bravo'] = new WhmDomainInventory([
            $this->domain('bravo.example.invalid', DomainType::Primary),
        ]);

        $this->runJob();
        $this->runJob(new SyncCpanelAccountDomains($other->id, $this->run->id));
        app(SyncRunRecorder::class)->finalize($this->run->id);

        $this->assertSame(1, Domain::whereBelongsTo($other)->count());
        $this->assertSame(1, $this->run->fresh()->errors_count);
        $this->assertSame(SyncRunStatus::Partial, $this->run->fresh()->status);
    }

    public function test_parent_resolution_rejects_self_and_domains_from_other_accounts(): void
    {
        $otherAccount = CpanelAccount::factory()->for($this->server)->create(['username' => 'bravo']);
        Domain::factory()->for($otherAccount)->create(['domain' => 'outside.example.invalid']);
        $this->whm->domainInventories['alpha'] = new WhmDomainInventory([
            $this->domain('self.example.invalid', DomainType::Subdomain, parentDomain: 'self.example.invalid'),
            $this->domain('child.example.invalid', DomainType::Subdomain, parentDomain: 'outside.example.invalid'),
        ]);

        $this->runJob();

        $this->assertNull(Domain::whereBelongsTo($this->account)->where('domain', 'self.example.invalid')->sole()->parent_domain_id);
        $this->assertNull(Domain::whereBelongsTo($this->account)->where('domain', 'child.example.invalid')->sole()->parent_domain_id);
    }

    public function test_successful_child_processing_finalizes_as_successful(): void
    {
        $this->server->update(['last_successful_sync_at' => null]);
        $this->whm->domainInventories['alpha'] = new WhmDomainInventory([
            $this->domain('alpha.example.invalid', DomainType::Primary),
        ]);

        $this->runJob();
        app(SyncRunRecorder::class)->finalize($this->run->id);

        $this->run->refresh();
        $this->assertSame(SyncRunStatus::Successful, $this->run->status);
        $this->assertNotNull($this->run->completed_at);
        $this->assertNotNull($this->server->fresh()->last_synced_at);
        $this->assertNotNull($this->server->fresh()->last_successful_sync_at);
    }

    public function test_domain_failure_never_exposes_the_server_token_or_logs_it(): void
    {
        Log::spy();
        $this->server->update(['api_token' => 'TASK8_SUPER_SECRET_TOKEN']);
        $exception = new WhmApiException('remote_failure', [
            'server_id' => $this->server->id,
            'server_hostname' => $this->server->hostname,
            'function' => 'uapi_cpanel',
            'username' => 'alpha',
        ]);
        $this->whm->domainExceptions['alpha'] = $exception;
        $job = new SyncCpanelAccountDomains($this->account->id, $this->run->id);

        $this->runJob($job);

        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', serialize($job));
        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', $this->run->fresh()->error_summary ?? '');
        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', $exception->getMessage());
        Log::shouldNotHaveReceived('error');
    }

    private function runJob(?SyncCpanelAccountDomains $job = null): void
    {
        $this->app->call([($job ?? new SyncCpanelAccountDomains($this->account->id, $this->run->id)), 'handle']);
    }

    private function domain(
        string $domain,
        DomainType $type,
        ?string $documentRoot = null,
        ?string $parentDomain = null,
    ): WhmDomainData {
        return new WhmDomainData($domain, $type, $documentRoot, $parentDomain, ['source' => 'fake']);
    }

    private function assertDomainPolicy(string $domain, DomainClassification $classification, bool $monitored): void
    {
        $model = Domain::where('domain', $domain)->sole();
        $this->assertSame($classification, $model->classification);
        $this->assertSame(DomainClassificationSource::Auto, $model->classification_source);
        $this->assertSame($monitored, $model->monitoring_enabled);
    }
}
