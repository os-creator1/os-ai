<?php

namespace App\Models;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Business\BusinessStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Business extends Model
{
    use HasUid;

    protected $fillable = [
        'customer_id',
        'name',
        'industry',
        'industry_other',
        'description',
        'email',
        'phone',
        'website_url',
        'google_business_profile_url',
        'facebook_url',
        'instagram_url',
        'country_code',
        'timezone',
        'currency_code',
    ];

    /**
     * additional_location_slots and grandfathered_location_slots (Customer
     * Experience Slice 1A, §7.5.2) are deliberately NOT fillable: they are
     * entitlement state, written only by EntitlementManager under the
     * Business row lock and durably audited.
     */
    protected $casts = [
        'status' => BusinessStatus::class,
        'industry' => BusinessIndustry::class,
        'is_primary' => 'boolean',
        'activated_at' => 'datetime',
        'additional_location_slots' => 'integer',
        'grandfathered_location_slots' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'user_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    public function primaryLocation(): HasOne
    {
        return $this->hasOne(BusinessLocation::class)->where('is_primary', true);
    }

    /**
     * Slice 1A — the locations that consume physical-location capacity.
     * Archived locations stay reachable through locations().
     */
    public function activeLocations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class)->where('lifecycle_state', BusinessLocationLifecycleState::Active->value);
    }

    public function services(): HasMany
    {
        return $this->hasMany(BusinessService::class);
    }

    public function primaryService(): HasOne
    {
        return $this->hasOne(BusinessService::class)
            ->where('is_primary', true)
            ->where('status', BusinessServiceStatus::Active);
    }

    public function opportunityRuns(): HasMany
    {
        return $this->hasMany(OpportunityRun::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
