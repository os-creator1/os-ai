<?php

namespace App\Models;

use App\Enums\Automation\AutomationExecutionStatus;
use App\Enums\Automation\AutomationTriggerType;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B4 Business Automations — one row per logical execution in the
 * authoritative `automation_executions` ledger (contract §4, §11).
 *
 * The row's mere existence (any status) is the durable at-most-once claim
 * for its `idempotency_key` (§5): it is created BEFORE any provider call,
 * and a later automatic attempt for the same key must find it and stop.
 * Only bounded, human-safe summaries are ever stored here — never
 * provider bodies, credentials, or the full outbound message.
 */
class AutomationExecution extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'automation_id',
        'contact_id',
        'trigger_type',
        'idempotency_key',
        'status',
        'action_claimed_at',
        'started_at',
        'completed_at',
        'safe_result_summary',
        'safe_error_summary',
    ];

    protected $casts = [
        'trigger_type' => AutomationTriggerType::class,
        'status' => AutomationExecutionStatus::class,
        'action_claimed_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class, 'automation_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }

    public function isPending(): bool
    {
        return $this->status === AutomationExecutionStatus::Pending;
    }
}
