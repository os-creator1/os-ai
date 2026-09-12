<?php

namespace App\Models;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Automations V2 §4.5/§7.1 — one contact's journey through one pinned version.
 *
 * THE PIN. `version_id` is set at enrollment and never changes. Because
 * published versions are immutable, a journey that began on version 3 keeps
 * executing version 3 even after version 4 goes live. The database enforces both
 * halves: the version cannot be deleted while pinned, and a composite foreign key
 * means the pinned version must belong to this enrollment's workflow.
 *
 * THE CURSOR. `current_node_id` is the step about to run, and it is constrained
 * to a node of the pinned version. `status = active` means that step is
 * executable now; `waiting` means not until `resume_at`. Nothing else is
 * non-terminal — Resume relies on exactly that.
 *
 * THE CLAIM. `enrollment_key` is UNIQUE, so "already entered" is a database
 * fact. `active_contact_guard` additionally forbids the same contact occupying
 * this workflow twice at once, whatever the policy says.
 */
class AutomationEnrollment extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'workflow_id',
        'version_id',
        'contact_id',
        'status',
        'current_node_id',
        'resume_at',
        'trigger_type',
        'trigger_occurrence_key',
        'enrollment_key',
        'causation_depth',
        'step_count',
        'enrolled_at',
        'last_advanced_at',
        'completed_at',
        'exit_reason',
    ];

    protected $casts = [
        'status' => EnrollmentStatus::class,
        'trigger_type' => WorkflowTriggerType::class,
        'causation_depth' => 'integer',
        'step_count' => 'integer',
        'resume_at' => 'datetime',
        'enrolled_at' => 'datetime',
        'last_advanced_at' => 'datetime',
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

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    /** The pinned version. Never reassigned. */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'version_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }

    public function currentNode(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowNode::class, 'current_node_id');
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(AutomationStepRun::class, 'enrollment_id');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /** `active` means the cursor node is executable now (§7.1). */
    public function isExecutableNow(): bool
    {
        return $this->status === EnrollmentStatus::Active && $this->current_node_id !== null;
    }

    public function isWaiting(): bool
    {
        return $this->status === EnrollmentStatus::Waiting;
    }
}
