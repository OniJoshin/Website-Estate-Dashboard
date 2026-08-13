<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_interactively_creates_an_enabled_admin(): void
    {
        $this->artisan('estate:create-admin')
            ->expectsQuestion('Name', 'Initial Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'StrongPassword1!')
            ->expectsQuestion('Confirm password', 'StrongPassword1!')
            ->expectsOutput('Administrator created successfully.')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame('Initial Admin', $admin->name);
        $this->assertSame('admin', $admin->role->value);
        $this->assertTrue($admin->enabled);
        $this->assertTrue(Hash::check('StrongPassword1!', $admin->password));
    }

    public function test_command_rejects_mismatched_password_confirmation(): void
    {
        $this->artisan('estate:create-admin')
            ->expectsQuestion('Name', 'Initial Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'StrongPassword1!')
            ->expectsQuestion('Confirm password', 'DifferentPassword1!')
            ->assertFailed();

        $this->assertNull(User::query()->where('email', 'admin@example.com')->first());
    }
}
