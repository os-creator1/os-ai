<?php

namespace App\Models;

use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityCompletionPolicy;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityActionExecution extends Model
{
    use HasUid;

    protected $fillable = [
        'opportunity_id',
        'action_key',
        'recommended_action_hash',
        'action_schema_version',
        'occurrence_number',
        'attempt_number',
        'idempotency_key',
        'status',
        'initiated_by_user_id',
        'initiated_by_type',
        'completion_policy',
        'safe_result_summary',
        'safe_error_summary',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => OpportunityActionExecutionStatus::class,
        'completion_policy' => OpportunityCompletionPolicy::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
