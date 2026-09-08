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

    protected $casts = [
        'status' => BusinessStatus::class,
        'industry' => BusinessIndustry::class,
        // Slice 1A physical-location capacity counters. Deliberately NOT
        // fillable: they are mutated only by EntitlementManager under the
        // Business row lock, never by mass assignment.
        'additional_location_slots' => 'integer',
        'grandfathered_location_slots' => 'integer',
        'is_primary' => 'boolean',
        'activated_at' => 'datetime',
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

    /**
     * Slice 1A — locations that consume physical-location capacity.
     * Archived locations are retained and fully readable through
     * locations(); they simply do not count (contract §7.3a rule 1).
     */
    public function activeLocations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class)
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value);
    }

    public function primaryLocation(): HasOne
    {
        return $this->hasOne(BusinessLocation::class)->where('is_primary', true);
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
