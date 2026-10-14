<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * External Website Audit — one internal link seen on a crawled page: where it is
 * (the source page), where it points and its anchor text. Bounded per page and per
 * crawl; it exists so a broken or redirecting link can be reported WITH the page
 * to fix it on.
 */
class ExternalSiteLink extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['nofollow' => 'boolean'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ExternalSitePage::class, 'source_page_id');
    }
}
