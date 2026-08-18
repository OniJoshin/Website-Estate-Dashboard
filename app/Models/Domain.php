<?php

namespace App\Models;

use App\Enums\DomainClassification;
use App\Enums\DomainClassificationSource;
use App\Enums\DomainType;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['cpanel_account_id', 'domain', 'type', 'parent_domain_id', 'document_root', 'classification', 'classification_source', 'monitoring_enabled', 'is_active', 'metadata', 'discovered_at', 'last_seen_at', 'removed_at'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'classification_source' => 'auto',
        'monitoring_enabled' => true,
        'is_active' => true,
    ];

    /** @return BelongsTo<CpanelAccount, $this> */
    public function cpanelAccount(): BelongsTo
    {
        return $this->belongsTo(CpanelAccount::class);
    }

    /** @return BelongsTo<Domain, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_domain_id');
    }

    /** @return HasMany<Domain, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_domain_id');
    }

    /** @return HasMany<DomainCheck, $this> */
    public function domainChecks(): HasMany
    {
        return $this->hasMany(DomainCheck::class);
    }

    /** @return HasOne<DomainCheck, $this> */
    public function latestHttpCheck(): HasOne
    {
        return $this->hasOne(DomainCheck::class)
            ->ofMany(['checked_at' => 'max', 'id' => 'max'], fn ($query) => $query->where('check_type', 'http'));
    }

    /** @return HasOne<DomainCheck, $this> */
    public function latestDnsCheck(): HasOne
    {
        return $this->hasOne(DomainCheck::class)
            ->ofMany(['checked_at' => 'max', 'id' => 'max'], fn ($query) => $query->where('check_type', 'dns'));
    }

    /** @return HasOne<DomainCheck, $this> */
    public function latestTlsCheck(): HasOne
    {
        return $this->hasOne(DomainCheck::class)
            ->ofMany(['checked_at' => 'max', 'id' => 'max'], fn ($query) => $query->where('check_type', 'tls'));
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
            'type' => DomainType::class,
            'classification' => DomainClassification::class,
            'classification_source' => DomainClassificationSource::class,
            'monitoring_enabled' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'discovered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
