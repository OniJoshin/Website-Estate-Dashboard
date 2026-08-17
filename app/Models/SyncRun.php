<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Enums\SyncRunType;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_id', 'type', 'status', 'started_at', 'completed_at', 'batch_id', 'accounts_found', 'accounts_created', 'accounts_updated', 'accounts_removed', 'domains_found', 'domains_created', 'domains_updated', 'domains_removed', 'errors_count', 'error_summary'])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'accounts_found' => 0,
        'accounts_created' => 0,
        'accounts_updated' => 0,
        'accounts_removed' => 0,
        'domains_found' => 0,
        'domains_created' => 0,
        'domains_updated' => 0,
        'domains_removed' => 0,
        'errors_count' => 0,
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SyncRunType::class,
            'status' => SyncRunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
