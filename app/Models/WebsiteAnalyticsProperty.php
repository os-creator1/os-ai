<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One measured site (an Agency White Label site or a Business's published Website). See the migration. */
class WebsiteAnalyticsProperty extends Model
{
    public const OWNER_AGENCY_SITE = 'agency_site';

    public const OWNER_WEBSITE = 'website';

    protected $guarded = [];

    protected $casts = [
        'collection_started_at' => 'datetime',
    ];

    public function agencySite(): BelongsTo
    {
        return $this->belongsTo(AgencySite::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WebsiteAnalyticsEvent::class, 'property_id');
    }
}
