<?php

namespace App\Models;

use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpportunityRun extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'worker_key',
        'producer_version',
        'status',
        'started_at',
        'heartbeat_at',
        'completed_at',
        'abandoned_at',
        'reason_code',
        'safe_error_summary',
    ];

    protected $casts = [
        'worker_key' => OpportunityWorkerKey::class,
        'status' => OpportunityRunStatus::class,
        'started_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'completed_at' => 'datetime',
        'abandoned_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(OpportunityRunCandidate::class, 'opportunity_run_id');
    }

    public function confirmedOpportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'last_confirmed_run_id');
    }
}
