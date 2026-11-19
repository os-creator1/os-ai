<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tracking configuration of one Website at one scope (see the migration). Written only through
 * App\Library\Website\Tracking\WebsiteTrackingSettings, which validates every value.
 */
class WebsiteTrackingSetting extends Model
{
    public const GLOBAL_SCOPE = '*';

    protected $fillable = [
        'website_id',
        'scope_key',
        'is_enabled',
        'require_consent',
        'google_tag_id',
        'google_ads_id',
        'google_ads_lead_label',
        'meta_pixel_id',
        'head_snippet',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'require_consent' => 'boolean',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
