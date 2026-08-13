<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InternalAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unused_authentication_routes_are_not_registered(): void
    {
        $routeNames = [
            'register',
            'register.store',
            'passkey.login',
            'passkey.confirm',
            'passkey.store',
            'two-factor.login',
            'two-factor.enable',
            'two-factor.disable',
            'verification.notice',
            'verification.verify',
            'verification.send',
        ];

        foreach ($routeNames as $routeName) {
            $this->assertFalse(Route::has($routeName), "Route [{$routeName}] should not be registered.");
        }

        $this->get('/register')->assertNotFound();
        $this->get('/passkeys/login/options')->assertNotFound();
        $this->get('/two-factor-challenge')->assertNotFound();
        $this->get('/email/verify')->assertNotFound();
    }

    public function test_email_password_login_and_password_reset_routes_remain_available(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();

        $this->assertTrue(Route::has('logout'));
        $this->assertTrue(Route::has('password.reset'));
        $this->assertTrue(Route::has('password.update'));
    }

    public function test_enabled_user_can_authenticate_and_last_login_is_recorded(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'enabled' => true,
            'last_login_at' => null,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_disabled_user_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => Hash::make('password'),
            'enabled' => false,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_is_logged_out_on_their_next_request_after_being_disabled(): void
    {
        $user = User::factory()->create([
            'role' => 'staff',
            'enabled' => true,
        ]);

        $this->actingAs($user);
        $user->update(['enabled' => false]);

        $this->get('/dashboard')
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_guest_cannot_view_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login', absolute: false));
    }

    public function test_enabled_staff_user_can_view_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'staff',
            'enabled' => true,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
