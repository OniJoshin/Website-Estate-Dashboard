<?php

namespace App\Services\Whm;

use App\Data\Whm\WhmAccountData;
use App\Data\Whm\WhmDomainData;
use App\Data\Whm\WhmDomainInventory;
use App\Data\Whm\WhmServerHealthData;
use App\Enums\DomainType;
use App\Models\Server;
use App\Services\Whm\Contracts\WhmClient;
use App\Services\Whm\Exceptions\WhmApiException;
use App\Support\MonitoringThresholds;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpWhmClient implements WhmClient
{
    private const int BYTES_PER_QUOTA_BLOCK = 1024;

    public function __construct(private readonly MonitoringThresholds $thresholds) {}

    /** @return list<WhmAccountData> */
    public function listAccounts(Server $server): array
    {
        $response = $this->call($server, 'listaccts');
        $accounts = Arr::get($response, 'data.acct');

        if (! is_array($accounts) || ! array_is_list($accounts)) {
            throw $this->exception($server, 'listaccts', 'malformed_response');
        }

        return array_map(function (mixed $account) use ($server): WhmAccountData {
            if (! is_array($account) || $this->nullableString($account['user'] ?? null) === null) {
                throw $this->exception($server, 'listaccts', 'malformed_response');
            }

            return new WhmAccountData(
                username: (string) $account['user'],
                primaryDomain: $this->nullableString($account['domain'] ?? null),
                homeDirectory: $this->nullableString($account['homedir'] ?? null),
                package: $this->nullableString($account['plan'] ?? null),
                owner: $this->nullableString($account['owner'] ?? null),
                suspended: $this->boolean($account['suspended'] ?? false),
                suspensionReason: $this->nullableString($account['suspendreason'] ?? null),
                metadata: Arr::except($account, [
                    'user', 'domain', 'homedir', 'plan', 'owner', 'suspended', 'suspendreason',
                ]),
            );
        }, $accounts);
    }

    public function listDomains(Server $server, string $username): WhmDomainInventory
    {
        $inventory = $this->callUapi($server, $username, 'list_domains', [
            'hide_temporary_domains' => 1,
        ]);
        $primaryDomain = $this->nullableString($inventory['main_domain'] ?? null);

        if ($primaryDomain === null) {
            throw $this->exception($server, 'uapi_cpanel', 'implausible_domain_inventory', [
                'cpanel_username' => $username,
            ]);
        }

        $details = $this->callUapi($server, $username, 'domains_data', [
            'format' => 'list',
            'hide_temporary_domains' => 1,
        ]);
        $detailsByDomain = $this->domainDetailsByName($details);

        $domains = [
            $this->domainData($primaryDomain, DomainType::Primary, null, $detailsByDomain),
        ];

        foreach ($this->domainNames($inventory['addon_domains'] ?? null, $server, $username) as $domain) {
            $domains[] = $this->domainData($domain, DomainType::Addon, $primaryDomain, $detailsByDomain);
        }

        foreach ($this->domainNames($inventory['sub_domains'] ?? null, $server, $username) as $domain) {
            $domains[] = $this->domainData($domain, DomainType::Subdomain, $primaryDomain, $detailsByDomain);
        }

        foreach ($this->domainNames($inventory['parked_domains'] ?? null, $server, $username) as $domain) {
            $domains[] = $this->domainData($domain, DomainType::Alias, $primaryDomain, $detailsByDomain);
        }

        return new WhmDomainInventory($this->uniqueDomains($domains));
    }

    public function getServerHealth(Server $server): WhmServerHealthData
    {
        $loadResponse = $this->call($server, 'systemloadavg');
        $diskResponse = $this->call($server, 'getdiskusage');
        $load = Arr::get($loadResponse, 'data');
        $partitions = Arr::get($diskResponse, 'data.partition');

        if (! is_array($load) || ! is_array($partitions) || ! array_is_list($partitions)) {
            throw $this->exception($server, 'systemloadavg/getdiskusage', 'malformed_response');
        }

        $load1 = $this->requiredFloat($load['one'] ?? null, $server, 'systemloadavg');
        $load5 = $this->requiredFloat($load['five'] ?? null, $server, 'systemloadavg');
        $load15 = $this->requiredFloat($load['fifteen'] ?? null, $server, 'systemloadavg');

        return new WhmServerHealthData(
            load1: $load1,
            load5: $load5,
            load15: $load15,
            partitions: array_map(
                fn (mixed $partition): array => $this->normalizePartition($partition, $server),
                $partitions,
            ),
        );
    }

    /** @return array<string, array{used_bytes: ?int, limit_bytes: ?int}> */
    public function getAccountDiskUsage(Server $server): array
    {
        $response = $this->call($server, 'get_disk_usage', ['cache_mode' => 'on']);
        $accounts = Arr::get($response, 'data.accounts');

        if (! is_array($accounts) || ! array_is_list($accounts)) {
            throw $this->exception($server, 'get_disk_usage', 'malformed_response');
        }

        $usage = [];

        foreach ($accounts as $account) {
            if (! is_array($account) || ($username = $this->nullableString($account['user'] ?? null)) === null) {
                throw $this->exception($server, 'get_disk_usage', 'malformed_response');
            }

            $usage[$username] = [
                'used_bytes' => $this->quotaBlocksToBytes($account['blocks_used'] ?? null, false),
                'limit_bytes' => $this->quotaBlocksToBytes($account['blocks_limit'] ?? null, true),
            ];
        }

        return $usage;
    }

    public function testConnection(Server $server): void
    {
        $this->listAccounts($server);
    }

    /**
     * @param  array<string, int|string>  $parameters
     * @return array<string, mixed>
     */
    private function call(Server $server, string $function, array $parameters = []): array
    {
        $url = sprintf(
            'https://%s:%d/json-api/%s',
            $server->hostname,
            $server->whm_port,
            rawurlencode($function),
        );

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'Authorization' => "whm {$server->api_username}:{$server->api_token}",
                ])
                ->connectTimeout(5)
                ->timeout($this->thresholds->httpTimeoutSeconds())
                ->retry(
                    2,
                    100,
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                    throw: false,
                )
                ->get($url, ['api.version' => 1, ...$parameters]);
        } catch (ConnectionException) {
            throw $this->exception($server, $function, 'connection_failure');
        }

        $this->validateHttpResponse($server, $function, $response);
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw $this->exception($server, $function, 'malformed_response', [
                'http_status' => $response->status(),
            ]);
        }

        $result = Arr::get($decoded, 'metadata.result');

        if (! is_numeric($result) || (int) $result !== 1) {
            throw $this->exception($server, $function, 'metadata_failure', [
                'whm_reason' => $this->safeReason($server, Arr::get($decoded, 'metadata.reason')),
            ]);
        }

        return $decoded;
    }

    private function validateHttpResponse(Server $server, string $function, Response $response): void
    {
        if (! $response->successful()) {
            throw $this->exception($server, $function, 'http_failure', [
                'http_status' => $response->status(),
            ]);
        }
    }

    /**
     * @param  array<string, int|string>  $parameters
     * @return array<string, mixed>
     */
    private function callUapi(Server $server, string $username, string $uapiFunction, array $parameters = []): array
    {
        $response = $this->call($server, 'uapi_cpanel', [
            'cpanel.module' => 'DomainInfo',
            'cpanel.function' => $uapiFunction,
            'cpanel.user' => $username,
            ...$parameters,
        ]);
        $uapi = Arr::get($response, 'data.uapi');
        $result = is_array($uapi) && is_array($uapi['result'] ?? null)
            ? $uapi['result']
            : $uapi;

        if (! is_array($result) || ! is_numeric($result['status'] ?? null) || (int) $result['status'] !== 1) {
            throw $this->exception($server, 'uapi_cpanel', 'uapi_failure', [
                'cpanel_username' => $username,
                'whm_reason' => $this->safeReason($server, $this->uapiReason($result)),
            ]);
        }

        $data = $result['data'] ?? null;

        if (! is_array($data)) {
            throw $this->exception($server, 'uapi_cpanel', 'malformed_response', [
                'cpanel_username' => $username,
            ]);
        }

        return $data;
    }

    /** @return list<string> */
    private function domainNames(mixed $value, Server $server, string $username): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->exception($server, 'uapi_cpanel', 'implausible_domain_inventory', [
                'cpanel_username' => $username,
            ]);
        }

        $domains = [];

        foreach ($value as $domain) {
            if (($normalized = $this->nullableString($domain)) === null) {
                throw $this->exception($server, 'uapi_cpanel', 'implausible_domain_inventory', [
                    'cpanel_username' => $username,
                ]);
            }

            $domains[] = $normalized;
        }

        return $domains;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, array<string, mixed>>
     */
    private function domainDetailsByName(array $details): array
    {
        $byDomain = [];

        foreach ($details as $group) {
            if (! is_array($group)) {
                continue;
            }

            $records = array_is_list($group) ? $group : [$group];

            foreach ($records as $record) {
                if (! is_array($record) || ($domain = $this->nullableString($record['domain'] ?? null)) === null) {
                    continue;
                }

                $byDomain[$domain] = $record;
            }
        }

        return $byDomain;
    }

    /** @param array<string, array<string, mixed>> $detailsByDomain */
    private function domainData(
        string $domain,
        DomainType $type,
        ?string $defaultParent,
        array $detailsByDomain,
    ): WhmDomainData {
        $details = $detailsByDomain[$domain] ?? [];

        return new WhmDomainData(
            domain: $domain,
            type: $type,
            documentRoot: $this->nullableString($details['documentroot'] ?? $details['document_root'] ?? null),
            parentDomain: $type === DomainType::Primary
                ? null
                : $this->nullableString($details['rootdomain'] ?? null) ?? $defaultParent,
            metadata: Arr::except($details, ['domain', 'documentroot', 'document_root', 'rootdomain']),
        );
    }

    /** @param list<WhmDomainData> $domains
     * @return list<WhmDomainData>
     */
    private function uniqueDomains(array $domains): array
    {
        $unique = [];

        foreach ($domains as $domain) {
            $unique[$domain->domain] ??= $domain;
        }

        return array_values($unique);
    }

    /**
     * @return array{filesystem: string, mount: ?string, total: ?float, used: ?float, available: ?float, used_percent: ?float}
     */
    private function normalizePartition(mixed $partition, Server $server): array
    {
        if (! is_array($partition) || ($filesystem = $this->nullableString($partition['filesystem'] ?? null)) === null) {
            throw $this->exception($server, 'getdiskusage', 'malformed_response');
        }

        return [
            'filesystem' => $filesystem,
            'mount' => $this->nullableString($partition['mount'] ?? null),
            'total' => $this->nullableFloat($partition['size'] ?? $partition['total'] ?? null),
            'used' => $this->nullableFloat($partition['used'] ?? null),
            'available' => $this->nullableFloat($partition['available'] ?? null),
            'used_percent' => $this->nullableFloat($partition['percentage'] ?? $partition['percent'] ?? null),
        ];
    }

    private function quotaBlocksToBytes(mixed $value, bool $zeroMeansUnlimited): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $blocks = (int) $value;

        if ($blocks < 0 || ($zeroMeansUnlimited && $blocks === 0)) {
            return null;
        }

        return $blocks * self::BYTES_PER_QUOTA_BLOCK;
    }

    private function requiredFloat(mixed $value, Server $server, string $function): float
    {
        if (! is_numeric($value)) {
            throw $this->exception($server, $function, 'malformed_response');
        }

        return (float) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = rtrim(trim($value), '%');
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function uapiReason(mixed $result): mixed
    {
        if (! is_array($result)) {
            return null;
        }

        $errors = $result['errors'] ?? $result['messages'] ?? null;

        return is_array($errors) ? implode('; ', array_filter($errors, 'is_string')) : $errors;
    }

    private function safeReason(Server $server, mixed $reason): ?string
    {
        if (! is_string($reason)) {
            return null;
        }

        $reason = str_replace((string) $server->api_token, '[REDACTED]', $reason);

        return preg_replace('/Authorization:\s*whm\s+[^\s:]+:[^\s]+/i', 'Authorization: [REDACTED]', $reason);
    }

    /** @param array<string, int|string|null> $context */
    private function exception(
        Server $server,
        string $function,
        string $category,
        array $context = [],
    ): WhmApiException {
        return new WhmApiException($category, [
            'server_id' => $server->getKey(),
            'server_hostname' => $server->hostname,
            'function' => $function,
            ...$context,
        ]);
    }
}
