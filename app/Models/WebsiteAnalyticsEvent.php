<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One anonymous funnel event. Written only by App\Library\WebsiteAnalytics\AnalyticsRecorder. */
class WebsiteAnalyticsEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
