<?php

namespace Tests\Feature\Admin;

use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Server;
use App\Models\User;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_cannot_access_server_pages(): void
    {
        $server = Server::factory()->create();

        $this->get('/servers')->assertRedirect('/login');
        $this->get('/admin/servers/create')->assertRedirect('/login');
        $this->get("/admin/servers/{$server->id}/edit")->assertRedirect('/login');
    }

    public function test_staff_and_admin_can_access_server_index(): void
    {
        Server::factory()->create(['name' => 'London WHM']);

        foreach ([$this->staff(), $this->admin()] as $user) {
            $this->actingAs($user)->get('/servers')->assertOk()->assertSee('London WHM');
        }
    }

    public function test_server_index_uses_local_active_account_and_monitored_domain_counts(): void
    {
        $server = Server::factory()->create();
        $currentAccount = CpanelAccount::factory()->for($server)->create();
        CpanelAccount::factory()->for($server)->removed()->create();
        Domain::factory()->for($currentAccount)->create(['monitoring_enabled' => true]);
        Domain::factory()->for($currentAccount)->create(['monitoring_enabled' => false]);
        Domain::factory()->for($currentAccount)->removed()->create(['monitoring_enabled' => true]);

        $this->mock(WhmClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('listAccounts', 'listDomains', 'getServerHealth', 'getAccountDiskUsage', 'testConnection');
        });

        $this->actingAs($this->staff())
            ->get('/servers')
            ->assertOk()
            ->assertSeeHtml('data-test="active-accounts-'.$server->id.'" data-count="1"')
            ->assertSeeHtml('data-test="monitored-domains-'.$server->id.'" data-count="1"');
    }

    public function test_staff_cannot_access_create_or_edit_pages(): void
    {
        $staff = $this->staff();
        $server = Server::factory()->create();

        $this->actingAs($staff)->get('/admin/servers/create')->assertForbidden();
        $this->actingAs($staff)->get("/admin/servers/{$server->id}/edit")->assertForbidden();
    }

    public function test_admin_can_access_create_and_edit_pages(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create();

        $this->actingAs($admin)->get('/admin/servers/create')->assertOk();
        $this->actingAs($admin)->get("/admin/servers/{$server->id}/edit")->assertOk();
    }

    public function test_staff_index_does_not_render_admin_controls(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->staff())
            ->get('/servers')
            ->assertOk()
            ->assertDontSee('Add WHM server')
            ->assertDontSee(route('admin.servers.edit', $server));
    }

    public function test_staff_cannot_directly_invoke_create_edit_test_or_toggle_actions(): void
    {
        $staff = $this->staff();
        $server = Server::factory()->create(['enabled' => true]);

        Livewire::actingAs($staff)->test('pages::admin.servers.create')->fill($this->validForm())->call('save')->assertForbidden();
        Livewire::actingAs($staff)->test('pages::admin.servers.create')->fill($this->validForm())->call('testConnection')->assertForbidden();
        Livewire::actingAs($staff)->test('pages::admin.servers.edit', ['server' => $server])->call('save')->assertForbidden();
        Livewire::actingAs($staff)->test('pages::admin.servers.edit', ['server' => $server])->call('testConnection')->assertForbidden();
        Livewire::actingAs($staff)->test('pages::servers.index')->call('toggleEnabled', $server->id)->assertForbidden();

        $this->assertSame(1, Server::query()->count());
        $this->assertTrue($server->refresh()->enabled);
    }

    public function test_admin_can_create_a_server_with_normalized_values_and_defaults(): void
    {
        Bus::fake();
        $this->mock(WhmClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('testConnection');
        });

        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.create')
            ->assertSet('whmPort', 2087)
            ->assertSet('enabled', true)
            ->fill([
                ...$this->validForm(),
                'name' => '  Primary WHM  ',
                'hostname' => '  WHM1.EXAMPLE.INVALID  ',
                'apiUsername' => '  estate-reader  ',
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('servers.index'));

        $server = Server::query()->sole();
        $this->assertSame('Primary WHM', $server->name);
        $this->assertSame('whm1.example.invalid', $server->hostname);
        $this->assertSame(2087, $server->whm_port);
        $this->assertSame('estate-reader', $server->api_username);
        $this->assertTrue($server->enabled);
        Bus::assertNothingDispatched();
    }

    public function test_hostname_must_be_unique(): void
    {
        Server::factory()->create(['hostname' => 'whm1.example.invalid']);

        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.create')
            ->fill($this->validForm())
            ->call('save')
            ->assertHasErrors(['hostname' => 'unique']);
    }

    #[DataProvider('malformedHostnameProvider')]
    public function test_malformed_hostname_is_rejected(string $hostname): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.create')
            ->fill([...$this->validForm(), 'hostname' => $hostname])
            ->call('save')
            ->assertHasErrors('hostname');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedHostnameProvider(): iterable
    {
        yield 'scheme' => ['https://server.example.invalid'];
        yield 'port' => ['server.example.invalid:2087'];
        yield 'path' => ['server.example.invalid/path'];
        yield 'spaces' => ['server name.example.invalid'];
        yield 'single label' => ['localhost'];
    }

    public function test_api_token_is_required_on_create(): void
    {
        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.create')
            ->fill([...$this->validForm(), 'apiToken' => ''])
            ->call('save')
            ->assertHasErrors(['apiToken' => 'required']);
    }

    public function test_created_api_token_is_encrypted_and_hidden(): void
    {
        Livewire::actingAs($this->admin())->test('pages::admin.servers.create')->fill($this->validForm())->call('save');

        $server = Server::query()->sole();
        $rawToken = $server->getRawOriginal('api_token');

        $this->assertNotSame('candidate-fixture-token', $rawToken);
        $this->assertStringNotContainsString('candidate-fixture-token', (string) $rawToken);
        $this->assertSame('candidate-fixture-token', $server->api_token);
        $this->assertArrayNotHasKey('api_token', $server->toArray());
    }

    public function test_admin_can_edit_normal_fields_and_blank_token_retains_existing_secret(): void
    {
        Bus::fake();
        $server = Server::factory()->create([
            'hostname' => 'whm1.example.invalid',
            'api_token' => 'existing-fixture-token',
        ]);

        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.edit', ['server' => $server])
            ->assertSet('apiToken', '')
            ->assertDontSee('existing-fixture-token')
            ->set('name', 'Updated WHM')
            ->set('hostname', 'WHM2.EXAMPLE.INVALID')
            ->set('whmPort', 2099)
            ->set('apiUsername', 'replacement-reader')
            ->set('enabled', false)
            ->set('apiToken', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('servers.index'));

        $server->refresh();
        $this->assertSame('Updated WHM', $server->name);
        $this->assertSame('whm2.example.invalid', $server->hostname);
        $this->assertSame(2099, $server->whm_port);
        $this->assertSame('replacement-reader', $server->api_username);
        $this->assertFalse($server->enabled);
        $this->assertSame('existing-fixture-token', $server->api_token);
        Bus::assertNothingDispatched();
    }

    public function test_supplied_replacement_token_replaces_the_stored_secret(): void
    {
        $server = Server::factory()->create(['api_token' => 'existing-fixture-token']);

        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.edit', ['server' => $server])
            ->set('apiToken', 'replacement-fixture-token')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('replacement-fixture-token', $server->refresh()->api_token);
    }

    public function test_unsaved_connection_test_uses_the_interface_and_does_not_persist(): void
    {
        $testedServer = null;
        $this->mock(WhmClient::class, function (MockInterface $mock) use (&$testedServer): void {
            $mock->shouldReceive('testConnection')->once()->withArgs(function (Server $server) use (&$testedServer): bool {
                $testedServer = $server;

                return true;
            });
        });

        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.create')
            ->fill($this->validForm())
            ->call('testConnection')
            ->assertHasNoErrors()
            ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => $parameters['dataset']['variant'] === 'success');

        $this->assertInstanceOf(Server::class, $testedServer);
        $this->assertFalse($testedServer->exists);
        $this->assertSame('candidate-fixture-token', $testedServer->api_token);
        $this->assertSame(0, Server::query()->count());
    }

    public function test_failed_connection_test_reports_a_safe_message(): void
    {
        $this->mock(WhmClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('testConnection')->once()->andThrow(new WhmApiException('metadata_failure', [
                'server_hostname' => 'whm1.example.invalid',
                'function' => 'listaccts',
            ]));
        });

        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.create')
            ->fill($this->validForm())
            ->call('testConnection')
            ->assertDispatched('toast-show', function (string $event, array $parameters): bool {
                $text = $parameters['slots']['text'];

                return $parameters['dataset']['variant'] === 'danger'
                    && str_contains($text, 'Unable to connect')
                    && ! str_contains($text, 'candidate-fixture-token');
            });
    }

    public function test_existing_connection_test_uses_candidate_token_without_persisting_it(): void
    {
        $server = Server::factory()->create(['api_token' => 'existing-fixture-token']);

        $this->mock(WhmClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('testConnection')->once()->withArgs(
                fn (Server $candidate): bool => $candidate->api_token === 'candidate-replacement-token',
            );
        });

        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.edit', ['server' => $server])
            ->set('apiToken', 'candidate-replacement-token')
            ->call('testConnection')
            ->assertHasNoErrors();

        $this->assertSame('existing-fixture-token', $server->refresh()->api_token);
    }

    public function test_existing_connection_test_with_blank_candidate_uses_stored_token(): void
    {
        $server = Server::factory()->create(['api_token' => 'existing-fixture-token']);

        $this->mock(WhmClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('testConnection')->once()->withArgs(
                fn (Server $candidate): bool => $candidate->api_token === 'existing-fixture-token',
            );
        });

        Livewire::actingAs($this->admin())
            ->test('pages::admin.servers.edit', ['server' => $server])
            ->assertSet('apiToken', '')
            ->call('testConnection')
            ->assertHasNoErrors();
    }

    public function test_admin_can_toggle_enabled_without_removing_related_data(): void
    {
        $server = Server::factory()->create(['enabled' => true]);
        $account = CpanelAccount::factory()->for($server)->create();
        $domain = Domain::factory()->for($account)->create();

        Livewire::actingAs($this->admin())
            ->test('pages::servers.index')
            ->call('toggleEnabled', $server->id)
            ->assertHasNoErrors();

        $this->assertFalse($server->refresh()->enabled);
        $this->assertModelExists($account);
        $this->assertModelExists($domain);
    }

    /** @return array<string, int|string|bool> */
    private function validForm(): array
    {
        return [
            'name' => 'Primary WHM',
            'hostname' => 'whm1.example.invalid',
            'whmPort' => 2087,
            'apiUsername' => 'estate-reader',
            'apiToken' => 'candidate-fixture-token',
            'enabled' => true,
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'enabled' => true]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff', 'enabled' => true]);
    }
}
