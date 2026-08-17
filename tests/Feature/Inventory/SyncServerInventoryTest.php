<?php

namespace Tests\Feature\Inventory;

use App\Data\Whm\WhmAccountData;
use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use App\Jobs\Inventory\SyncCpanelAccountDomains;
use App\Jobs\Inventory\SyncServerInventory;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Server;
use App\Models\SyncRun;
use App\Services\Inventory\SyncRunRecorder;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeWhmClient;
use Tests\TestCase;

class SyncServerInventoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private FakeWhmClient $whm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->whm = new FakeWhmClient;
        $this->app->instance(WhmClient::class, $this->whm);
    }

    public function test_disabled_or_missing_server_exits_without_remote_calls(): void
    {
        $server = Server::factory()->create(['enabled' => false]);

        $this->runJob(new SyncServerInventory($server->id));
        $this->runJob(new SyncServerInventory(999_999));

        $this->assertSame(0, $this->whm->accountCalls);
        $this->assertSame(0, SyncRun::count());
    }

    public function test_successful_inventory_creates_accounts_with_source_disk_and_batch_data(): void
    {
        Bus::fake();
        $server = Server::factory()->create(['last_synced_at' => null, 'last_successful_sync_at' => null]);
        $this->whm->accounts = [$this->account('alpha', suspended: true)];
        $this->whm->diskUsage = ['alpha' => ['used_bytes' => 1_024, 'limit_bytes' => 8_192]];

        $this->runJob(new SyncServerInventory($server->id));

        $account = CpanelAccount::sole();
        $run = SyncRun::sole();
        $this->assertSame('alpha', $account->username);
        $this->assertSame('alpha.example.invalid', $account->primary_domain);
        $this->assertSame('/home/alpha', $account->home_directory);
        $this->assertSame('business', $account->package);
        $this->assertSame('root', $account->owner);
        $this->assertTrue($account->suspended);
        $this->assertSame('billing', $account->suspension_reason);
        $this->assertSame(1_024, $account->disk_used_bytes);
        $this->assertSame(8_192, $account->disk_limit_bytes);
        $this->assertSame(['plan' => 'business'], $account->metadata);
        $this->assertNotNull($account->discovered_at);
        $this->assertNotNull($account->last_seen_at);
        $this->assertSame(SyncRunType::Inventory, $run->type);
        $this->assertSame(SyncRunStatus::Running, $run->status);
        $this->assertSame(1, $run->accounts_found);
        $this->assertSame(1, $run->accounts_created);
        $this->assertNotNull($run->batch_id);
        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->allowsFailures() && $batch->hasJobs([
            fn (SyncCpanelAccountDomains $job): bool => $job->cpanelAccountId === $account->id && $job->syncRunId === $run->id,
        ]));
    }

    public function test_identical_sync_is_idempotent_and_meaningful_change_is_counted(): void
    {
        Bus::fake();
        $server = Server::factory()->create();
        $this->whm->accounts = [$this->account('alpha')];

        $this->runJob(new SyncServerInventory($server->id));
        $firstAccount = CpanelAccount::sole();
        $discoveredAt = $firstAccount->discovered_at;
        app(SyncRunRecorder::class)->finalize(SyncRun::sole()->id);
        $this->runJob(new SyncServerInventory($server->id));

        $this->assertSame(1, CpanelAccount::count());
        $this->assertTrue($discoveredAt->equalTo(CpanelAccount::sole()->discovered_at));
        $this->assertSame(0, SyncRun::latest('id')->firstOrFail()->accounts_created);
        $this->assertSame(0, SyncRun::latest('id')->firstOrFail()->accounts_updated);

        app(SyncRunRecorder::class)->finalize(SyncRun::latest('id')->firstOrFail()->id);
        $this->whm->accounts = [$this->account('alpha', package: 'enterprise')];
        $this->runJob(new SyncServerInventory($server->id));
        $this->assertSame(1, SyncRun::latest('id')->firstOrFail()->accounts_updated);
    }

    public function test_authoritative_failure_is_failed_and_removes_nothing_or_dispatches_domains(): void
    {
        Bus::fake();
        $server = Server::factory()->create(['last_successful_sync_at' => now()->subDay()]);
        $account = CpanelAccount::factory()->for($server)->create();
        $domain = Domain::factory()->for($account)->create();
        $this->whm->accountsException = $this->apiException($server, 'listaccts');

        $this->runJob(new SyncServerInventory($server->id));

        $run = SyncRun::sole();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(1, $run->errors_count);
        $this->assertNull($account->fresh()->removed_at);
        $this->assertNull($domain->fresh()->removed_at);
        $this->assertNotNull($server->fresh()->last_synced_at);
        $this->assertTrue($server->last_successful_sync_at->equalTo($server->fresh()->last_successful_sync_at));
        Bus::assertNothingBatched();
    }

    public function test_disk_failure_reconciles_accounts_preserves_usage_and_records_partial_error(): void
    {
        Bus::fake();
        $server = Server::factory()->create();
        $account = CpanelAccount::factory()->for($server)->create([
            'username' => 'alpha', 'disk_used_bytes' => 100, 'disk_limit_bytes' => 200,
        ]);
        $this->whm->accounts = [$this->account('alpha')];
        $this->whm->diskUsageException = $this->apiException($server, 'get_disk_usage');

        $this->runJob(new SyncServerInventory($server->id));

        $this->assertSame(100, $account->fresh()->disk_used_bytes);
        $this->assertSame(200, $account->fresh()->disk_limit_bytes);
        $this->assertSame('business', $account->fresh()->package);
        $this->assertSame(1, SyncRun::sole()->errors_count);
        $this->assertStringContainsString('disk usage', SyncRun::sole()->error_summary);
        app(SyncRunRecorder::class)->finalize(SyncRun::sole()->id);
        $this->assertSame(SyncRunStatus::Partial, SyncRun::sole()->fresh()->status);
    }

    public function test_successful_missing_account_removes_it_and_domains_only_once(): void
    {
        Bus::fake();
        $server = Server::factory()->create();
        $account = CpanelAccount::factory()->for($server)->create();
        Domain::factory()->count(2)->for($account)->create();

        $this->runJob(new SyncServerInventory($server->id));
        $firstRun = SyncRun::sole();
        $this->assertNotNull($account->fresh()->removed_at);
        $this->assertSame(2, Domain::whereNotNull('removed_at')->where('is_active', false)->count());
        $this->assertSame(1, $firstRun->accounts_removed);
        $this->assertSame(2, $firstRun->domains_removed);

        $this->runJob(new SyncServerInventory($server->id));
        $this->assertSame(0, SyncRun::latest('id')->firstOrFail()->accounts_removed);
        $this->assertSame(0, SyncRun::latest('id')->firstOrFail()->domains_removed);
    }

    public function test_removed_account_reappears_with_original_discovery_time(): void
    {
        Bus::fake();
        $server = Server::factory()->create();
        $account = CpanelAccount::factory()->removed()->for($server)->create(['username' => 'alpha']);
        $discoveredAt = $account->discovered_at;
        $this->whm->accounts = [$this->account('alpha')];

        $this->runJob(new SyncServerInventory($server->id));

        $this->assertNull($account->fresh()->removed_at);
        $this->assertTrue($discoveredAt->equalTo($account->fresh()->discovered_at));
        $this->assertSame(1, SyncRun::sole()->accounts_updated);
    }

    public function test_empty_inventory_finalizes_successfully_and_updates_success_timestamp(): void
    {
        $server = Server::factory()->create(['last_successful_sync_at' => null]);

        $this->runJob(new SyncServerInventory($server->id));

        $run = SyncRun::sole();
        $this->assertSame(SyncRunStatus::Successful, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertNotNull($server->fresh()->last_synced_at);
        $this->assertNotNull($server->fresh()->last_successful_sync_at);
    }

    public function test_existing_running_sync_prevents_another_remote_call(): void
    {
        $server = Server::factory()->create();
        SyncRun::factory()->for($server)->create([
            'type' => SyncRunType::Inventory,
            'status' => SyncRunStatus::Running,
            'completed_at' => null,
        ]);

        $this->runJob(new SyncServerInventory($server->id));

        $this->assertSame(0, $this->whm->accountCalls);
        $this->assertSame(1, SyncRun::count());
    }

    public function test_jobs_serialize_only_ids_and_error_summaries_never_contain_tokens(): void
    {
        Bus::fake();
        $server = Server::factory()->create(['api_token' => 'TASK8_SUPER_SECRET_TOKEN']);
        $this->whm->accountsException = $this->apiException($server, 'listaccts');

        $job = new SyncServerInventory($server->id);
        $this->assertIsInt($job->serverId);
        $this->assertObjectNotHasProperty('server', $job);
        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', serialize($job));
        $this->runJob($job);
        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', SyncRun::sole()->error_summary ?? '');
    }

    public function test_finalization_uses_errors_and_batch_failures_to_set_terminal_state(): void
    {
        $successfulServer = Server::factory()->create(['last_successful_sync_at' => null]);
        $successful = SyncRun::factory()->for($successfulServer)->create([
            'status' => SyncRunStatus::Running,
            'completed_at' => null,
            'errors_count' => 0,
        ]);
        $partialServer = Server::factory()->create(['last_successful_sync_at' => null]);
        $partial = SyncRun::factory()->for($partialServer)->create([
            'status' => SyncRunStatus::Running,
            'completed_at' => null,
            'errors_count' => 0,
        ]);

        $recorder = app(SyncRunRecorder::class);
        $recorder->finalize($successful->id);
        $recorder->finalize($partial->id, unexpectedBatchFailure: true);

        $successful->refresh();
        $partial->refresh();
        $this->assertSame(SyncRunStatus::Successful, $successful->status);
        $this->assertNotNull($successful->completed_at);
        $this->assertNotNull($successfulServer->fresh()->last_synced_at);
        $this->assertNotNull($successfulServer->fresh()->last_successful_sync_at);
        $this->assertSame(SyncRunStatus::Partial, $partial->status);
        $this->assertSame(1, $partial->errors_count);
        $this->assertNotNull($partialServer->fresh()->last_synced_at);
        $this->assertNull($partialServer->fresh()->last_successful_sync_at);
    }

    public function test_counter_increments_accumulate_without_overwriting_other_child_results(): void
    {
        $run = SyncRun::factory()->create([
            'status' => SyncRunStatus::Running,
            'completed_at' => null,
            'domains_found' => 0,
            'domains_created' => 0,
            'domains_updated' => 0,
        ]);
        $recorder = app(SyncRunRecorder::class);

        $recorder->increment($run->id, ['domains_found' => 2, 'domains_created' => 2]);
        $recorder->increment($run->id, ['domains_found' => 3, 'domains_updated' => 1]);

        $run->refresh();
        $this->assertSame(5, $run->domains_found);
        $this->assertSame(2, $run->domains_created);
        $this->assertSame(1, $run->domains_updated);
    }

    public function test_error_recording_accumulates_count_and_summaries_without_losing_prior_errors(): void
    {
        $run = SyncRun::factory()->create([
            'status' => SyncRunStatus::Running,
            'completed_at' => null,
            'errors_count' => 0,
            'error_summary' => null,
        ]);
        $recorder = app(SyncRunRecorder::class);

        $recorder->recordError($run->id, 'Domain inventory failed for alpha.');
        $recorder->recordError($run->id, 'Domain inventory failed for bravo.');

        $run->refresh();
        $this->assertSame(2, $run->errors_count);
        $this->assertStringContainsString('alpha', $run->error_summary ?? '');
        $this->assertStringContainsString('bravo', $run->error_summary ?? '');
    }

    public function test_account_and_disk_failures_never_expose_the_server_token_or_log_it(): void
    {
        Bus::fake();
        Log::spy();
        $accountFailureServer = Server::factory()->create(['api_token' => 'TASK8_SUPER_SECRET_TOKEN']);
        $this->whm->accountsException = $this->apiException($accountFailureServer, 'listaccts');

        $accountJob = new SyncServerInventory($accountFailureServer->id);
        $this->runJob($accountJob);
        $accountRun = SyncRun::whereBelongsTo($accountFailureServer)->sole();

        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', serialize($accountJob));
        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', $accountRun->error_summary ?? '');
        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', $this->whm->accountsException->getMessage());

        $diskFailureServer = Server::factory()->create(['api_token' => 'TASK8_SUPER_SECRET_TOKEN']);
        $this->whm->accountsException = null;
        $this->whm->accounts = [$this->account('alpha')];
        $this->whm->diskUsageException = $this->apiException($diskFailureServer, 'get_disk_usage');
        $diskJob = new SyncServerInventory($diskFailureServer->id);
        $this->runJob($diskJob);
        $diskRun = SyncRun::whereBelongsTo($diskFailureServer)->sole();

        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', $diskRun->error_summary ?? '');
        $this->assertStringNotContainsString('TASK8_SUPER_SECRET_TOKEN', $this->whm->diskUsageException->getMessage());
        Log::shouldNotHaveReceived('error');
    }

    private function runJob(SyncServerInventory $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    private function account(string $username, bool $suspended = false, string $package = 'business'): WhmAccountData
    {
        return new WhmAccountData(
            username: $username,
            primaryDomain: $username.'.example.invalid',
            homeDirectory: '/home/'.$username,
            package: $package,
            owner: 'root',
            suspended: $suspended,
            suspensionReason: $suspended ? 'billing' : null,
            metadata: ['plan' => $package],
        );
    }

    private function apiException(Server $server, string $function): WhmApiException
    {
        return new WhmApiException('remote_failure', [
            'server_id' => $server->id,
            'server_hostname' => $server->hostname,
            'function' => $function,
        ]);
    }
}
