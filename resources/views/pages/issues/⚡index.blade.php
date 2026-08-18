<?php

use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\Server;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Issues')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $severity = 'all';

    #[Url]
    public string $type = 'all';

    #[Url]
    public string $serverId = 'all';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'severity', 'type', 'serverId'], true)) {
            $this->resetPage();
        }
    }

    /** @return LengthAwarePaginator<int, Issue> */
    #[Computed]
    public function issues(): LengthAwarePaginator
    {
        return Issue::query()
            ->with(['server', 'cpanelAccount.server', 'domain.cpanelAccount.server'])
            ->when($this->status === 'active', fn (Builder $query) => $query->whereNull('resolved_at'))
            ->when($this->status === 'resolved', fn (Builder $query) => $query->whereNotNull('resolved_at'))
            ->when($this->severity !== 'all', fn (Builder $query) => $query->where('severity', $this->severity))
            ->when($this->type !== 'all', fn (Builder $query) => $query->where('type', $this->type))
            ->when($this->serverId !== 'all', function (Builder $query): void {
                $serverId = (int) $this->serverId;
                $query->where(function (Builder $query) use ($serverId): void {
                    $query->where('server_id', $serverId)
                        ->orWhereHas('cpanelAccount', fn (Builder $query) => $query->where('server_id', $serverId))
                        ->orWhereHas('domain.cpanelAccount', fn (Builder $query) => $query->where('server_id', $serverId));
                });
            })
            ->when(
                $this->status === 'resolved',
                fn (Builder $query) => $query->latest('resolved_at')->latest('id'),
                fn (Builder $query) => $query
                    ->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")
                    ->latest('last_detected_at')->latest('id'),
            )
            ->paginate(25);
    }

    /** @return array{servers: Collection<int, Server>, issueTypes: array<int, IssueType>} */
    public function with(): array
    {
        return [
            'servers' => Server::query()->select(['id', 'name', 'hostname'])->orderBy('name')->get(),
            'issueTypes' => IssueType::cases(),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8 sm:px-6 lg:px-8">
    <header class="space-y-2"><flux:heading size="xl">{{ __('Issues') }}</flux:heading><flux:text>{{ __('Active incidents and resolved operational history.') }}</flux:text></header>

    <section class="grid gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Issue filters') }}">
        <flux:select wire:model.live="status" label="{{ __('Status') }}"><flux:select.option value="active">{{ __('Active') }}</flux:select.option><flux:select.option value="resolved">{{ __('Resolved') }}</flux:select.option><flux:select.option value="all">{{ __('All') }}</flux:select.option></flux:select>
        <flux:select wire:model.live="severity" label="{{ __('Severity') }}"><flux:select.option value="all">{{ __('All severities') }}</flux:select.option><flux:select.option value="critical">{{ __('Critical') }}</flux:select.option><flux:select.option value="warning">{{ __('Warning') }}</flux:select.option></flux:select>
        <flux:select wire:model.live="type" label="{{ __('Issue type') }}"><flux:select.option value="all">{{ __('All types') }}</flux:select.option>@foreach ($issueTypes as $issueType)<flux:select.option value="{{ $issueType->value }}">{{ $issueType->label() }}</flux:select.option>@endforeach</flux:select>
        <flux:select wire:model.live="serverId" label="{{ __('Server') }}"><flux:select.option value="all">{{ __('All servers') }}</flux:select.option>@foreach ($servers as $server)<flux:select.option value="{{ $server->id }}">{{ $server->name }}</flux:select.option>@endforeach</flux:select>
    </section>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        @if ($this->issues->isEmpty())
            <div class="px-6 py-12 text-center"><flux:heading size="lg">{{ __('No issues match these filters') }}</flux:heading><flux:text>{{ __('Adjust the filters to browse other operational history.') }}</flux:text></div>
        @else
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead><tr>@foreach ([__('Severity'), __('Issue'), __('Target'), __('Server'), __('Details'), __('First detected'), __('Last detected'), __('Resolved')] as $heading)<th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->issues as $issue)
                    @php
                        $contextServer = $issue->server ?? $issue->cpanelAccount?->server ?? $issue->domain?->cpanelAccount?->server;
                        $target = $issue->domain?->domain
                            ?? ($issue->cpanelAccount ? trim($issue->cpanelAccount->username.' · '.($issue->cpanelAccount->primary_domain ?? '')) : null)
                            ?? ($issue->server ? $issue->server->name.' · '.$issue->server->hostname : 'Unknown target');
                    @endphp
                    <tr wire:key="issue-{{ $issue->id }}">
                        <td class="px-4 py-3"><flux:badge :color="$issue->severity === IssueSeverity::Critical ? 'red' : 'amber'">{{ ucfirst($issue->severity->value) }}</flux:badge></td>
                        <td class="px-4 py-3"><div class="font-medium">{{ $issue->title }}</div><div class="text-zinc-500">{{ $issue->type->label() }}</div></td>
                        <td class="px-4 py-3">@if ($issue->domain)<a class="hover:underline" href="{{ route('domains.show', $issue->domain) }}" wire:navigate>{{ $target }}</a>@else{{ $target }}@endif</td>
                        <td class="px-4 py-3">@if ($contextServer)<a class="hover:underline" href="{{ route('servers.show', $contextServer) }}" wire:navigate>{{ $contextServer->name }}</a>@else—@endif</td>
                        <td class="max-w-sm px-4 py-3">{{ $issue->details ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $issue->first_detected_at->format('j M Y, H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $issue->last_detected_at->format('j M Y, H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $issue->resolved_at?->format('j M Y, H:i') ?? __('Active') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($this->issues->hasPages())<div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">{{ $this->issues->links() }}</div>@endif
        @endif
    </section>
</div>
