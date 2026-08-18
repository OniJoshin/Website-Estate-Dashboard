<?php

use App\Support\Operations\OperationsStatus;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Operations')] class extends Component {
    #[Computed]
    public function operations(): array
    {
        $status = app(OperationsStatus::class);

        return [
            'application' => $status->applicationInformation(),
            'scheduler' => $status->schedulerHeartbeat(),
            'queue' => $status->queueHeartbeat(),
            'metrics' => $status->queueMetrics(),
            'failedJobs' => $status->recentFailedJobs(),
            'schedule' => $status->scheduleSummary(),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <div>
            <flux:heading size="xl">Operations</flux:heading>
            <flux:text>Local application, scheduler, queue and configuration diagnostics.</flux:text>
        </div>

        @if ($this->operations['application']['debug'] && ! in_array($this->operations['application']['environment'], ['local', 'testing'], true))
            <flux:callout variant="danger" icon="exclamation-triangle" heading="Debug mode is enabled outside local/testing." />
        @endif

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <flux:card><flux:heading>Scheduler</flux:heading><flux:text>{{ $this->operations['scheduler']->state }}</flux:text><flux:text>{{ $this->operations['scheduler']->recordedAt?->format('j M Y, H:i:s') ?? 'No heartbeat recorded' }}</flux:text></flux:card>
            <flux:card><flux:heading>Queue worker</flux:heading><flux:text>{{ $this->operations['queue']->state }}</flux:text><flux:text>{{ $this->operations['queue']->recordedAt?->format('j M Y, H:i:s') ?? 'No heartbeat recorded' }}</flux:text></flux:card>
            <flux:card><flux:heading>Queue backlog</flux:heading><flux:text>{{ $this->operations['metrics']['pending'] }} {{ Str::plural('pending job', $this->operations['metrics']['pending']) }}</flux:text><flux:text>{{ $this->operations['metrics']['failed'] }} {{ Str::plural('failed job', $this->operations['metrics']['failed']) }}</flux:text></flux:card>
            <flux:card><flux:heading>Oldest pending</flux:heading><flux:text>{{ $this->operations['metrics']['oldest_pending_at']?->diffForHumans() ?? 'No pending jobs' }}</flux:text></flux:card>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <flux:card class="space-y-3">
                <flux:heading>Application</flux:heading>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    @foreach (['environment' => 'Environment', 'php' => 'PHP', 'laravel' => 'Laravel', 'database' => 'Database driver', 'cache' => 'Cache store', 'queue' => 'Queue connection'] as $key => $label)
                        <dt class="text-zinc-500">{{ $label }}</dt><dd>{{ $this->operations['application'][$key] }}</dd>
                    @endforeach
                    <dt class="text-zinc-500">Debug</dt><dd>{{ $this->operations['application']['debug'] ? 'Enabled' : 'Disabled' }}</dd>
                </dl>
            </flux:card>
            <flux:card class="space-y-3"><flux:heading>Estate schedule</flux:heading><ul class="space-y-1 text-sm">@foreach ($this->operations['schedule'] as $item)<li>{{ $item }}</li>@endforeach</ul></flux:card>
        </div>

        <flux:card class="space-y-3">
            <flux:heading>Recent failed jobs</flux:heading>
            @if ($this->operations['failedJobs'] === [])
                <flux:text>No failed jobs recorded.</flux:text>
            @else
                <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th scope="col" class="py-2">Failed</th><th scope="col">Job</th><th scope="col">Connection</th><th scope="col">Queue</th></tr></thead><tbody>
                    @foreach ($this->operations['failedJobs'] as $job)<tr class="border-b border-zinc-200 dark:border-zinc-700"><td class="py-2">{{ $job['failed_at']->format('j M Y, H:i:s') }}</td><td>{{ $job['job'] }}</td><td>{{ $job['connection'] }}</td><td>{{ $job['queue'] }}</td></tr>@endforeach
                </tbody></table></div>
            @endif
        </flux:card>
</div>
