<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 02 (Location ACL Foundation) §5 — the canonical
 * equivalent of WorkspaceMembershipBusiness, mirrored exactly, for the
 * workspace_membership_locations grant table.
 *
 * Necessary addition beyond §12's literal "New files" list: the Eloquent
 * repository this contract requires (EloquentBaseRepository::__construct(Model $model))
 * cannot exist without a concrete Model to inject — see the implementation
 * report for the full note.
 */
class WorkspaceMembershipLocation extends Model
{
    protected $fillable = [
        'workspace_membership_id',
        'business_location_id',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(WorkspaceMembership::class, 'workspace_membership_id');
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }
}
