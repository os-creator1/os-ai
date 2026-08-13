<?php

namespace App\Models;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No "inherit" row is ever stored — absence of a row already means inherit
 * (RFC-004 §10.5).
 */
class WorkspaceEntitlementOverride extends Model
{
    protected $table = 'workspace_entitlement_overrides';

    protected $fillable = [
        'workspace_id',
        'feature_key',
        'state',
        'reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'feature_key' => PlatformFeature::class,
        'state' => WorkspaceEntitlementOverrideState::class,
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
