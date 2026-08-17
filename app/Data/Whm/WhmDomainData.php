<?php

namespace App\Data\Whm;

use App\Enums\DomainType;

final readonly class WhmDomainData
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $domain,
        public DomainType $type,
        public ?string $documentRoot,
        public ?string $parentDomain,
        public array $metadata,
    ) {}
}
