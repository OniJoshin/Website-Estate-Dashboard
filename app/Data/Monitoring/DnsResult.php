<?php

namespace App\Data\Monitoring;

use InvalidArgumentException;

final readonly class DnsResult
{
    /**
     * @param  list<string>  $a
     * @param  list<string>  $aaaa
     * @param  list<string>  $cname
     */
    private function __construct(
        public bool $successful,
        public array $a,
        public array $aaaa,
        public array $cname,
        public ?string $errorType,
        public ?string $errorMessage,
    ) {}

    /**
     * @param  list<string>  $a
     * @param  list<string>  $aaaa
     * @param  list<string>  $cname
     */
    public static function resolved(array $a, array $aaaa, array $cname): self
    {
        if ($a === [] && $aaaa === [] && $cname === []) {
            throw new InvalidArgumentException('A resolved DNS result requires at least one record.');
        }

        return new self(true, $a, $aaaa, $cname, null, null);
    }

    public static function failure(string $errorType, string $errorMessage): self
    {
        if ($errorType === '' || $errorMessage === '') {
            throw new InvalidArgumentException('A failed DNS result requires an error type and message.');
        }

        return new self(false, [], [], [], $errorType, $errorMessage);
    }
}
