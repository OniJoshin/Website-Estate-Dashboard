<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Fixture Server '.fake()->unique()->numerify('####'),
            'hostname' => fake()->unique()->numerify('server-####.invalid'),
            'whm_port' => 2087,
            'api_username' => 'fixture-admin',
            'api_token' => 'fixture-token-'.fake()->unique()->numerify('########'),
            'enabled' => true,
            'last_synced_at' => now()->subMinute(),
            'last_successful_sync_at' => now()->subMinutes(2),
        ];
    }
}
