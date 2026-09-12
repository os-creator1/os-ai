<?php

namespace App\Models;

use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowEdgeKind;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Automations V2 §4.6 — one row per step executed.
 *
 * `UNIQUE(enrollment_id, node_id)` carries the at-most-once guarantee. The row
 * is inserted already `started`, inside the short transaction that verified the
 * enrollment's cursor under a row lock, and BEFORE any provider call — so a
 * duplicated job loses the insert and stops, and a text message cannot be sent
 * twice. That is B4 §5.1 inherited intact, with the tree's "a node is visited at
 * most once" doing the work B4's flat ledger needed a second start-claim for.
 *
 * Only bounded, human-safe summaries are stored: never a provider response body,
 * never a credential, never the full outbound message.
 */
class AutomationStepRun extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'enrollment_id',
        'node_id',
        'node_type',
        'status',
        'branch_taken',
        'started_at',
        'completed_at',
        'safe_result_summary',
        'safe_error_summary',
    ];

    protected $casts = [
        'node_type' => WorkflowNodeType::class,
        'status' => StepRunStatus::class,
        'branch_taken' => WorkflowEdgeKind::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** See AutomationWorkflow::generateUid(). */
    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(AutomationEnrollment::class, 'enrollment_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowNode::class, 'node_id');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether the recovery sweep should treat this as interrupted if its
     * enrollment has gone stale (§7.4). What it may then do depends on the
     * node type's side-effect class — an external step is never re-executed.
     */
    public function isInterrupted(): bool
    {
        return $this->status->isInterruptible();
    }
}
