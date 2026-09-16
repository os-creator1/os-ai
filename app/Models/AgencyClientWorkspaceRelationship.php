<?php

namespace App\Models;

use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One Agency Workspace's management relationship with one Client Workspace
 * (Addendum §2, Implementation Contract 01 §5).
 *
 * The row is its own audit record: who established it and when, and — once
 * terminated — who ended it, when, and why. It is never hard-deleted, so a
 * Client Workspace's full management history is always readable.
 *
 * An Active row is the canonical management LINK. It is never, on its own,
 * proof that the Agency Workspace still holds Agency entitlement (Contract 01
 * §6): the tier is checked once, when the relationship is established, and a
 * later downgrade does not terminate the row. Every Agency-only capability
 * that consumes this relationship re-checks current entitlement itself.
 */
class AgencyClientWorkspaceRelationship extends Model
{
    use HasUid;

    protected $fillable = [
        'uid',
        'agency_workspace_id',
        'client_workspace_id',
        'status',
        'established_by_user_id',
        'established_at',
        'terminated_by_user_id',
        'terminated_at',
        'termination_reason',
    ];

    protected $casts = [
        'status' => AgencyClientRelationshipStatus::class,
        'established_at' => 'datetime',
        'terminated_at' => 'datetime',
    ];

    /**
     * uid is a database UUID column; HasUid's default generateUid() uses
     * uniqid(), which is not a valid UUID. Overriding this instance method
     * (rather than boot()) is enough because HasUid's creating() hook already
     * calls $item->generateUid() polymorphically — the same override
     * Workspace itself carries, for the same reason.
     */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function agencyWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'agency_workspace_id');
    }

    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'client_workspace_id');
    }

    public function isActive(): bool
    {
        return $this->status === AgencyClientRelationshipStatus::Active;
    }
}
