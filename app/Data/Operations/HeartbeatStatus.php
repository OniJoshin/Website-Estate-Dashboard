<?php

namespace App\Data\Operations;

use Carbon\CarbonImmutable;

final readonly class HeartbeatStatus
{
    public function __construct(
        public string $state,
        public ?CarbonImmutable $recordedAt,
    ) {}
}
