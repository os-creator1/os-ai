<?php

namespace App\Models;

use App\Enums\Automation\Workflow\EnrollmentPolicy;
use App\Enums\Automation\Workflow\EnrollmentPolicySource;
use App\Enums\Automation\Workflow\FailurePolicy;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Automation\Workflow\WorkflowVersionState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Automations V2 §4.2/§6 — a draft, or an immutable published version.
 *
 * THREE THINGS LIVE HERE, AND THE SEPARATION IS THE POINT:
 *
 *   `definition`  the editor's document. Autosaved, validated, and read by the
 *                 builder. THE RUNTIME NEVER READS IT.
 *   the compiled  `nodes()` and `edges()` — written once at publish, never
 *   graph        updated, and the only thing execution walks.
 *   the policies  `enrollment_policy`, `enrollment_policy_source`,
 *                 `failure_policy` — denormalised from the trigger node at
 *                 publish and pinned here, so an enrollment reads the policies
 *                 of the version it actually started on.
 *
 * Once `state` leaves draft this row and its graph are immutable. Nothing in
 * the codebase may update a published or superseded version's nodes or edges;
 * the node and edge tables have no `updated_at` so an attempt fails loudly.
 */
class AutomationWorkflowVersion extends Model
{
    use HasUid;

    protected $fillable = [
        'workflow_id',
        'business_id',
        'version_number',
        'state',
        'definition',
        'definition_revision',
        'definition_hash',
        'trigger_type',
        'node_count',
        'enrollment_policy',
        'enrollment_policy_source',
        'failure_policy',
        'published_at',
        'published_by_user_id',
    ];

    protected $casts = [
        'state' => WorkflowVersionState::class,
        'definition' => 'array',
        'definition_revision' => 'integer',
        'node_count' => 'integer',
        'trigger_type' => WorkflowTriggerType::class,
        'enrollment_policy' => EnrollmentPolicy::class,
        'enrollment_policy_source' => EnrollmentPolicySource::class,
        'failure_policy' => FailurePolicy::class,
        'published_at' => 'datetime',
    ];

    /** See AutomationWorkflow::generateUid(). */
    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(AutomationWorkflowNode::class, 'version_id');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(AutomationWorkflowEdge::class, 'version_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(AutomationEnrollment::class, 'version_id');
    }

    public function isDraft(): bool
    {
        return $this->state === WorkflowVersionState::Draft;
    }

    public function isImmutable(): bool
    {
        return $this->state->isImmutable();
    }

    /** The compiled root. Every traversal starts here. */
    public function rootNode(): ?AutomationWorkflowNode
    {
        return $this->nodes()->where('node_type', 'trigger')->first();
    }
}
