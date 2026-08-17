<?php

namespace App\Models;

use App\Support\MonitoringThresholds;
use Database\Factories\ServerCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_id', 'checked_at', 'reachable', 'load_1m', 'load_5m', 'load_15m', 'partitions', 'error_message'])]
class ServerCheck extends Model
{
    /** @use HasFactory<ServerCheckFactory> */
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

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'reachable' => 'boolean',
            'partitions' => 'array',
        ];
    }
}
