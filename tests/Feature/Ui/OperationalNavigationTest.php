<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OperationalNavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_navigation_contains_operational_pages_without_admin_controls(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSee('Dashboard')->assertSee('Servers')->assertSee('Domains')->assertSee('Issues')
            ->assertDontSee('Users')->assertDontSee('Add WHM server');
    }

    public function test_admin_navigation_retains_administration_links(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))
            ->assertSee('Dashboard')->assertSee('Servers')->assertSee('Domains')->assertSee('Issues')->assertSee('Users');
    }
}
