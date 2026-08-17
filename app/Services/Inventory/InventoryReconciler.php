<?php

namespace App\Services\Inventory;

use App\Data\Inventory\ReconciliationOutcome;
use App\Data\Whm\WhmAccountData;
use App\Data\Whm\WhmDomainInventory;
use App\Enums\DomainClassification;
use App\Enums\DomainClassificationSource;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

final class InventoryReconciler
{
    public function __construct(private DomainClassifier $classifier) {}

    /**
     * @param  list<WhmAccountData>  $accounts
     * @param  array<string, array{used_bytes: ?int, limit_bytes: ?int}>  $diskUsage
     */
    public function reconcileAccounts(Server $server, array $accounts, array $diskUsage): ReconciliationOutcome
    {
        $seenAt = now();

        return DB::transaction(function () use ($server, $accounts, $diskUsage, $seenAt): ReconciliationOutcome {
            $existing = CpanelAccount::whereBelongsTo($server)->get()->keyBy('username');
            $seenUsernames = [];
            $currentIds = [];
            $created = 0;
            $updated = 0;

            foreach ($accounts as $source) {
                $seenUsernames[] = $source->username;
                $account = $existing->get($source->username);
                $isNew = $account === null;
                $account ??= new CpanelAccount([
                    'server_id' => $server->id,
                    'username' => $source->username,
                    'discovered_at' => $seenAt,
                ]);

                $account->fill([
                    'primary_domain' => $source->primaryDomain,
                    'home_directory' => $source->homeDirectory,
                    'package' => $source->package,
                    'owner' => $source->owner,
                    'suspended' => $source->suspended,
                    'suspension_reason' => $source->suspensionReason,
                    'metadata' => $source->metadata,
                    'removed_at' => null,
                ]);

                if (array_key_exists($source->username, $diskUsage)) {
                    $account->fill([
                        'disk_used_bytes' => $diskUsage[$source->username]['used_bytes'],
                        'disk_limit_bytes' => $diskUsage[$source->username]['limit_bytes'],
                    ]);
                }

                $meaningfullyChanged = ! $isNew && $account->isDirty([
                    'primary_domain', 'home_directory', 'package', 'owner', 'suspended',
                    'suspension_reason', 'disk_used_bytes', 'disk_limit_bytes', 'metadata', 'removed_at',
                ]);
                $account->last_seen_at = $seenAt;
                $account->save();
                $currentIds[] = $account->id;
                $created += (int) $isNew;
                $updated += (int) $meaningfullyChanged;
            }

            $missingAccounts = CpanelAccount::whereBelongsTo($server)
                ->whereNull('removed_at')
                ->when($seenUsernames !== [], fn ($query) => $query->whereNotIn('username', $seenUsernames))
                ->get();
            $domainsRemoved = 0;

            foreach ($missingAccounts as $missingAccount) {
                $missingAccount->update(['removed_at' => $seenAt]);
                $domains = Domain::whereBelongsTo($missingAccount)
                    ->whereNull('removed_at');
                $domainsRemoved += $domains->count();
                $domains->update(['removed_at' => $seenAt, 'is_active' => false]);
            }

            return new ReconciliationOutcome(
                found: count($accounts),
                created: $created,
                updated: $updated,
                removed: $missingAccounts->count(),
                relatedRemoved: $domainsRemoved,
                currentIds: $currentIds,
            );
        });
    }

    public function reconcileDomains(CpanelAccount $account, WhmDomainInventory $inventory): ReconciliationOutcome
    {
        $seenAt = now();

        return DB::transaction(function () use ($account, $inventory, $seenAt): ReconciliationOutcome {
            $existing = Domain::whereBelongsTo($account)->get()->keyBy('domain');
            $seenDomains = [];
            $current = [];
            $changedIds = [];
            $created = 0;

            foreach ($inventory->domains as $source) {
                $seenDomains[] = $source->domain;
                $domain = $existing->get($source->domain);
                $isNew = $domain === null;
                $classification = $this->classifier->classify($source->type, $source->domain);
                $domain ??= new Domain([
                    'cpanel_account_id' => $account->id,
                    'domain' => $source->domain,
                    'discovered_at' => $seenAt,
                    'classification' => $classification,
                    'classification_source' => DomainClassificationSource::Auto,
                    'monitoring_enabled' => $this->isMonitoredByDefault($classification),
                ]);

                $domain->fill([
                    'type' => $source->type,
                    'document_root' => $source->documentRoot,
                    'metadata' => $source->metadata,
                    'removed_at' => null,
                    'is_active' => true,
                ]);

                if (! $isNew && $domain->getAttribute('classification_source') === DomainClassificationSource::Auto) {
                    $domain->setAttribute('classification', $classification);
                }

                if (! $isNew && $domain->isDirty([
                    'type', 'document_root', 'metadata', 'removed_at', 'is_active', 'classification',
                ])) {
                    $changedIds[$domain->id] = true;
                }

                $domain->last_seen_at = $seenAt;
                $domain->save();
                $current[$source->domain] = $domain;
                $created += (int) $isNew;
            }

            foreach ($inventory->domains as $source) {
                $domain = $current[$source->domain];
                $parentId = $source->parentDomain !== null && $source->parentDomain !== $source->domain
                    ? ($current[$source->parentDomain]->id ?? null)
                    : null;

                if ($domain->parent_domain_id !== $parentId) {
                    $domain->update(['parent_domain_id' => $parentId]);

                    if (! $domain->wasRecentlyCreated) {
                        $changedIds[$domain->id] = true;
                    }
                }
            }

            $missing = Domain::whereBelongsTo($account)
                ->whereNull('removed_at')
                ->when($seenDomains !== [], fn ($query) => $query->whereNotIn('domain', $seenDomains));
            $removed = $missing->count();
            $missing->update(['removed_at' => $seenAt, 'is_active' => false]);

            return new ReconciliationOutcome(
                found: count($inventory->domains),
                created: $created,
                updated: count($changedIds),
                removed: $removed,
                currentIds: array_values(array_map(fn (Domain $domain): int => $domain->id, $current)),
            );
        });
    }

    private function isMonitoredByDefault(DomainClassification $classification): bool
    {
        return in_array($classification, [
            DomainClassification::Website,
            DomainClassification::Development,
            DomainClassification::Unknown,
        ], true);
    }
}
