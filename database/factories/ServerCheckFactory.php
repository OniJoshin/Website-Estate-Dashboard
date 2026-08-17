<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerCheck>
 */
class ServerCheckFactory extends Factory
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
            'checked_at' => now(),
            'reachable' => true,
            'load_1m' => 0.25,
            'load_5m' => 0.20,
            'load_15m' => 0.15,
            'partitions' => [['mount' => '/fixture', 'used_percent' => 42]],
            'error_message' => null,
        ];
    }
}
