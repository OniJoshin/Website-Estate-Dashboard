<?php

namespace App\Data\Whm;

final readonly class WhmDomainInventory
{
    /** @param list<WhmDomainData> $domains */
    public function __construct(public array $domains) {}
}
