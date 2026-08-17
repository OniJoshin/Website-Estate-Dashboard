<?php

namespace App\Data\Monitoring;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class TlsResult
{
    private function __construct(
        public bool $successful,
        public bool $sslValid,
        public ?CarbonImmutable $expiresAt,
        public ?int $daysRemaining,
        public ?string $errorType,
        public ?string $errorMessage,
    ) {}

    public static function valid(CarbonImmutable $expiresAt, int $daysRemaining): self
    {
        if ($daysRemaining < 0) {
            throw new InvalidArgumentException('TLS days remaining cannot be negative.');
        }

        return new self(true, true, $expiresAt, $daysRemaining, null, null);
    }

    public static function failure(string $errorType, string $errorMessage): self
    {
        if ($errorType === '' || $errorMessage === '') {
            throw new InvalidArgumentException('A failed TLS result requires an error type and message.');
        }

        return new self(false, false, null, null, $errorType, $errorMessage);
    }
}
