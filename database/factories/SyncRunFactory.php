<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use App\Models\Server;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'type' => SyncRunType::Inventory,
            'status' => SyncRunStatus::Successful,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'batch_id' => null,
            'accounts_found' => 2,
            'accounts_created' => 1,
            'accounts_updated' => 1,
            'accounts_removed' => 0,
            'domains_found' => 3,
            'domains_created' => 1,
            'domains_updated' => 2,
            'domains_removed' => 0,
            'errors_count' => 0,
            'error_summary' => null,
        ];
    }
}
