<?php

use App\Models\Server;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Servers')] class extends Component {
    /** @return array{servers: Collection<int, Server>} */
    public function with(): array
    {
        return [
            'servers' => Server::query()
                ->withCount([
                    'cpanelAccounts as active_cpanel_accounts_count' => fn ($query) => $query->whereNull('removed_at'),
                    'domains as active_monitored_domains_count' => fn ($query) => $query
                        ->whereNull('domains.removed_at')
                        ->where('domains.monitoring_enabled', true),
                ])
                ->orderBy('name')
                ->get(),
        ];
    }

    public function toggleEnabled(int $serverId): void
    {
        Gate::authorize('admin');

        $server = Server::query()->findOrFail($serverId);
        $server->update(['enabled' => ! $server->enabled]);

        Flux::toast(variant: 'success', text: __('Server status updated.'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div class="space-y-2">
            <flux:heading size="xl">{{ __('Servers') }}</flux:heading>
            <flux:text>{{ __('Configured WHM servers and locally stored estate totals.') }}</flux:text>
        </div>

        @can('admin')
            <flux:button variant="primary" icon="plus" :href="route('admin.servers.create')" wire:navigate>
                {{ __('Add WHM server') }}
            </flux:button>
        @endcan
    </div>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        @if ($servers->isEmpty())
            <div class="flex flex-col items-center gap-3 px-6 py-12 text-center">
                <flux:heading size="lg">{{ __('No servers configured') }}</flux:heading>
                <flux:text>{{ __('An administrator can add the first read-only WHM connection.') }}</flux:text>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/70">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Server') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Last successful sync') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Accounts') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Monitored domains') }}</th>
                            @can('admin')
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($servers as $server)
                            <tr wire:key="server-{{ $server->id }}">
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $server->name }}</div>
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $server->hostname }}:{{ $server->whm_port }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <flux:badge :color="$server->enabled ? 'green' : 'zinc'">
                                        {{ $server->enabled ? __('Enabled') : __('Disabled') }}
                                    </flux:badge>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-zinc-600 dark:text-zinc-300">
                                    @if ($server->last_successful_sync_at)
                                        <time datetime="{{ $server->last_successful_sync_at->toIso8601String() }}" title="{{ $server->last_successful_sync_at->toDayDateTimeString() }}">
                                            {{ $server->last_successful_sync_at->diffForHumans() }}
                                        </time>
                                    @else
                                        {{ __('Never') }}
                                    @endif
                                </td>
                                <td data-test="active-accounts-{{ $server->id }}" data-count="{{ $server->active_cpanel_accounts_count }}" class="whitespace-nowrap px-6 py-4 text-right tabular-nums text-zinc-700 dark:text-zinc-200">{{ $server->active_cpanel_accounts_count }}</td>
                                <td data-test="monitored-domains-{{ $server->id }}" data-count="{{ $server->active_monitored_domains_count }}" class="whitespace-nowrap px-6 py-4 text-right tabular-nums text-zinc-700 dark:text-zinc-200">{{ $server->active_monitored_domains_count }}</td>
                                @can('admin')
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <div class="flex justify-end gap-2">
                                            <flux:button size="sm" :href="route('admin.servers.edit', $server)" wire:navigate>{{ __('Edit') }}</flux:button>
                                            <flux:button size="sm" :variant="$server->enabled ? 'danger' : 'primary'" wire:click="toggleEnabled({{ $server->id }})">
                                                {{ $server->enabled ? __('Disable') : __('Enable') }}
                                            </flux:button>
                                        </div>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
