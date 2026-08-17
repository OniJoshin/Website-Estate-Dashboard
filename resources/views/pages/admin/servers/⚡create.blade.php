<?php

use App\Models\Server;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add WHM server')] class extends Component {
    public string $name = '';

    public string $hostname = '';

    public int $whmPort = 2087;

    public string $apiUsername = '';

    public string $apiToken = '';

    public bool $enabled = true;

    public function save(): void
    {
        Gate::authorize('admin');

        $validated = $this->validatedData();

        Server::query()->create([
            'name' => $validated['name'],
            'hostname' => $validated['hostname'],
            'whm_port' => $validated['whmPort'],
            'api_username' => $validated['apiUsername'],
            'api_token' => $validated['apiToken'],
            'enabled' => $validated['enabled'],
        ]);

        Flux::toast(variant: 'success', text: __('WHM server created.'));

        $this->redirectRoute('servers.index', navigate: true);
    }

    public function testConnection(WhmClient $whmClient): void
    {
        Gate::authorize('admin');

        $validated = $this->validatedData();
        $server = new Server([
            'name' => $validated['name'],
            'hostname' => $validated['hostname'],
            'whm_port' => $validated['whmPort'],
            'api_username' => $validated['apiUsername'],
            'api_token' => $validated['apiToken'],
            'enabled' => $validated['enabled'],
        ]);

        try {
            $whmClient->testConnection($server);
        } catch (WhmApiException) {
            Flux::toast(variant: 'danger', text: __('Unable to connect to the WHM server. Check the hostname, port, username, token, and server availability.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('WHM connection successful.'));
    }

    /** @return array{name: string, hostname: string, whmPort: int, apiUsername: string, apiToken: string, enabled: bool} */
    private function validatedData(): array
    {
        $this->normalizeFields();

        return $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'hostname' => [
                'required',
                'string',
                'lowercase',
                'max:253',
                'regex:/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/',
                Rule::unique(Server::class),
            ],
            'whmPort' => ['required', 'integer', 'between:1,65535'],
            'apiUsername' => ['required', 'string', 'max:255'],
            'apiToken' => ['required', 'string', 'max:8192'],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    private function normalizeFields(): void
    {
        $this->name = trim($this->name);
        $this->hostname = Str::lower(trim($this->hostname));
        $this->apiUsername = trim($this->apiUsername);
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
    <div class="space-y-2">
        <flux:heading size="xl">{{ __('Add WHM server') }}</flux:heading>
        <flux:text>{{ __('Configure read-only WHM API access. Saving does not start an inventory sync.') }}</flux:text>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:input wire:model="name" :label="__('Name')" required autocomplete="off" />
        <flux:input wire:model="hostname" :label="__('Hostname')" :description="__('Hostname only, without https://, a port, or a path.')" required autocomplete="off" placeholder="whm1.example.invalid" />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="whmPort" :label="__('WHM port')" type="number" min="1" max="65535" required />
            <flux:input wire:model="apiUsername" :label="__('API username')" required autocomplete="off" />
        </div>

        <flux:input wire:model="apiToken" :label="__('API token')" type="password" required autocomplete="new-password" />
        <flux:checkbox wire:model="enabled" :label="__('Enabled')" :description="__('Later inventory and monitoring tasks will skip disabled servers.')" />

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:button :href="route('servers.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            <div class="flex gap-3">
                <flux:button type="button" wire:click="testConnection">{{ __('Test connection') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save server') }}</flux:button>
            </div>
        </div>
    </form>
</div>
