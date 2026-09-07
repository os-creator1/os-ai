<?php

namespace App\Models;

use App\Enums\Business\BusinessServiceMode;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessLocation extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'name',
        'service_mode',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country_code',
        'latitude',
        'longitude',
        'public_address',
        'service_radius_km',
        'service_area_cities',
        // Website Guided Generation contract §5.5 (Slice 1 allowlist
        // correction -- see the implementation report): hours are a
        // business_locations fact, never duplicated on the Business
        // Knowledge Profile. Written exclusively by
        // BusinessKnowledgeProfileManager::updateLocationHours().
        'hours',
        'hours_source',
        'hours_verification_status',
        'hours_verified_by_user_id',
        'hours_verified_at',
    ];

    protected $casts = [
        'service_mode' => BusinessServiceMode::class,
        'public_address' => 'boolean',
        'is_primary' => 'boolean',
        'service_area_cities' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'hours' => 'array',
        'hours_verified_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
