<?php

namespace App\Models;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceMembership extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
        'business_access_scope',
        'is_active',
    ];

    protected $casts = [
        'role' => WorkspaceMembershipRole::class,
        'business_access_scope' => WorkspaceBusinessAccessScope::class,
        'is_active' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function businessAssignments(): HasMany
    {
        return $this->hasMany(WorkspaceMembershipBusiness::class);
    }

    public function assignedBusinesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'workspace_membership_businesses');
    }
}
