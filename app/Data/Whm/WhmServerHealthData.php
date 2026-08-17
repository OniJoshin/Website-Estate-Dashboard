<?php

namespace App\Data\Whm;

final readonly class WhmServerHealthData
{
    /**
     * @param list<array{
     *     filesystem: string,
     *     mount: ?string,
     *     total: ?float,
     *     used: ?float,
     *     available: ?float,
     *     used_percent: ?float
     * }> $partitions
     */
    public function __construct(
        public float $load1,
        public float $load5,
        public float $load15,
        public array $partitions,
    ) {}
}
