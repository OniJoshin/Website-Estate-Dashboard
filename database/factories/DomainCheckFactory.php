<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\DomainCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DomainCheck>
 */
class DomainCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'check_type' => 'http',
            'checked_at' => now(),
            'successful' => true,
            'http_status' => 200,
            'response_time_ms' => 125,
            'final_url' => 'https://site.invalid/',
            'redirect_count' => 0,
            'resolved_ips' => ['192.0.2.10'],
            'ssl_valid' => true,
            'ssl_expires_at' => now()->addDays(60),
            'ssl_days_remaining' => 60,
            'error_type' => null,
            'error_message' => null,
        ];
    }
}
