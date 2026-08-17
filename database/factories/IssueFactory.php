<?php

namespace Database\Factories;

use App\Enums\IssueSeverity;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\Issue;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
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
            'cpanel_account_id' => null,
            'domain_id' => null,
            'type' => 'fixture_issue',
            'severity' => IssueSeverity::Warning,
            'title' => 'Fixture issue',
            'details' => 'Synthetic issue details for automated tests.',
            'first_detected_at' => now()->subMinutes(5),
            'last_detected_at' => now()->subMinute(),
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolved_at' => now(),
        ]);
    }

    public function forServer(Server $server): static
    {
        return $this->state(fn (array $attributes) => [
            'server_id' => $server->id,
            'cpanel_account_id' => null,
            'domain_id' => null,
        ]);
    }

    public function forAccount(CpanelAccount $cpanelAccount): static
    {
        return $this->state(fn (array $attributes) => [
            'server_id' => null,
            'cpanel_account_id' => $cpanelAccount->id,
            'domain_id' => null,
        ]);
    }

    public function forDomain(Domain $domain): static
    {
        return $this->state(fn (array $attributes) => [
            'server_id' => null,
            'cpanel_account_id' => null,
            'domain_id' => $domain->id,
        ]);
    }
}
