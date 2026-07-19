<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityRunCandidate extends Model
{
    use HasUid;

    protected $fillable = [
        'opportunity_run_id',
        'type',
        'fingerprint_version',
        'fingerprint',
        'context_key',
        'title',
        'summary',
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
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'evidence' => 'array',
        'recommended_action' => 'array',
        'scored_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(OpportunityRun::class, 'opportunity_run_id');
    }
}
