<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use HasUid;

    protected $fillable = [
        'uid',
        'name',
        'owner_user_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * workspaces.uid is a database UUID column; HasUid's default
     * generateUid() uses uniqid(), which is not a valid UUID. Overriding
     * this instance method (rather than boot()) is enough because HasUid's
     * creating() hook already calls $item->generateUid() polymorphically.
     */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('is_active', true);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /**
     * The lane-A platform subscription (at most one per Workspace —
     * `ps_workspace_unique`). Read-side relation for admin lookup; the
     * subscription itself is only ever written by PlatformSubscriptionFinalizer.
     */
    public function platformSubscription(): HasOne
    {
        return $this->hasOne(PlatformSubscription::class);
    }
}
