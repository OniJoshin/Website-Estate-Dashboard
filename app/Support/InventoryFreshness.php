<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

final class InventoryFreshness
{
    private readonly int $staleHours;

    public function __construct()
    {
        $this->staleHours = Config::integer('estate.inventory.stale_hours');

        if ($this->staleHours < 1) {
            throw new InvalidArgumentException('Inventory stale hours must be positive.');
        }
    }

    public function status(?CarbonInterface $lastSuccessfulSyncAt, bool $syncing): string
    {
        if ($syncing) {
            return 'Syncing';
        }

        if ($lastSuccessfulSyncAt === null) {
            return 'Never synced';
        }

        return $lastSuccessfulSyncAt->lte(now()->subHours($this->staleHours))
            ? 'Stale'
            : 'Current';
    }
}
