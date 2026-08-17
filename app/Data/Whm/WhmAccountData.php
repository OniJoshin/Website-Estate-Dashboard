<?php

namespace App\Data\Whm;

final readonly class WhmAccountData
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $username,
        public ?string $primaryDomain,
        public ?string $homeDirectory,
        public ?string $package,
        public ?string $owner,
        public bool $suspended,
        public ?string $suspensionReason,
        public array $metadata,
    ) {}
}
