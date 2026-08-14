<?php

namespace App\Models;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspacePlanAssignment extends Model
{
    protected $table = 'workspace_plan_assignments';

    protected $fillable = [
        'workspace_id',
        'workspace_plan_catalog_id',
        'status',
        'is_complimentary',
        'complimentary_reason',
        'complimentary_granted_by_user_id',
        'complimentary_granted_at',
        'additional_business_slots',
    ];

    protected $casts = [
        'status' => WorkspacePlanAssignmentStatus::class,
        'is_complimentary' => 'boolean',
        'complimentary_granted_at' => 'datetime',
        'additional_business_slots' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(WorkspacePlanCatalog::class, 'workspace_plan_catalog_id');
    }
}
