<?php

namespace App\Models;

use Database\Factories\CpanelAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['server_id', 'username', 'primary_domain', 'home_directory', 'package', 'owner', 'suspended', 'suspension_reason', 'disk_used_bytes', 'disk_limit_bytes', 'metadata', 'discovered_at', 'last_seen_at', 'removed_at'])]
class CpanelAccount extends Model
{
    /** @use HasFactory<CpanelAccountFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['suspended' => false];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'suspended' => 'boolean',
            'metadata' => 'array',
            'discovered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
