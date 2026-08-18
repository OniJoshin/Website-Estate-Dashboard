<?php

use App\Enums\DomainClassification;
use App\Enums\IssueSeverity;
use App\Models\Domain;
use App\Models\Server;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Domains')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';
    #[Url]
    public string $serverId = 'all';
    #[Url]
    public string $classification = 'all';
    #[Url]
    public string $monitoring = 'all';
    #[Url]
    public string $estateStatus = 'current';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'serverId', 'classification', 'monitoring', 'estateStatus'], true)) {
            $this->resetPage();
        }
    }

    /** @return LengthAwarePaginator<int, Domain> */
    #[Computed]
    public function domains(): LengthAwarePaginator
    {
        return Domain::query()
            ->with(['cpanelAccount.server', 'latestHttpCheck', 'latestDnsCheck', 'latestTlsCheck'])
            ->withCount([
                'issues as active_critical_issues_count' => fn ($query) => $query->whereNull('resolved_at')->where('severity', IssueSeverity::Critical),
                'issues as active_warning_issues_count' => fn ($query) => $query->whereNull('resolved_at')->where('severity', IssueSeverity::Warning),
            ])
            ->when($this->estateStatus === 'current', fn (Builder $query) => $query->whereNull('removed_at')->where('is_active', true))
            ->when($this->estateStatus === 'removed', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNotNull('removed_at')->orWhere('is_active', false)))
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.trim($this->search).'%';
                $query->where(fn (Builder $query) => $query->where('domain', 'like', $search)
                    ->orWhereHas('cpanelAccount', fn (Builder $query) => $query->where('username', 'like', $search)->orWhere('primary_domain', 'like', $search)));
            })
            ->when($this->serverId !== 'all', fn (Builder $query) => $query->whereHas('cpanelAccount', fn (Builder $query) => $query->where('server_id', (int) $this->serverId)))
            ->when($this->classification !== 'all', fn (Builder $query) => $query->where('classification', $this->classification))
            ->when($this->monitoring === 'monitored', fn (Builder $query) => $query->where('monitoring_enabled', true))
            ->when($this->monitoring === 'not_monitored', fn (Builder $query) => $query->where('monitoring_enabled', false))
            ->orderBy('domain')
            ->paginate(25);
    }

    /** @return array{servers: Collection<int, Server>, classifications: array<int, DomainClassification>} */
    public function with(): array
    {
        return [
            'servers' => Server::query()->select(['id', 'name'])->orderBy('name')->get(),
            'classifications' => DomainClassification::cases(),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8 sm:px-6 lg:px-8">
    <header class="space-y-2"><flux:heading size="xl">{{ __('Domains') }}</flux:heading><flux:text>{{ __('Browse the locally discovered estate and latest monitoring observations.') }}</flux:text></header>
    <section class="grid gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-2 lg:grid-cols-5" aria-label="{{ __('Domain filters') }}">
        <flux:input wire:model.live.debounce.300ms="search" label="{{ __('Search') }}" placeholder="{{ __('Domain or account') }}" />
        <flux:select wire:model.live="serverId" label="{{ __('Server') }}"><flux:select.option value="all">{{ __('All servers') }}</flux:select.option>@foreach ($servers as $server)<flux:select.option value="{{ $server->id }}">{{ $server->name }}</flux:select.option>@endforeach</flux:select>
        <flux:select wire:model.live="classification" label="{{ __('Classification') }}"><flux:select.option value="all">{{ __('All classifications') }}</flux:select.option>@foreach ($classifications as $item)<flux:select.option value="{{ $item->value }}">{{ ucfirst($item->value) }}</flux:select.option>@endforeach</flux:select>
        <flux:select wire:model.live="monitoring" label="{{ __('Monitoring') }}"><flux:select.option value="all">{{ __('All') }}</flux:select.option><flux:select.option value="monitored">{{ __('Monitored') }}</flux:select.option><flux:select.option value="not_monitored">{{ __('Not monitored') }}</flux:select.option></flux:select>
        <flux:select wire:model.live="estateStatus" label="{{ __('Estate status') }}"><flux:select.option value="current">{{ __('Current') }}</flux:select.option><flux:select.option value="removed">{{ __('Removed') }}</flux:select.option><flux:select.option value="all">{{ __('All') }}</flux:select.option></flux:select>
    </section>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        @if ($this->domains->isEmpty())
            <div class="px-6 py-12 text-center"><flux:heading size="lg">{{ __('No domains match these filters') }}</flux:heading><flux:text>{{ __('Try another search or estate status.') }}</flux:text></div>
        @else
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead><tr>@foreach ([__('Domain'), __('Type'), __('Classification'), __('Server'), __('cPanel account'), __('Monitoring'), __('Latest HTTP'), __('Latest DNS'), __('Latest TLS'), __('Active issues')] as $heading)<th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->domains as $domain)
                    <tr wire:key="domain-{{ $domain->id }}">
                        <td class="px-4 py-3"><a class="font-medium hover:underline" href="{{ route('domains.show', $domain) }}" wire:navigate>{{ $domain->domain }}</a>@if ($domain->removed_at || ! $domain->is_active)<div class="text-zinc-500">{{ __('Removed') }}</div>@endif</td>
                        <td class="px-4 py-3">{{ ucfirst($domain->type->value) }}</td>
                        <td class="px-4 py-3">{{ ucfirst($domain->classification->value) }}</td>
                        <td class="px-4 py-3">{{ $domain->cpanelAccount?->server?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $domain->cpanelAccount?->username ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $domain->monitoring_enabled ? __('Monitored') : __('Not monitored') }}</td>
                        <td class="px-4 py-3">@if (! $domain->latestHttpCheck){{ __('Not checked yet') }}@elseif (! $domain->latestHttpCheck->successful){{ __('Failed') }}@else{{ __('HTTP :status', ['status' => $domain->latestHttpCheck->http_status]) }}@if ($domain->latestHttpCheck->response_time_ms !== null)<div class="text-zinc-500">{{ number_format($domain->latestHttpCheck->response_time_ms) }} ms</div>@endif @endif</td>
                        <td class="px-4 py-3">{{ ! $domain->latestDnsCheck ? __('Not checked yet') : ($domain->latestDnsCheck->successful ? __('Resolved') : __('Failed')) }}</td>
                        <td class="px-4 py-3">{{ ! $domain->latestTlsCheck ? __('Not checked yet') : ($domain->latestTlsCheck->successful && $domain->latestTlsCheck->ssl_valid ? __('Valid') : __('Invalid / Failed')) }}</td>
                        <td class="px-4 py-3">@if ($domain->active_critical_issues_count)<flux:badge color="red">{{ __('Critical') }}</flux:badge>@elseif ($domain->active_warning_issues_count)<flux:badge color="amber">{{ __('Warning') }}</flux:badge>@else{{ __('No active issues') }}@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($this->domains->hasPages())<div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">{{ $this->domains->links() }}</div>@endif
        @endif
    </section>
</div>
