<?php

namespace App\Models;

use App\Enums\Entitlement\PlatformFeature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row's mere existence is "disabled here" — there is no boolean/"enabled"
 * column and no "allow" state (RFC-004 §10.6). Re-enabling deletes the row.
 */
class BusinessFeatureToggle extends Model
{
    protected $table = 'business_feature_toggles';

    protected $fillable = [
        'business_id',
        'feature_key',
        'reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'feature_key' => PlatformFeature::class,
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
