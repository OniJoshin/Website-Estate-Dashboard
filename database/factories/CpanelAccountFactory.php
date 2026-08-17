<?php

namespace Database\Factories;

use App\Models\CpanelAccount;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CpanelAccount>
 */
class CpanelAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $username = fake()->unique()->bothify('acct####');

        return [
            'server_id' => Server::factory(),
            'username' => $username,
            'primary_domain' => $username.'.invalid',
            'home_directory' => '/home/'.$username,
            'package' => 'fixture-package',
            'owner' => 'fixture-owner',
            'suspended' => false,
            'suspension_reason' => null,
            'disk_used_bytes' => 1_048_576,
            'disk_limit_bytes' => 10_485_760,
            'metadata' => ['source' => 'factory'],
            'discovered_at' => now()->subHour(),
            'last_seen_at' => now(),
            'removed_at' => null,
        ];
    }

    public function removed(): static
    {
        return $this->state(fn (array $attributes) => [
            'removed_at' => now()->subMinute(),
        ]);
    }
}
