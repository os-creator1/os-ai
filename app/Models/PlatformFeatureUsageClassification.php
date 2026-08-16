<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFeatureUsageClassification extends Model
{
    protected $table = 'platform_feature_usage_classifications';

    protected $fillable = [
        'feature_key',
        'is_metered',
        'active_rate_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_metered' => 'boolean',
    ];

    public function activeRate(): BelongsTo
    {
        return $this->belongsTo(BusinessUsageRate::class, 'active_rate_id');
    }
}
