<?php

namespace Tests\Feature\Database;

use App\Enums\DomainClassification;
use App\Enums\DomainClassificationSource;
use App\Enums\DomainType;
use App\Enums\IssueSeverity;
use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainCheck;
use App\Models\Issue;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\SyncRun;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstateModelTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_server_relationships_return_their_estate_records(): void
    {
        $server = Server::factory()->create();
        $accounts = CpanelAccount::factory()->count(2)->for($server)->create();
        $checks = ServerCheck::factory()->count(2)->for($server)->create();
        $syncRuns = SyncRun::factory()->count(2)->for($server)->create();

        $this->assertEqualsCanonicalizing($accounts->modelKeys(), $server->cpanelAccounts->modelKeys());
        $this->assertEqualsCanonicalizing($checks->modelKeys(), $server->serverChecks->modelKeys());
        $this->assertEqualsCanonicalizing($syncRuns->modelKeys(), $server->syncRuns->modelKeys());
    }

    public function test_account_and_domain_relationships_form_a_complete_hierarchy(): void
    {
        $server = Server::factory()->create();
        $account = CpanelAccount::factory()->for($server)->create();
        $parent = Domain::factory()->for($account)->create();
        $child = Domain::factory()->for($account)->for($parent, 'parent')->create();
        $check = DomainCheck::factory()->for($child)->create();

        $this->assertTrue($account->server->is($server));
        $this->assertTrue($server->cpanelAccounts->contains($account));
        $this->assertTrue($parent->cpanelAccount->is($account));
        $this->assertTrue($account->domains->contains($parent));
        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->contains($child));
        $this->assertTrue($child->domainChecks->contains($check));
        $this->assertTrue($check->domain->is($child));
    }

    public function test_issue_with_only_a_server_target_is_accepted(): void
    {
        $server = Server::factory()->create();
        $issue = Issue::factory()->forServer($server)->create();

        $this->assertSame($server->id, $issue->server_id);
        $this->assertNull($issue->cpanel_account_id);
        $this->assertNull($issue->domain_id);
        $this->assertTrue($issue->server->is($server));
        $this->assertTrue($server->issues->contains($issue));
    }

    public function test_issue_with_only_an_account_target_is_accepted(): void
    {
        $account = CpanelAccount::factory()->create();
        $issue = Issue::factory()->forAccount($account)->create();

        $this->assertNull($issue->server_id);
        $this->assertSame($account->id, $issue->cpanel_account_id);
        $this->assertNull($issue->domain_id);
        $this->assertTrue($issue->cpanelAccount->is($account));
        $this->assertTrue($account->issues->contains($issue));
    }

    public function test_issue_with_only_a_domain_target_is_accepted(): void
    {
        $domain = Domain::factory()->create();
        $issue = Issue::factory()->forDomain($domain)->create();

        $this->assertNull($issue->server_id);
        $this->assertNull($issue->cpanel_account_id);
        $this->assertSame($domain->id, $issue->domain_id);
        $this->assertTrue($issue->domain->is($domain));
        $this->assertTrue($domain->issues->contains($issue));
    }

    public function test_issue_without_a_target_is_rejected(): void
    {
        $this->assertIssueTargetRejected([
            'server_id' => null,
            'cpanel_account_id' => null,
            'domain_id' => null,
        ]);
    }

    public function test_issue_with_server_and_account_targets_is_rejected(): void
    {
        $server = Server::factory()->create();
        $account = CpanelAccount::factory()->for($server)->create();

        $this->assertIssueTargetRejected([
            'server_id' => $server->id,
            'cpanel_account_id' => $account->id,
            'domain_id' => null,
        ]);
    }

    public function test_issue_with_server_and_domain_targets_is_rejected(): void
    {
        $server = Server::factory()->create();
        $domain = Domain::factory()->create();

        $this->assertIssueTargetRejected([
            'server_id' => $server->id,
            'cpanel_account_id' => null,
            'domain_id' => $domain->id,
        ]);
    }

    public function test_issue_with_account_and_domain_targets_is_rejected(): void
    {
        $account = CpanelAccount::factory()->create();
        $domain = Domain::factory()->for($account)->create();

        $this->assertIssueTargetRejected([
            'server_id' => null,
            'cpanel_account_id' => $account->id,
            'domain_id' => $domain->id,
        ]);
    }

    public function test_issue_with_all_three_targets_is_rejected(): void
    {
        $server = Server::factory()->create();
        $account = CpanelAccount::factory()->for($server)->create();
        $domain = Domain::factory()->for($account)->create();

        $this->assertIssueTargetRejected([
            'server_id' => $server->id,
            'cpanel_account_id' => $account->id,
            'domain_id' => $domain->id,
        ]);
    }

    public function test_backed_enum_attributes_are_cast_to_enum_instances(): void
    {
        $domain = Domain::factory()->create([
            'type' => DomainType::Alias,
            'classification' => DomainClassification::Service,
            'classification_source' => DomainClassificationSource::Manual,
        ]);
        $syncRun = SyncRun::factory()->create([
            'type' => SyncRunType::Inventory,
            'status' => SyncRunStatus::Partial,
        ]);
        $issue = Issue::factory()->create(['severity' => IssueSeverity::Critical]);

        $this->assertSame(DomainType::Alias, $domain->type);
        $this->assertSame(DomainClassification::Service, $domain->classification);
        $this->assertSame(DomainClassificationSource::Manual, $domain->classification_source);
        $this->assertSame(SyncRunType::Inventory, $syncRun->type);
        $this->assertSame(SyncRunStatus::Partial, $syncRun->status);
        $this->assertSame(IssueSeverity::Critical, $issue->severity);
    }

    public function test_server_api_token_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $server = Server::factory()->create(['api_token' => 'fixture-token-not-a-real-secret']);
        $storedToken = DB::table($server->getTable())->where('id', $server->id)->value('api_token');

        $this->assertNotSame('fixture-token-not-a-real-secret', $storedToken);
        $this->assertSame('fixture-token-not-a-real-secret', $server->fresh()->api_token);
        $this->assertArrayNotHasKey('api_token', $server->toArray());
    }

    public function test_duplicate_server_hostname_is_rejected_by_the_database(): void
    {
        Server::factory()->create(['hostname' => 'host-one.invalid']);

        $this->expectException(QueryException::class);

        Server::factory()->create(['hostname' => 'host-one.invalid']);
    }

    public function test_duplicate_username_on_the_same_server_is_rejected_by_the_database(): void
    {
        $server = Server::factory()->create();
        CpanelAccount::factory()->for($server)->create(['username' => 'estateuser']);

        $this->expectException(QueryException::class);

        CpanelAccount::factory()->for($server)->create(['username' => 'estateuser']);
    }

    public function test_same_username_is_allowed_on_different_servers(): void
    {
        CpanelAccount::factory()->create(['username' => 'estateuser']);
        $account = CpanelAccount::factory()->create(['username' => 'estateuser']);

        $this->assertModelExists($account);
    }

    public function test_duplicate_domain_on_the_same_account_is_rejected_by_the_database(): void
    {
        $account = CpanelAccount::factory()->create();
        Domain::factory()->for($account)->create(['domain' => 'site-one.invalid']);

        $this->expectException(QueryException::class);

        Domain::factory()->for($account)->create(['domain' => 'site-one.invalid']);
    }

    public function test_same_domain_is_allowed_on_different_accounts(): void
    {
        Domain::factory()->create(['domain' => 'site-one.invalid']);
        $domain = Domain::factory()->create(['domain' => 'site-one.invalid']);

        $this->assertModelExists($domain);
    }

    public function test_json_fields_are_cast_to_arrays(): void
    {
        $account = CpanelAccount::factory()->create(['metadata' => ['source' => 'fixture']]);
        $domain = Domain::factory()->create(['metadata' => ['redirected' => false]]);
        $domainCheck = DomainCheck::factory()->create(['resolved_ips' => ['192.0.2.10']]);
        $serverCheck = ServerCheck::factory()->create([
            'partitions' => [['mount' => '/fixture', 'used_percent' => 42]],
        ]);

        $this->assertSame(['source' => 'fixture'], $account->metadata);
        $this->assertSame(['redirected' => false], $domain->metadata);
        $this->assertSame(['192.0.2.10'], $domainCheck->resolved_ips);
        $this->assertSame([['mount' => '/fixture', 'used_percent' => 42]], $serverCheck->partitions);
    }

    public function test_boolean_and_timestamp_fields_are_cast_to_expected_types(): void
    {
        $server = Server::factory()->create();
        $account = CpanelAccount::factory()->removed()->create();
        $domain = Domain::factory()->removed()->create();
        $domainCheck = DomainCheck::factory()->create();
        $serverCheck = ServerCheck::factory()->create();
        $syncRun = SyncRun::factory()->create();
        $issue = Issue::factory()->resolved()->create();

        $this->assertIsBool($server->enabled);
        $this->assertInstanceOf(DateTimeInterface::class, $server->last_synced_at);
        $this->assertInstanceOf(DateTimeInterface::class, $server->last_successful_sync_at);
        $this->assertIsBool($account->suspended);
        $this->assertInstanceOf(DateTimeInterface::class, $account->discovered_at);
        $this->assertInstanceOf(DateTimeInterface::class, $account->last_seen_at);
        $this->assertInstanceOf(DateTimeInterface::class, $account->removed_at);
        $this->assertIsBool($domain->monitoring_enabled);
        $this->assertIsBool($domain->is_active);
        $this->assertInstanceOf(DateTimeInterface::class, $domain->discovered_at);
        $this->assertInstanceOf(DateTimeInterface::class, $domain->last_seen_at);
        $this->assertInstanceOf(DateTimeInterface::class, $domain->removed_at);
        $this->assertIsBool($domainCheck->successful);
        $this->assertInstanceOf(DateTimeInterface::class, $domainCheck->checked_at);
        $this->assertInstanceOf(DateTimeInterface::class, $domainCheck->ssl_expires_at);
        $this->assertIsBool($serverCheck->reachable);
        $this->assertInstanceOf(DateTimeInterface::class, $serverCheck->checked_at);
        $this->assertInstanceOf(DateTimeInterface::class, $syncRun->started_at);
        $this->assertInstanceOf(DateTimeInterface::class, $syncRun->completed_at);
        $this->assertInstanceOf(DateTimeInterface::class, $issue->first_detected_at);
        $this->assertInstanceOf(DateTimeInterface::class, $issue->last_detected_at);
        $this->assertInstanceOf(DateTimeInterface::class, $issue->resolved_at);
    }

    /** @param array{server_id: int|null, cpanel_account_id: int|null, domain_id: int|null} $targets */
    private function assertIssueTargetRejected(array $targets): void
    {
        $this->expectException(QueryException::class);

        Issue::factory()->create($targets);
    }
}
