<?php

namespace App\Services\Whm\Contracts;

use App\Data\Whm\WhmAccountData;
use App\Data\Whm\WhmDomainInventory;
use App\Data\Whm\WhmServerHealthData;
use App\Models\Server;

interface WhmClient
{
    /** @return list<WhmAccountData> */
    public function listAccounts(Server $server): array;

    public function listDomains(Server $server, string $username): WhmDomainInventory;

    public function getServerHealth(Server $server): WhmServerHealthData;

    /**
     * @return array<string, array{used_bytes: ?int, limit_bytes: ?int}>
     */
    public function getAccountDiskUsage(Server $server): array;

    public function testConnection(Server $server): void;
}
