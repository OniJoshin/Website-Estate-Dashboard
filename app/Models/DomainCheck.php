<?php

namespace App\Models;

use App\Support\MonitoringThresholds;
use Database\Factories\DomainCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domain_id', 'check_type', 'checked_at', 'successful', 'http_status', 'response_time_ms', 'final_url', 'redirect_count', 'resolved_ips', 'ssl_valid', 'ssl_expires_at', 'ssl_days_remaining', 'error_type', 'error_message'])]
class DomainCheck extends Model
{
    /** @use HasFactory<DomainCheckFactory> */
    use HasFactory;

    use MassPrunable;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where(
            'checked_at',
            '<',
            now()->subDays(app(MonitoringThresholds::class)->retentionDays()),
        );
    }

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'successful' => 'boolean',
            'resolved_ips' => 'array',
            'ssl_valid' => 'boolean',
            'ssl_expires_at' => 'datetime',
        ];
    }
}
