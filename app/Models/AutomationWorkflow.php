<?php

namespace App\Models;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Automations V2 §4.1 — a workflow's stable identity.
 *
 * This row is deliberately thin. It holds identity, a name, lifecycle status and
 * a pointer to the live version; it holds NO behavioural setting, because every
 * such setting belongs with the trigger on the version and must be pinned with
 * it. Putting the enrollment or failure policy here would let a Settings edit
 * change how the live published version behaves before anything was published.
 *
 * A workflow belongs to exactly one Business, and `business_id` is NOT NULL
 * behind a restricting foreign key — there is no such thing as a
 * Business-less v2 workflow, unlike the legacy B4 rows that could not be
 * resolved.
 */
class AutomationWorkflow extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'name',
        'status',
        'published_version_id',
        'legacy_automation_id',
        'created_by_user_id',
        'archived_at',
    ];

    protected $casts = [
        'status' => WorkflowStatus::class,
        'archived_at' => 'datetime',
    ];

    /**
     * The repository's HasUid mints `uniqid()`, which is neither a UUID nor what
     * a 36-character uuid column is shaped for. Slice 3 set the precedent of
     * minting a real UUID at the write site; this keeps HasUid's route-key and
     * lookup behaviour while fixing the format.
     */
    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationWorkflowVersion::class, 'workflow_id');
    }

    /** The live version. NULL until the first publish. */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'published_version_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(AutomationEnrollment::class, 'workflow_id');
    }

    /** The editable version, if one exists. At most one, DB-enforced. */
    public function draftVersion(): ?AutomationWorkflowVersion
    {
        return $this->versions()->where('state', 'draft')->first();
    }

    public function isPublished(): bool
    {
        return $this->status === WorkflowStatus::Published;
    }

    public function acceptsNewEnrollments(): bool
    {
        return $this->status->acceptsNewEnrollments() && $this->published_version_id !== null;
    }

    public function permitsExecution(): bool
    {
        return $this->status->permitsExecution();
    }
}
