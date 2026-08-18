<?php

namespace Tests\Feature\Operations;

use App\Models\Server;
use App\Models\User;
use App\Support\Operations\OperationsStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_operations_page_is_admin_only_and_navigation_is_role_aware(): void
    {
        $this->get('/admin/operations')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/operations')->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee('Operations');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin/operations')->assertOk();
        $this->actingAs($admin)->get(route('dashboard'))->assertSee('Operations');
    }

    public function test_operations_page_shows_safe_application_heartbeat_queue_and_schedule_information(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 12:00:00');
        Cache::put(OperationsStatus::SCHEDULER_HEARTBEAT_KEY, now()->subMinute()->toIso8601String());
        Cache::put(OperationsStatus::QUEUE_HEARTBEAT_KEY, now()->subMinutes(4)->toIso8601String());
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->subMinutes(8)->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => fake()->uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Monitoring\\CheckDomainHttp'], JSON_THROW_ON_ERROR),
            'exception' => 'Authorization: whm root:TASK17_SUPER_SECRET_TOKEN <html>PRIVATE_RESPONSE_BODY</html>',
            'failed_at' => now(),
        ]);
        Server::factory()->create(['api_token' => 'TASK17_SUPER_SECRET_TOKEN']);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/admin/operations');

        $response->assertOk()
            ->assertSee('Current')->assertSee('Stale')
            ->assertSee('1 pending job')->assertSee('1 failed job')
            ->assertSee('CheckDomainHttp')
            ->assertSee('Server health: every 5 minutes')
            ->assertSee('HTTP: every 10 minutes')
            ->assertSee('DNS: every 6 hours')
            ->assertSee('TLS: every 6 hours')
            ->assertSee('Inventory: daily at 03:00 Europe/London')
            ->assertSee('Retention: 90 days')
            ->assertDontSee('TASK17_SUPER_SECRET_TOKEN')
            ->assertDontSee('Authorization:')
            ->assertDontSee('PRIVATE_RESPONSE_BODY');
    }

    public function test_malformed_failed_job_payload_is_safely_presented(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => fake()->uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{malformed', 'exception' => 'private trace', 'failed_at' => now(),
        ]);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/operations')
            ->assertOk()->assertSee('Unknown job')->assertDontSee('{malformed')->assertDontSee('private trace');
    }

    public function test_operations_page_warns_when_debug_is_enabled_outside_local_or_testing(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('app.debug', true);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/operations')
            ->assertOk()->assertSee('Debug mode is enabled outside local/testing.');
    }
}
