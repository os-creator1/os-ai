<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit snapshot written when a member is removed from a Workspace: who was
 * removed, by whom, and exactly what access they held. Never deleted, and
 * closed (reinstated_*) rather than overwritten if the person is later added again.
 */
class WorkspaceMembershipRemoval extends Model
{
    protected $guarded = [];

    protected $casts = [
        'business_ids' => 'array',
        'location_ids' => 'array',
        'removed_at' => 'datetime',
        'reinstated_at' => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(WorkspaceMembership::class, 'workspace_membership_id');
    }
}
