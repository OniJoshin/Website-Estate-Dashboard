<?php

namespace Tests\Unit\Whm;

use App\Data\Whm\WhmServerHealthData;
use App\Enums\DomainType;
use App\Models\Server;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use App\Services\Whm\HttpWhmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpWhmClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_client_contract_is_bound_to_the_http_client(): void
    {
        $this->assertInstanceOf(HttpWhmClient::class, resolve(WhmClient::class));
    }

    public function test_calls_use_https_configured_port_path_version_authentication_and_json(): void
    {
        Http::fake(['*' => Http::response($this->whmResponse(['acct' => []]))]);

        $this->client()->listAccounts($this->server());

        Http::assertSent(function (Request $request): bool {
            $parts = parse_url($request->url());

            return ($parts['scheme'] ?? null) === 'https'
                && ($parts['host'] ?? null) === 'whm.example.invalid'
                && ($parts['port'] ?? null) === 2099
                && ($parts['path'] ?? null) === '/json-api/listaccts'
                && $request['api.version'] === 1
                && $request->hasHeader('Authorization', 'whm estate-api:fixture-secret')
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    public function test_list_accounts_maps_documented_fields_and_normalizes_suspension_values(): void
    {
        Http::fake(['*' => Http::response($this->whmResponse([
            'acct' => [
                [
                    'user' => 'alpha',
                    'domain' => 'example.invalid',
                    'homedir' => '/home/alpha',
                    'plan' => 'Business',
                    'owner' => 'root',
                    'suspended' => 0,
                    'suspendreason' => 'not suspended',
                    'email' => 'owner@example.invalid',
                ],
                [
                    'user' => 'bravo',
                    'domain' => 'bravo.example.invalid',
                    'suspended' => '1',
                    'suspendreason' => 'billing hold',
                ],
            ],
        ]))]);

        $accounts = $this->client()->listAccounts($this->server());

        $this->assertCount(2, $accounts);
        $this->assertSame('alpha', $accounts[0]->username);
        $this->assertSame('example.invalid', $accounts[0]->primaryDomain);
        $this->assertSame('/home/alpha', $accounts[0]->homeDirectory);
        $this->assertSame('Business', $accounts[0]->package);
        $this->assertSame('root', $accounts[0]->owner);
        $this->assertFalse($accounts[0]->suspended);
        $this->assertSame(['email' => 'owner@example.invalid'], $accounts[0]->metadata);
        $this->assertTrue($accounts[1]->suspended);
        $this->assertSame('billing hold', $accounts[1]->suspensionReason);
    }

    public function test_outer_whm_failure_throws_a_safe_exception(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [],
            'metadata' => ['result' => 0, 'reason' => 'Invalid token fixture-secret'],
        ])]);

        try {
            $this->client()->listAccounts($this->server());
            $this->fail('Expected a WHM API exception.');
        } catch (WhmApiException $exception) {
            $this->assertSame('metadata_failure', $exception->category);
            $this->assertStringNotContainsString('fixture-secret', $exception->getMessage());
            $this->assertStringNotContainsString('fixture-secret', json_encode($exception->context, JSON_THROW_ON_ERROR));
        }
    }

    public function test_non_successful_http_response_throws_without_retrying(): void
    {
        Http::fake(['*' => Http::response('Unauthorized fixture-secret', 401)]);

        try {
            $this->client()->listAccounts($this->server());
            $this->fail('Expected a WHM API exception.');
        } catch (WhmApiException $exception) {
            $this->assertSame('http_failure', $exception->category);
            $this->assertSame(401, $exception->context['http_status']);
            $this->assertStringNotContainsString('fixture-secret', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_connection_failure_is_retried_conservatively_then_wrapped(): void
    {
        Http::fake(['*' => Http::failedConnection('Connection refused for fixture-secret')]);

        try {
            $this->client()->listAccounts($this->server());
            $this->fail('Expected a WHM API exception.');
        } catch (WhmApiException $exception) {
            $this->assertSame('connection_failure', $exception->category);
            $this->assertStringNotContainsString('fixture-secret', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_timeout_is_wrapped_without_exposing_the_token(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: timed out; token fixture-secret')]);

        try {
            $this->client()->listAccounts($this->server());
            $this->fail('Expected a WHM API exception.');
        } catch (WhmApiException $exception) {
            $this->assertSame('connection_failure', $exception->category);
            $this->assertStringNotContainsString('fixture-secret', $exception->getMessage());
        }
    }

    public function test_malformed_non_json_response_throws(): void
    {
        Http::fake(['*' => Http::response('<html>not json</html>', 200)]);

        $this->expectException(WhmApiException::class);
        $this->expectExceptionMessage('malformed_response');

        $this->client()->listAccounts($this->server());
    }

    public function test_domain_inventory_sends_uapi_parameters_maps_types_and_enriches_document_roots(): void
    {
        Http::fake(function (Request $request) {
            if ($request['cpanel.function'] === 'list_domains') {
                return Http::response($this->uapiResponse([
                    'main_domain' => 'example.invalid',
                    'addon_domains' => ['addon.invalid'],
                    'sub_domains' => ['staging.example.invalid'],
                    'parked_domains' => ['alias.invalid'],
                ]));
            }

            return Http::response($this->uapiResponse([
                'main_domain' => [[
                    'domain' => 'example.invalid',
                    'documentroot' => '/home/alpha/public_html',
                ]],
                'addon_domains' => [[
                    'domain' => 'addon.invalid',
                    'documentroot' => '/home/alpha/addon',
                    'rootdomain' => 'example.invalid',
                ]],
                'sub_domains' => [[
                    'domain' => 'staging.example.invalid',
                    'documentroot' => '/home/alpha/staging',
                    'rootdomain' => 'example.invalid',
                ]],
            ]));
        });

        $inventory = $this->client()->listDomains($this->server(), 'alpha');

        $this->assertCount(4, $inventory->domains);
        $domains = collect($inventory->domains)->keyBy('domain');
        $this->assertSame(DomainType::Primary, $domains['example.invalid']->type);
        $this->assertSame(DomainType::Addon, $domains['addon.invalid']->type);
        $this->assertSame(DomainType::Subdomain, $domains['staging.example.invalid']->type);
        $this->assertSame(DomainType::Alias, $domains['alias.invalid']->type);
        $this->assertSame('/home/alpha/addon', $domains['addon.invalid']->documentRoot);
        $this->assertSame('example.invalid', $domains['staging.example.invalid']->parentDomain);
        $this->assertNull($domains['alias.invalid']->documentRoot);

        Http::assertSent(function (Request $request): bool {
            return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/uapi_cpanel')
                && $request['cpanel.module'] === 'DomainInfo'
                && $request['cpanel.function'] === 'list_domains'
                && $request['cpanel.user'] === 'alpha'
                && $request['hide_temporary_domains'] === 1;
        });
    }

    public function test_failed_nested_uapi_status_throws_even_when_outer_metadata_succeeds(): void
    {
        Http::fake(['*' => Http::response($this->uapiResponse([], 0, ['Access denied']))]);

        $this->expectException(WhmApiException::class);
        $this->expectExceptionMessage('uapi_failure');

        $this->client()->listDomains($this->server(), 'alpha');
    }

    public function test_failed_domains_data_nested_uapi_status_throws(): void
    {
        Http::fakeSequence()
            ->push($this->uapiResponse(['main_domain' => 'example.invalid']))
            ->push($this->uapiResponse([], 0, ['Userdata unavailable']));

        $this->expectException(WhmApiException::class);
        $this->expectExceptionMessage('uapi_failure');

        $this->client()->listDomains($this->server(), 'alpha');
    }

    public function test_successful_list_domains_with_a_blank_main_domain_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->uapiResponse([
            'main_domain' => '',
            'addon_domains' => [],
            'sub_domains' => [],
            'parked_domains' => [],
        ]))]);

        $this->expectException(WhmApiException::class);
        $this->expectExceptionMessage('implausible_domain_inventory');

        $this->client()->listDomains($this->server(), 'alpha');
    }

    public function test_account_disk_usage_maps_blocks_to_bytes_and_unlimited_or_missing_limits_to_null(): void
    {
        Http::fake(['*' => Http::response($this->whmResponse([
            'accounts' => [
                ['user' => 'alpha', 'blocks_used' => 100, 'blocks_limit' => 500],
                ['user' => 'bravo', 'blocks_used' => '25', 'blocks_limit' => 0],
                ['user' => 'charlie', 'blocks_used' => null],
            ],
        ]))]);

        $usage = $this->client()->getAccountDiskUsage($this->server());

        $this->assertSame([
            'alpha' => ['used_bytes' => 102400, 'limit_bytes' => 512000],
            'bravo' => ['used_bytes' => 25600, 'limit_bytes' => null],
            'charlie' => ['used_bytes' => null, 'limit_bytes' => null],
        ], $usage);

        Http::assertSent(fn (Request $request): bool => $request['cache_mode'] === 'on');
    }

    public function test_server_health_normalizes_load_values_and_partition_data(): void
    {
        Http::fakeSequence()
            ->push($this->whmResponse(['one' => '0.42', 'five' => 1, 'fifteen' => '1.25']))
            ->push($this->whmResponse(['partition' => [[
                'filesystem' => '/dev/vda1',
                'mount' => '/',
                'size' => '100000',
                'used' => 85000,
                'available' => '15000',
                'percentage' => '85%',
            ]]]));

        $health = $this->client()->getServerHealth($this->server());

        $this->assertInstanceOf(WhmServerHealthData::class, $health);
        $this->assertSame(0.42, $health->load1);
        $this->assertSame(1.0, $health->load5);
        $this->assertSame(1.25, $health->load15);
        $this->assertSame([[
            'filesystem' => '/dev/vda1',
            'mount' => '/',
            'total' => 100000.0,
            'used' => 85000.0,
            'available' => 15000.0,
            'used_percent' => 85.0,
        ]], $health->partitions);

        Http::assertSent(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/systemloadavg'));
        Http::assertSent(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/getdiskusage'));
    }

    public function test_connection_test_returns_normally_for_valid_list_accounts_access(): void
    {
        Http::fake(['*' => Http::response($this->whmResponse(['acct' => []]))]);

        $this->client()->testConnection($this->server());

        $this->addToAssertionCount(1);
    }

    public function test_connection_test_throws_safely_for_invalid_authentication(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [],
            'metadata' => ['result' => 0, 'reason' => 'Invalid token fixture-secret'],
        ])]);

        try {
            $this->client()->testConnection($this->server());
            $this->fail('Expected a WHM API exception.');
        } catch (WhmApiException $exception) {
            $this->assertStringNotContainsString('fixture-secret', $exception->getMessage());
            $this->assertStringNotContainsString('fixture-secret', json_encode($exception->context, JSON_THROW_ON_ERROR));
        }
    }

    private function client(): HttpWhmClient
    {
        return resolve(HttpWhmClient::class);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function whmResponse(array $data): array
    {
        return [
            'data' => $data,
            'metadata' => ['result' => 1, 'reason' => 'OK'],
        ];
    }

    /** @param array<string, mixed> $data
     * @param  list<string>|null  $errors
     * @return array<string, mixed>
     */
    private function uapiResponse(array $data, int $status = 1, ?array $errors = null): array
    {
        return $this->whmResponse([
            'uapi' => [
                'data' => $data,
                'errors' => $errors,
                'messages' => null,
                'status' => $status,
            ],
        ]);
    }

    private function server(): Server
    {
        return Server::factory()->make([
            'id' => 42,
            'hostname' => 'whm.example.invalid',
            'whm_port' => 2099,
            'api_username' => 'estate-api',
            'api_token' => 'fixture-secret',
        ]);
    }
}
