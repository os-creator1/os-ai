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
    ];

    protected $casts = [
        'service_mode' => BusinessServiceMode::class,
        'public_address' => 'boolean',
        'is_primary' => 'boolean',
        'service_area_cities' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
