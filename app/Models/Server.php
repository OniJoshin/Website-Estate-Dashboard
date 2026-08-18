<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'hostname', 'whm_port', 'api_username', 'api_token', 'enabled', 'last_synced_at', 'last_successful_sync_at'])]
#[Hidden(['api_token'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'whm_port' => 2087,
        'enabled' => true,
    ];

    /** @return HasMany<CpanelAccount, $this> */
    public function cpanelAccounts(): HasMany
    {
        return $this->hasMany(CpanelAccount::class);
    }

    /** @return HasManyThrough<Domain, CpanelAccount, $this> */
    public function domains(): HasManyThrough
    {
        return $this->hasManyThrough(Domain::class, CpanelAccount::class);
    }

    /** @return HasMany<ServerCheck, $this> */
    public function serverChecks(): HasMany
    {
        return $this->hasMany(ServerCheck::class);
    }

    /** @return HasOne<ServerCheck, $this> */
    public function latestServerCheck(): HasOne
    {
        return $this->hasOne(ServerCheck::class)->ofMany(['checked_at' => 'max', 'id' => 'max']);
    }

    /** @return HasMany<SyncRun, $this> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    /** @return HasOne<SyncRun, $this> */
    public function latestSyncRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->latestOfMany('started_at');
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
            'api_token' => 'encrypted',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
        ];
    }
}
