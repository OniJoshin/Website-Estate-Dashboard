<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_cannot_access_user_management(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'enabled' => true,
        ]);

        $this->actingAs($staff)->get('/admin/users')->assertForbidden();
    }

    public function test_staff_cannot_invoke_user_management_actions(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'enabled' => true,
        ]);

        Livewire::actingAs($staff)
            ->test('pages::admin.users.index')
            ->set('name', 'Unauthorized User')
            ->set('email', 'unauthorized@example.com')
            ->set('role', 'staff')
            ->call('createUser')
            ->assertForbidden();

        $this->assertNull(User::query()->where('email', 'unauthorized@example.com')->first());
    }

    public function test_admin_can_access_user_management_and_see_users(): void
    {
        $admin = User::factory()->create([
            'name' => 'Dashboard Admin',
            'role' => 'admin',
            'enabled' => true,
        ]);
        $staff = User::factory()->create([
            'name' => 'Estate Staff',
            'role' => 'staff',
        ]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee($admin->name)
            ->assertSee($staff->name);
    }

    public function test_admin_can_create_user_and_password_setup_email_is_sent(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'role' => 'admin',
            'enabled' => true,
        ]);

        Livewire::actingAs($admin)
            ->test('pages::admin.users.index')
            ->set('name', 'New Staff')
            ->set('email', 'new.staff@example.com')
            ->set('role', 'staff')
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'new.staff@example.com')->firstOrFail();

        $this->assertSame('New Staff', $user->name);
        $this->assertSame('staff', $user->role->value);
        $this->assertTrue($user->enabled);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_admin_can_change_another_users_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($admin)
            ->test('pages::admin.users.index')
            ->call('changeRole', $user->id, 'admin')
            ->assertHasNoErrors();

        $this->assertSame('admin', $user->refresh()->role->value);
    }

    public function test_admin_can_disable_and_enable_another_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['enabled' => true]);

        $component = Livewire::actingAs($admin)->test('pages::admin.users.index');

        $component->call('toggleEnabled', $user->id);
        $this->assertFalse($user->refresh()->enabled);

        $component->call('toggleEnabled', $user->id);
        $this->assertTrue($user->refresh()->enabled);
    }

    public function test_admin_cannot_disable_their_own_account(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'enabled' => true,
        ]);

        Livewire::actingAs($admin)
            ->test('pages::admin.users.index')
            ->call('toggleEnabled', $admin->id)
            ->assertHasErrors('enabled');

        $this->assertTrue($admin->refresh()->enabled);
    }
}
