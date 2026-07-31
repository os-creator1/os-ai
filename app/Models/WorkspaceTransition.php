<?php

namespace App\Models;

use App\Enums\Workspace\WorkspaceTransitionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only durable audit trail for RFC-003 Milestone 2 (corrected M2-0
 * domain contract §5/§7/§11) — limited to exactly two operations: Workspace
 * ownership transfer and cross-Workspace Business reassignment. The table
 * has created_at only — no updated_at column exists, and no row is ever
 * updated after insert.
 *
 * actor_user_id/from_owner_user_id/to_owner_user_id are immutable scalar
 * IDs with no foreign key (RFC-003 Milestone 2 domain contract, corrected
 * report §9), so no User relationship is exposed for any of them.
 */
class WorkspaceTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'transition_type',
        'actor_user_id',
        'from_owner_user_id',
        'to_owner_user_id',
        'previous_owner_disposition',
        'business_id',
        'from_workspace_id',
    ];

    protected $casts = [
        'transition_type' => WorkspaceTransitionType::class,
        'created_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function fromWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'from_workspace_id');
    }
}
