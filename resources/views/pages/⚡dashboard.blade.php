<?php

use App\Enums\IssueSeverity;
use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Issue;
use App\Models\Server;
use App\Support\InventoryFreshness;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /** @return array<string, mixed> */
    public function with(): array
    {
        $activeIssues = Issue::query()
            ->whereNull('resolved_at')
            ->with(['server', 'cpanelAccount.server', 'domain.cpanelAccount.server'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")
            ->latest('last_detected_at')
            ->latest('id')
            ->limit(15)
            ->get();

        $servers = Server::query()
            ->with(['latestServerCheck', 'latestSyncRun'])
            ->withExists([
                'syncRuns as inventory_sync_running' => fn ($query) => $query
                    ->where('type', SyncRunType::Inventory)
                    ->where('status', SyncRunStatus::Running)
                    ->whereNull('completed_at'),
            ])
            ->withCount([
                'cpanelAccounts as current_accounts_count' => fn ($query) => $query->whereNull('removed_at'),
                'domains as current_domains_count' => fn ($query) => $query
                    ->whereNull('domains.removed_at')
                    ->where('domains.is_active', true),
                'issues as active_critical_issues_count' => fn ($query) => $query
                    ->whereNull('resolved_at')->where('severity', IssueSeverity::Critical),
                'issues as active_warning_issues_count' => fn ($query) => $query
                    ->whereNull('resolved_at')->where('severity', IssueSeverity::Warning),
            ])
            ->orderBy('name')
            ->get();

        return [
            'metrics' => [
                'enabled_servers' => Server::query()->where('enabled', true)->count(),
                'current_accounts' => CpanelAccount::query()->whereNull('removed_at')->count(),
                'current_domains' => Domain::query()->whereNull('removed_at')->where('is_active', true)->count(),
                'monitored_domains' => Domain::query()->whereNull('removed_at')->where('is_active', true)->where('monitoring_enabled', true)->count(),
                'critical_issues' => Issue::query()->whereNull('resolved_at')->where('severity', IssueSeverity::Critical)->count(),
                'warning_issues' => Issue::query()->whereNull('resolved_at')->where('severity', IssueSeverity::Warning)->count(),
                'stale_inventory' => $servers->filter(fn (Server $server) => in_array(
                    app(InventoryFreshness::class)->status($server->last_successful_sync_at, (bool) $server->inventory_sync_running),
                    ['Never synced', 'Stale'],
                    true,
                ))->count(),
            ],
            'activeIssues' => $activeIssues,
            'servers' => $servers,
            'inventoryFreshness' => app(InventoryFreshness::class),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
    <header class="space-y-2">
        <flux:heading size="xl">{{ __('Estate overview') }}</flux:heading>
        <flux:text>{{ __('Current local inventory and monitoring state. No remote checks run while viewing this page.') }}</flux:text>
    </header>

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Estate summary') }}">
        @foreach ([
            ['Enabled servers', 'enabled-servers', $metrics['enabled_servers']],
            ['Current accounts', 'current-accounts', $metrics['current_accounts']],
            ['Current domains', 'current-domains', $metrics['current_domains']],
            ['Monitored domains', 'monitored-domains', $metrics['monitored_domains']],
            ['Active critical issues', 'critical-issues', $metrics['critical_issues']],
            ['Active warning issues', 'warning-issues', $metrics['warning_issues']],
            ['Stale or never synced', 'stale-inventory', $metrics['stale_inventory']],
        ] as [$label, $test, $count])
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text>{{ __($label) }}</flux:text>
                <div data-test="{{ $test }}" data-count="{{ $count }}" class="mt-1 text-2xl font-semibold tabular-nums">{{ $count }}</div>
            </div>
        @endforeach
    </section>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Active issues') }}</flux:heading>
            <flux:button size="sm" :href="route('issues.index')" wire:navigate>{{ __('View all issues') }}</flux:button>
        </div>
        @if ($activeIssues->isEmpty())
            <div class="px-5 py-8 text-center"><flux:text>{{ __('No active issues') }}</flux:text></div>
        @else
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead><tr>@foreach ([__('Severity'), __('Issue'), __('Target'), __('Server'), __('Details'), __('First detected'), __('Last detected')] as $heading)<th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($activeIssues as $issue)
                    @php
                        $target = $issue->domain?->domain ?? $issue->cpanelAccount?->username ?? $issue->server?->name;
                        $contextServer = $issue->server ?? $issue->cpanelAccount?->server ?? $issue->domain?->cpanelAccount?->server;
                    @endphp
                    <tr wire:key="dashboard-issue-{{ $issue->id }}">
                        <td class="px-4 py-3"><flux:badge :color="$issue->severity === IssueSeverity::Critical ? 'red' : 'amber'">{{ ucfirst($issue->severity->value) }}</flux:badge></td>
                        <td class="px-4 py-3 font-medium">{{ $issue->title }}</td>
                        <td class="px-4 py-3">{{ $target }}</td>
                        <td class="px-4 py-3">{{ $contextServer?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $issue->details ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $issue->first_detected_at->format('j M Y, H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $issue->last_detected_at->format('j M Y, H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700"><flux:heading size="lg">{{ __('Servers') }}</flux:heading></div>
        @if ($servers->isEmpty())
            <div class="px-5 py-8 text-center"><flux:text>{{ __('No servers configured') }}</flux:text></div>
        @else
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead><tr>@foreach ([__('Server'), __('Status'), __('Inventory'), __('Latest health'), __('Load 1m / 5m / 15m'), __('Issues C / W'), __('Accounts'), __('Domains')] as $heading)<th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($servers as $server)
                    <tr wire:key="dashboard-server-{{ $server->id }}">
                        <td class="px-4 py-3"><a class="font-medium hover:underline" href="{{ route('servers.show', $server) }}" wire:navigate>{{ $server->name }}</a><div class="text-zinc-500">{{ $server->hostname }}</div></td>
                        <td class="px-4 py-3">{{ $server->enabled ? __('Enabled') : __('Disabled') }}</td>
                        <td class="px-4 py-3">{{ $inventoryFreshness->status($server->last_successful_sync_at, (bool) $server->inventory_sync_running) }}</td>
                        <td class="px-4 py-3">{{ $server->latestServerCheck ? ($server->latestServerCheck->reachable ? __('Reachable') : __('Health unavailable')) : __('Not checked yet') }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $server->latestServerCheck?->reachable ? implode(' / ', [$server->latestServerCheck->load_1m ?? '—', $server->latestServerCheck->load_5m ?? '—', $server->latestServerCheck->load_15m ?? '—']) : '—' }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $server->active_critical_issues_count }} / {{ $server->active_warning_issues_count }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $server->current_accounts_count }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $server->current_domains_count }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</div>
