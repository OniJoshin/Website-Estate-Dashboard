<?php

namespace Tests\Feature\Operations;

use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SecretSafetyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_server_secret_is_absent_from_serialization_and_operational_pages(): void
    {
        Log::spy();
        $server = Server::factory()->create(['api_token' => 'TASK17_SUPER_SECRET_TOKEN']);
        $account = CpanelAccount::factory()->for($server)->create();
        $domain = Domain::factory()->for($account)->create();
        $admin = User::factory()->admin()->create();

        $this->assertStringNotContainsString('TASK17_SUPER_SECRET_TOKEN', json_encode($server, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('api_token', $server->toArray());

        foreach ([route('dashboard'), route('servers.show', $server), route('domains.index'), route('domains.show', $domain), route('admin.operations')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('TASK17_SUPER_SECRET_TOKEN');
        }

        Log::shouldNotHaveReceived('error');
    }
}
