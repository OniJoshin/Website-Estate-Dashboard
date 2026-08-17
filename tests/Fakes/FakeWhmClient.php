<?php

namespace Tests\Fakes;

use App\Data\Whm\WhmAccountData;
use App\Data\Whm\WhmDomainInventory;
use App\Data\Whm\WhmServerHealthData;
use App\Models\Server;
use App\Services\Whm\Contracts\WhmClient;
use Throwable;

final class FakeWhmClient implements WhmClient
{
    /** @var list<WhmAccountData> */
    public array $accounts = [];

    /** @var array<string, array{used_bytes: ?int, limit_bytes: ?int}> */
    public array $diskUsage = [];

    /** @var array<string, WhmDomainInventory> */
    public array $domainInventories = [];

    public ?Throwable $accountsException = null;

    public ?Throwable $diskUsageException = null;

    /** @var array<string, Throwable> */
    public array $domainExceptions = [];

    public int $accountCalls = 0;

    public int $diskUsageCalls = 0;

    /** @var list<string> */
    public array $domainCalls = [];

    public function listAccounts(Server $server): array
    {
        $this->accountCalls++;

        if ($this->accountsException !== null) {
            throw $this->accountsException;
        }

        return $this->accounts;
    }

    public function listDomains(Server $server, string $username): WhmDomainInventory
    {
        $this->domainCalls[] = $username;

        if (isset($this->domainExceptions[$username])) {
            throw $this->domainExceptions[$username];
        }

        return $this->domainInventories[$username] ?? new WhmDomainInventory([]);
    }

    public function getServerHealth(Server $server): WhmServerHealthData
    {
        return new WhmServerHealthData(0.0, 0.0, 0.0, []);
    }

    public function getAccountDiskUsage(Server $server): array
    {
        $this->diskUsageCalls++;

        if ($this->diskUsageException !== null) {
            throw $this->diskUsageException;
        }

        return $this->diskUsage;
    }

    public function testConnection(Server $server): void {}
}
