<?php

namespace App\Models;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'worker_key',
        'type',
        'fingerprint_version',
        'fingerprint',
        'context_key',
        'title',
        'summary',
        'status',
        'freshness',
        'impact',
        'urgency',
        'effort',
        'confidence',
        'goal_relevance_rank',
        'evidence_freshness_rank',
        'priority_score',
        'scoring_version',
        'scored_at',
        'evidence',
        'recommended_action',
        'recommended_action_hash',
        'action_schema_version',
        'occurrence_number',
        'last_confirmed_run_id',
        'last_confirmed_at',
        'first_detected_at',
        'snoozed_until',
        'completed_at',
        'dismissed_at',
        'stale_at',
    ];

    protected $casts = [
        'worker_key' => OpportunityWorkerKey::class,
        'status' => OpportunityStatus::class,
        'freshness' => OpportunityFreshness::class,
        'confidence' => 'decimal:2',
        'evidence' => 'array',
        'recommended_action' => 'array',
        'scored_at' => 'datetime',
        'last_confirmed_at' => 'datetime',
        'first_detected_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'completed_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'stale_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function lastConfirmedRun(): BelongsTo
    {
        return $this->belongsTo(OpportunityRun::class, 'last_confirmed_run_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(OpportunityTransition::class);
    }

    public function actionExecutions(): HasMany
    {
        return $this->hasMany(OpportunityActionExecution::class);
    }
}
