<?php

use App\Enums\UserRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage users')] class extends Component {
    public string $name = '';

    public string $email = '';

    public string $role = 'staff';

    /** @return array{users: \Illuminate\Database\Eloquent\Collection<int, User>, roles: array<int, UserRole>} */
    public function with(): array
    {
        return [
            'users' => User::query()->orderBy('name')->get(),
            'roles' => UserRole::cases(),
        ];
    }

    public function createUser(): void
    {
        Gate::authorize('admin');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        $user = User::query()->create([
            ...$validated,
            'password' => Str::password(40),
            'enabled' => true,
        ]);

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            $this->addError('email', __($status));

            return;
        }

        $this->reset('name', 'email');
        $this->role = UserRole::Staff->value;

        Flux::toast(variant: 'success', text: __('User created and password setup email sent.'));
    }

    public function changeRole(int $userId, string $role): void
    {
        Gate::authorize('admin');

        $validated = validator(
            ['role' => $role],
            ['role' => ['required', Rule::enum(UserRole::class)]],
        )->validate();

        User::query()->findOrFail($userId)->update([
            'role' => $validated['role'],
        ]);

        Flux::toast(variant: 'success', text: __('User role updated.'));
    }

    public function toggleEnabled(int $userId): void
    {
        Gate::authorize('admin');

        $user = User::query()->findOrFail($userId);

        if ($user->is(auth()->user())) {
            $this->addError('enabled', __('You cannot disable your own account.'));

            return;
        }

        $user->update(['enabled' => ! $user->enabled]);

        Flux::toast(variant: 'success', text: __('User status updated.'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
    <div class="space-y-2">
        <flux:heading size="xl">{{ __('Dashboard users') }}</flux:heading>
        <flux:text>{{ __('Manage access to the Website Estate Dashboard. These roles do not grant access to WHM, cPanel, or hosted websites.') }}</flux:text>
    </div>

    <section class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-6 space-y-1">
            <flux:heading size="lg">{{ __('Create user') }}</flux:heading>
            <flux:text>{{ __('The user will receive an email to set their password.') }}</flux:text>
        </div>

        <form wire:submit="createUser" class="grid gap-5 md:grid-cols-3">
            <flux:input wire:model="name" :label="__('Name')" required autocomplete="off" />
            <flux:input wire:model="email" :label="__('Email address')" type="email" required autocomplete="off" />
            <flux:select wire:model="role" :label="__('Role')" required>
                @foreach ($roles as $availableRole)
                    <flux:select.option :value="$availableRole->value">{{ ucfirst($availableRole->value) }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="md:col-span-3">
                <flux:button type="submit" variant="primary">{{ __('Create user') }}</flux:button>
            </div>
        </form>
    </section>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Users') }}</flux:heading>
        </div>

        @error('enabled')
            <div class="border-b border-red-200 bg-red-50 px-6 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                {{ $message }}
            </div>
        @enderror

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800/70">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('User') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Role') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $user->name }}</div>
                                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $user->email }}</div>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <flux:select
                                    :value="$user->role->value"
                                    wire:change="changeRole({{ $user->id }}, $event.target.value)"
                                    :aria-label="__('Role for :name', ['name' => $user->name])"
                                    size="sm"
                                >
                                    @foreach ($roles as $availableRole)
                                        <flux:select.option :value="$availableRole->value">{{ ucfirst($availableRole->value) }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <flux:badge :color="$user->enabled ? 'green' : 'zinc'">
                                    {{ $user->enabled ? __('Enabled') : __('Disabled') }}
                                </flux:badge>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-right">
                                <flux:button
                                    size="sm"
                                    :variant="$user->enabled ? 'danger' : 'primary'"
                                    wire:click="toggleEnabled({{ $user->id }})"
                                    :disabled="$user->is(auth()->user())"
                                >
                                    {{ $user->enabled ? __('Disable') : __('Enable') }}
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
