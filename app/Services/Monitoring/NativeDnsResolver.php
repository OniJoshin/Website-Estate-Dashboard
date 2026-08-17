<?php

namespace App\Services\Monitoring;

use App\Data\Monitoring\DnsResult;
use App\Services\Monitoring\Contracts\DnsResolver;
use Closure;

final class NativeDnsResolver implements DnsResolver
{
    /**
     * @var Closure(string, int): (array<int, array<string, mixed>>|false)
     */
    private Closure $lookup;

    /** @param (Closure(string, int): (array<int, array<string, mixed>>|false))|null $lookup */
    public function __construct(?Closure $lookup = null)
    {
        $this->lookup = $lookup ?? static fn (string $hostname, int $type): array|false => dns_get_record($hostname, $type);
    }

    public function resolve(string $hostname): DnsResult
    {
        $aRecords = $this->lookup($hostname, DNS_A);
        $aaaaRecords = $this->lookup($hostname, DNS_AAAA);
        $cnameRecords = $this->lookup($hostname, DNS_CNAME);

        $a = $this->normalizeAddresses($aRecords, 'A', 'ip', FILTER_FLAG_IPV4);
        $aaaa = $this->normalizeAddresses($aaaaRecords, 'AAAA', 'ipv6', FILTER_FLAG_IPV6);
        $cname = $this->normalizeCnames($cnameRecords);

        if ($a !== [] || $aaaa !== [] || $cname !== []) {
            return DnsResult::resolved($a, $aaaa, $cname);
        }

        if ($aRecords === false || $aaaaRecords === false || $cnameRecords === false) {
            return DnsResult::failure('resolver_error', 'DNS resolution failed.');
        }

        return DnsResult::failure('no_records', 'No relevant DNS records were found.');
    }

    /**
     * @param  array<int, array<string, mixed>>|false  $records
     * @return list<string>
     */
    private function normalizeAddresses(array|false $records, string $type, string $valueKey, int $filterFlag): array
    {
        $values = [];

        foreach ($records ?: [] as $record) {
            $value = $record[$valueKey] ?? null;

            if (($record['type'] ?? null) !== $type
                || ! is_string($value)
                || filter_var($value, FILTER_VALIDATE_IP, $filterFlag) === false
            ) {
                continue;
            }

            $values[] = $value;
        }

        return $this->uniqueSorted($values);
    }

    /**
     * @param  array<int, array<string, mixed>>|false  $records
     * @return list<string>
     */
    private function normalizeCnames(array|false $records): array
    {
        $values = [];

        foreach ($records ?: [] as $record) {
            $target = $record['target'] ?? null;

            if (($record['type'] ?? null) !== 'CNAME' || ! is_string($target)) {
                continue;
            }

            $target = strtolower(rtrim(trim($target), '.'));

            if ($target === '' || filter_var($target, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                continue;
            }

            $values[] = $target;
        }

        return $this->uniqueSorted($values);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /** @return array<int, array<string, mixed>>|false */
    private function lookup(string $hostname, int $type): array|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return ($this->lookup)($hostname, $type);
        } finally {
            restore_error_handler();
        }
    }
}
