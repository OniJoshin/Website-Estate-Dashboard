<?php

namespace App\Services\Monitoring\Contracts;

use App\Data\Monitoring\DnsResult;

interface DnsResolver
{
    public function resolve(string $hostname): DnsResult;
}
