<?php

namespace Database\Factories;

use App\Enums\DomainClassification;
use App\Enums\DomainClassificationSource;
use App\Enums\DomainType;
use App\Models\CpanelAccount;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->unique()->numerify('site-####.invalid');

        return [
            'cpanel_account_id' => CpanelAccount::factory(),
            'domain' => $domain,
            'type' => DomainType::Primary,
            'parent_domain_id' => null,
            'document_root' => '/home/fixture/public_html/'.$domain,
            'classification' => DomainClassification::Website,
            'classification_source' => DomainClassificationSource::Auto,
            'monitoring_enabled' => true,
            'is_active' => true,
            'metadata' => ['source' => 'factory'],
            'discovered_at' => now()->subHour(),
            'last_seen_at' => now(),
            'removed_at' => null,
        ];
    }

    public function removed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'removed_at' => now()->subMinute(),
        ]);
    }
}
