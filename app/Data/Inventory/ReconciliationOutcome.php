<?php

namespace App\Data\Inventory;

final readonly class ReconciliationOutcome
{
    /** @param list<int> $currentIds */
    public function __construct(
        public int $found,
        public int $created,
        public int $updated,
        public int $removed,
        public int $relatedRemoved = 0,
        public array $currentIds = [],
    ) {}
}
