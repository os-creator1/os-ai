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
        'confirmed_by_user_id',
        'confirmed_by_type',
        'approval_expires_at',
        'action_cost_payer_type',
        'action_cost_payer_workspace_id',
        'action_cost_currency_code',
        'action_cost_amount_minor_upper_bound',
        'action_cost_unit_count',
        'action_cost_unit_kind',
        'action_cost_basis',
        'action_cost_price_version',
        'action_cost_estimated_at',
        'action_cost_expires_at',
        'action_cost_wallet_sufficient',
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
        'approval_expires_at' => 'datetime',
        'action_cost_estimated_at' => 'datetime',
        'action_cost_expires_at' => 'datetime',
        'action_cost_wallet_sufficient' => 'boolean',
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
