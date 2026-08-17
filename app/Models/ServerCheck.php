<?php

namespace App\Models;

use Database\Factories\ServerCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_id', 'checked_at', 'reachable', 'load_1m', 'load_5m', 'load_15m', 'partitions', 'error_message'])]
class ServerCheck extends Model
{
    /** @use HasFactory<ServerCheckFactory> */
    use HasFactory;

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
