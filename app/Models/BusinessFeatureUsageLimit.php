<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessFeatureUsageLimit extends Model
{
    protected $table = 'business_feature_usage_limits';

    protected $fillable = [
        'business_id',
        'feature_key',
        'monthly_limit_micro',
        'updated_by_user_id',
    ];

    protected $casts = [
        'monthly_limit_micro' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
