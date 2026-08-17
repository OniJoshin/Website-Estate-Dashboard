<?php

namespace App\Models;

use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_id', 'cpanel_account_id', 'domain_id', 'type', 'severity', 'title', 'details', 'first_detected_at', 'last_detected_at', 'resolved_at'])]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<CpanelAccount, $this> */
    public function cpanelAccount(): BelongsTo
    {
        return $this->belongsTo(CpanelAccount::class);
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
            'type' => IssueType::class,
            'severity' => IssueSeverity::class,
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
