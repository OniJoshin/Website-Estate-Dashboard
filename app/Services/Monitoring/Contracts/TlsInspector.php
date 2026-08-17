<?php

namespace App\Services\Monitoring\Contracts;

use App\Data\Monitoring\TlsResult;

interface TlsInspector
{
    public function inspect(string $hostname, int $port = 443): TlsResult;
}
