<?php

namespace Tests\Unit\Inventory;

use App\Support\InventoryFreshness;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class InventoryFreshnessTest extends TestCase
{
    public function test_it_reports_each_inventory_freshness_state(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        config(['estate.inventory.stale_hours' => 26]);

        $freshness = new InventoryFreshness;

        $this->assertSame('Never synced', $freshness->status(null, false));
        $this->assertSame('Syncing', $freshness->status(now()->subDays(3), true));
        $this->assertSame('Current', $freshness->status(now()->subHours(25)->subMinutes(59), false));
        $this->assertSame('Stale', $freshness->status(now()->subHours(26), false));

        Carbon::setTestNow();
    }

    public function test_stale_hours_configuration_is_an_integer_with_the_expected_default(): void
    {
        $this->assertSame(26, config('estate.inventory.stale_hours'));
        $this->assertIsInt(config('estate.inventory.stale_hours'));
    }

    public function test_configured_stale_hours_override_is_used(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        config(['estate.inventory.stale_hours' => 12]);

        $this->assertSame('Stale', (new InventoryFreshness)->status(now()->subHours(12), false));

        Carbon::setTestNow();
    }

    public function test_non_positive_stale_hours_fail_fast(): void
    {
        config(['estate.inventory.stale_hours' => 0]);

        $this->expectException(InvalidArgumentException::class);

        new InventoryFreshness;
    }
}
