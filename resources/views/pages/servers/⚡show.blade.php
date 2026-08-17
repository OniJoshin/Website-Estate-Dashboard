<?php

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use App\Jobs\Inventory\SyncServerInventory;
use App\Models\Server;
use App\Models\SyncRun;
use App\Support\InventoryFreshness;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Server inventory')] class extends Component {
    use WithPagination;

    #[Locked]
    public int $serverId;

    public function mount(Server $server): void
    {
        $this->serverId = $server->id;
    }

    /** @return array{server: Server, freshness: string} */
    public function with(): array
    {
        $server = $this->server();

        return [
            'server' => $server,
            'freshness' => app(InventoryFreshness::class)->status(
                $server->last_successful_sync_at,
                (bool) $server->inventory_sync_running,
            ),
        ];
    }

    /** @return LengthAwarePaginator<int, SyncRun> */
    #[Computed]
    public function syncRuns(): LengthAwarePaginator
    {
        return SyncRun::query()
            ->where('server_id', $this->serverId)
            ->where('type', SyncRunType::Inventory)
            ->latest('started_at')
            ->latest('id')
            ->paginate(20);
    }

    public function syncNow(): void
    {
        Gate::authorize('admin');

        $server = Server::query()->findOrFail($this->serverId);

        if (! $server->enabled) {
            Flux::toast(variant: 'warning', text: __('Disabled servers cannot be synchronized.'));

            return;
        }

        $alreadyRunning = $server->syncRuns()
            ->where('type', SyncRunType::Inventory)
            ->where('status', SyncRunStatus::Running)
            ->whereNull('completed_at')
            ->exists();

        if ($alreadyRunning) {
            Flux::toast(variant: 'warning', text: __('An inventory sync is already running.'));

            return;
        }

        SyncServerInventory::dispatch($server->id);

        Flux::toast(variant: 'success', text: __('Inventory sync queued'));
    }

    private function server(): Server
    {
        return Server::query()
            ->select(['id', 'name', 'hostname', 'whm_port', 'enabled', 'last_synced_at', 'last_successful_sync_at'])
            ->with('latestSyncRun')
            ->withExists([
                'syncRuns as inventory_sync_running' => fn ($query) => $query
                    ->where('type', SyncRunType::Inventory)
                    ->where('status', SyncRunStatus::Running)
                    ->whereNull('completed_at'),
            ])
            ->withCount([
                'cpanelAccounts as current_cpanel_accounts_count' => fn ($query) => $query->whereNull('removed_at'),
                'domains as current_domains_count' => fn ($query) => $query->whereNull('domains.removed_at'),
                'domains as current_monitored_domains_count' => fn ($query) => $query
                    ->whereNull('domains.removed_at')
                    ->where('domains.monitoring_enabled', true),
            ])
            ->findOrFail($this->serverId);
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div class="space-y-2">
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('servers.index')" wire:navigate>{{ __('Servers') }}</flux:button>
            <flux:heading size="xl">{{ $server->name }}</flux:heading>
            <flux:text>{{ $server->hostname }}:{{ $server->whm_port }}</flux:text>
            <div class="flex flex-wrap gap-2">
                <flux:badge :color="$server->enabled ? 'green' : 'zinc'">{{ $server->enabled ? __('Enabled') : __('Disabled') }}</flux:badge>
                <flux:badge color="zinc">{{ $freshness }}</flux:badge>
                @if ($server->latestSyncRun)
                    <flux:badge color="zinc">{{ ucfirst($server->latestSyncRun->status->value) }}</flux:badge>
                @endif
            </div>
        </div>

        @can('admin')
            <flux:button variant="primary" icon="arrow-path" wire:click="syncNow" :disabled="! $server->enabled || $server->inventory_sync_running">
                {{ __('Sync now') }}
            </flux:button>
        @endcan
    </div>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Estate totals') }}">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text>{{ __('Current accounts') }}</flux:text>
            <div data-test="current-accounts" data-count="{{ $server->current_cpanel_accounts_count }}" class="mt-2 text-2xl font-semibold tabular-nums">{{ $server->current_cpanel_accounts_count }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text>{{ __('Current domains') }}</flux:text>
            <div data-test="current-domains" data-count="{{ $server->current_domains_count }}" class="mt-2 text-2xl font-semibold tabular-nums">{{ $server->current_domains_count }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text>{{ __('Monitored domains') }}</flux:text>
            <div data-test="current-monitored-domains" data-count="{{ $server->current_monitored_domains_count }}" class="mt-2 text-2xl font-semibold tabular-nums">{{ $server->current_monitored_domains_count }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:text>{{ __('Last successful sync') }}</flux:text>
            <div class="mt-2 font-medium">
                {{ $server->last_successful_sync_at?->diffForHumans() ?? __('Never') }}
            </div>
        </div>
    </section>

    <section class="grid gap-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-3">
        <div>
            <flux:text>{{ __('Current sync status') }}</flux:text>
            <div class="mt-1 font-medium">{{ $server->latestSyncRun ? ucfirst($server->latestSyncRun->status->value) : __('No runs') }}</div>
        </div>
        <div>
            <flux:text>{{ __('Last attempted sync') }}</flux:text>
            <div class="mt-1 font-medium">{{ $server->latestSyncRun?->started_at?->diffForHumans() ?? __('Never') }}</div>
        </div>
        <div>
            <flux:text>{{ __('Sync running') }}</flux:text>
            <div class="mt-1 font-medium">{{ $server->inventory_sync_running ? __('Yes') : __('No') }}</div>
        </div>
    </section>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Recent inventory syncs') }}</flux:heading>
        </div>

        @if ($this->syncRuns->isEmpty())
            <div class="px-6 py-10 text-center"><flux:text>{{ __('No inventory syncs have run yet.') }}</flux:text></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/70">
                        <tr>
                            @foreach ([__('Started'), __('Completed'), __('Status'), __('Accounts F/C/U/R'), __('Domains F/C/U/R'), __('Errors')] as $heading)
                                <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->syncRuns as $syncRun)
                            <tr wire:key="sync-run-{{ $syncRun->id }}">
                                <td class="whitespace-nowrap px-4 py-3">{{ $syncRun->started_at->toDayDateTimeString() }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $syncRun->completed_at?->toDayDateTimeString() ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3"><flux:badge size="sm" color="zinc">{{ ucfirst($syncRun->status->value) }}</flux:badge></td>
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                                    <span data-test="accounts-found" data-count="{{ $syncRun->accounts_found }}">{{ $syncRun->accounts_found }}</span> /
                                    {{ $syncRun->accounts_created }} / {{ $syncRun->accounts_updated }} / {{ $syncRun->accounts_removed }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                                    {{ $syncRun->domains_found }} / {{ $syncRun->domains_created }} / {{ $syncRun->domains_updated }} /
                                    <span data-test="domains-removed" data-count="{{ $syncRun->domains_removed }}">{{ $syncRun->domains_removed }}</span>
                                </td>
                                <td class="min-w-48 px-4 py-3">
                                    <div class="tabular-nums">{{ $syncRun->errors_count }}</div>
                                    @if ($syncRun->error_summary)
                                        <details class="mt-1 max-w-md">
                                            <summary class="cursor-pointer text-sm underline">{{ __('View error summary') }}</summary>
                                            <p class="mt-2 whitespace-pre-wrap break-words text-zinc-600 dark:text-zinc-300">{{ $syncRun->error_summary }}</p>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($this->syncRuns->hasPages())
                <div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">{{ $this->syncRuns->links() }}</div>
            @endif
        @endif
    </section>
</div>
