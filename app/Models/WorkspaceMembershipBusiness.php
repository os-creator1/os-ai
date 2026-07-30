<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMembershipBusiness extends Model
{
    protected $fillable = [
        'workspace_membership_id',
        'business_id',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(WorkspaceMembership::class, 'workspace_membership_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
