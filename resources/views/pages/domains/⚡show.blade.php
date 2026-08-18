<?php

use App\Enums\IssueSeverity;
use App\Models\Domain;
use App\Models\DomainCheck;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Domain monitoring')] class extends Component {
    use WithPagination;

    #[Locked]
    public int $domainId;

    public function mount(Domain $domain): void
    {
        $this->domainId = $domain->id;
    }

    /** @return array{domain: Domain} */
    public function with(): array
    {
        return ['domain' => Domain::query()
            ->with([
                'cpanelAccount.server', 'latestHttpCheck', 'latestDnsCheck', 'latestTlsCheck',
                'issues' => fn ($query) => $query->whereNull('resolved_at')->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")->latest('last_detected_at'),
            ])->findOrFail($this->domainId)];
    }

    /** @return LengthAwarePaginator<int, DomainCheck> */
    #[Computed]
    public function checks(): LengthAwarePaginator
    {
        return DomainCheck::query()->where('domain_id', $this->domainId)->latest('checked_at')->latest('id')->paginate(30);
    }
}; ?>

@php
    $http = $domain->latestHttpCheck;
    $dns = $domain->latestDnsCheck;
    $tls = $domain->latestTlsCheck;
@endphp
<div class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8 sm:px-6 lg:px-8">
    <header class="space-y-2">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('domains.index')" wire:navigate>{{ __('Domains') }}</flux:button>
        <div class="flex flex-wrap items-center gap-3"><flux:heading size="xl">{{ $domain->domain }}</flux:heading><flux:badge color="zinc">{{ $domain->removed_at || ! $domain->is_active ? __('Removed') : __('Current') }}</flux:badge></div>
        <flux:text>{{ ucfirst($domain->type->value) }} · {{ ucfirst($domain->classification->value) }} · {{ $domain->monitoring_enabled ? __('Monitoring enabled') : __('Monitoring disabled') }}</flux:text>
    </header>

    <section class="grid gap-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Domain identity') }}">
        <div><flux:text>{{ __('Server') }}</flux:text><div class="font-medium">{{ $domain->cpanelAccount?->server?->name ?? '—' }}</div></div>
        <div><flux:text>{{ __('cPanel account') }}</flux:text><div class="font-medium">{{ $domain->cpanelAccount?->username ?? '—' }}</div></div>
        <div><flux:text>{{ __('Document root') }}</flux:text><div class="break-all font-medium">{{ $domain->document_root ?? '—' }}</div></div>
        <div><flux:text>{{ __('Last seen') }}</flux:text><div class="font-medium">{{ $domain->last_seen_at?->format('j M Y, H:i') ?? '—' }}</div></div>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="lg">{{ __('Active issues') }}</flux:heading>
        @if ($domain->issues->isEmpty())<flux:text class="mt-3">{{ __('No active issues') }}</flux:text>@else<div class="mt-3 grid gap-3">@foreach ($domain->issues as $issue)<div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"><flux:badge :color="$issue->severity === IssueSeverity::Critical ? 'red' : 'amber'">{{ ucfirst($issue->severity->value) }}</flux:badge><div class="mt-2 font-medium">{{ $issue->title }}</div><div class="text-sm text-zinc-500">{{ $issue->details }}</div></div>@endforeach</div>@endif
    </section>

    <section class="grid gap-4 lg:grid-cols-3" aria-label="{{ __('Latest observations') }}">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading>{{ __('HTTP') }}</flux:heading>@if (! $http)<flux:text class="mt-3">{{ __('Not checked yet') }}</flux:text>@else<div class="mt-3 space-y-1 text-sm"><div>{{ $http->successful ? __('HTTP :status', ['status' => $http->http_status]) : __('Failed') }}</div><div>{{ $http->checked_at->format('j M Y, H:i') }}</div>@if ($http->response_time_ms !== null)<div>{{ number_format($http->response_time_ms) }} ms</div>@endif @if ($http->final_url)<div class="break-all">{{ $http->final_url }}</div>@endif @if ($http->redirect_count !== null)<div>{{ trans_choice(':count redirect|:count redirects', $http->redirect_count, ['count' => $http->redirect_count]) }}</div>@endif @if (! $http->successful)<div>{{ $http->error_type }} · {{ $http->error_message }}</div>@endif</div>@endif</div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading>{{ __('DNS') }}</flux:heading>@if (! $dns)<flux:text class="mt-3">{{ __('Not checked yet') }}</flux:text>@else<div class="mt-3 space-y-2 text-sm"><div>{{ $dns->successful ? __('Resolved') : __('Failed') }} · {{ $dns->checked_at->format('j M Y, H:i') }}</div>@foreach (['a' => 'A', 'aaaa' => 'AAAA', 'cname' => 'CNAME'] as $key => $label)<div><span class="font-medium">{{ $label }}:</span> {{ implode(', ', $dns->resolved_ips[$key] ?? []) ?: '—' }}</div>@endforeach @if (! $dns->successful)<div>{{ $dns->error_type }} · {{ $dns->error_message }}</div>@endif</div>@endif</div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading>{{ __('TLS') }}</flux:heading>@if (! $tls)<flux:text class="mt-3">{{ __('Not checked yet') }}</flux:text>@else<div class="mt-3 space-y-1 text-sm"><div>{{ $tls->successful && $tls->ssl_valid ? __('Valid') : __('Invalid / Failed') }}</div><div>{{ $tls->checked_at->format('j M Y, H:i') }}</div>@if ($tls->ssl_expires_at)<div>{{ __('Expires :date', ['date' => $tls->ssl_expires_at->format('j M Y, H:i')]) }}</div>@endif @if ($tls->ssl_days_remaining !== null)<div>{{ trans_choice(':count day remaining|:count days remaining', $tls->ssl_days_remaining, ['count' => $tls->ssl_days_remaining]) }}</div>@endif @if (! $tls->successful)<div>{{ $tls->error_type }} · {{ $tls->error_message }}</div>@endif</div>@endif</div>
    </section>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"><div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700"><flux:heading size="lg">{{ __('Recent check history') }}</flux:heading></div>
        @if ($this->checks->isEmpty())<div class="px-5 py-8 text-center"><flux:text>{{ __('No monitoring checks recorded yet.') }}</flux:text></div>@else<div class="overflow-x-auto"><table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700"><thead><tr><th scope="col" class="px-4 py-3 text-left">{{ __('Checked') }}</th><th scope="col" class="px-4 py-3 text-left">{{ __('Type') }}</th><th scope="col" class="px-4 py-3 text-left">{{ __('Outcome') }}</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->checks as $check)<tr wire:key="domain-check-{{ $check->id }}"><td class="px-4 py-3">{{ $check->checked_at->format('j M Y, H:i') }}</td><td class="px-4 py-3 font-medium">{{ strtoupper($check->check_type) }}</td><td class="px-4 py-3">@if (! $check->successful){{ __('Failed · :type', ['type' => $check->error_type]) }}@elseif ($check->check_type === 'http'){{ __('HTTP :status · :time ms', ['status' => $check->http_status, 'time' => number_format($check->response_time_ms ?? 0)]) }}@elseif ($check->check_type === 'dns'){{ __('Resolved') }}@elseif ($check->ssl_valid){{ __('Valid · expires in :days days', ['days' => $check->ssl_days_remaining]) }}@else{{ __('Invalid / Failed · :type', ['type' => $check->error_type ?? 'tls_invalid']) }}@endif</td></tr>@endforeach</tbody></table></div>@if ($this->checks->hasPages())<div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">{{ $this->checks->links() }}</div>@endif @endif
    </section>
</div>
