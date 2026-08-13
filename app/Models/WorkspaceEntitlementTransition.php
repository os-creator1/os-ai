<?php

namespace App\Models;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only durable audit trail (RFC-004 §10.4/§21) — created_at only, no
 * updated_at, no row is ever updated after insert.
 *
 * actor_user_id/created_by-style identity columns are deliberately plain
 * scalars with no foreign key, mirroring workspace_transitions.actor_user_id
 * (RFC-003) — must never block a legitimate user-deletion feature.
 */
class WorkspaceEntitlementTransition extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'workspace_entitlement_transitions';

    protected $fillable = [
        'workspace_id',
        'transition_type',
        'actor_user_id',
        'from_plan_catalog_id',
        'to_plan_catalog_id',
        'feature_key',
        'from_override_state',
        'to_override_state',
        'from_additional_business_slots',
        'to_additional_business_slots',
        'from_status',
        'to_status',
        'reason',
    ];

    protected $casts = [
        'transition_type' => WorkspaceEntitlementTransitionType::class,
        'feature_key' => PlatformFeature::class,
        'from_override_state' => WorkspaceEntitlementOverrideState::class,
        'to_override_state' => WorkspaceEntitlementOverrideState::class,
        'from_additional_business_slots' => 'integer',
        'to_additional_business_slots' => 'integer',
        'from_status' => WorkspacePlanAssignmentStatus::class,
        'to_status' => WorkspacePlanAssignmentStatus::class,
        'created_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function fromPlanCatalog(): BelongsTo
    {
        return $this->belongsTo(WorkspacePlanCatalog::class, 'from_plan_catalog_id');
    }

    public function toPlanCatalog(): BelongsTo
    {
        return $this->belongsTo(WorkspacePlanCatalog::class, 'to_plan_catalog_id');
    }
}
