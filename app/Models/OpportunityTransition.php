<?php

namespace App\Models;

use App\Enums\Opportunity\OpportunityTransitionActorType;
use App\Enums\Opportunity\OpportunityTransitionCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail (RFC-002 §15.5, §41). The table has created_at
 * only — no updated_at column exists, and no row is ever updated after
 * insert.
 */
class OpportunityTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'opportunity_id',
        'category',
        'from_status',
        'to_status',
        'actor_type',
        'actor_user_id',
        'opportunity_run_id',
        'action_execution_id',
        'reason_code',
        'safe_note',
    ];

    protected $casts = [
        'category' => OpportunityTransitionCategory::class,
        'actor_type' => OpportunityTransitionActorType::class,
        'created_at' => 'datetime',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(OpportunityRun::class, 'opportunity_run_id');
    }

    public function actionExecution(): BelongsTo
    {
        return $this->belongsTo(OpportunityActionExecution::class, 'action_execution_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
