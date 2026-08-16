<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformFeatureUsageSafetyLimit extends Model
{
    protected $table = 'platform_feature_usage_safety_limits';

    protected $fillable = [
        'feature_key',
        'max_monthly_limit_micro',
        'updated_by_user_id',
    ];

    protected $casts = [
        'max_monthly_limit_micro' => 'integer',
    ];
}
