<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * External Website Audit Mode V1 — the normalised FACTS of one crawled page (no
 * HTML, no body, no headers). Immutable once written.
 */
class ExternalSitePage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'http_status' => 'integer',
        'noindex' => 'boolean',
        'h1_count' => 'integer',
        'internal_link_count' => 'integer',
        'broken_link_count' => 'integer',
        'image_count' => 'integer',
        'images_missing_alt' => 'integer',
        'has_open_graph' => 'boolean',
        'has_json_ld' => 'boolean',
        'word_count' => 'integer',
        'fetched_at' => 'datetime',
    ];

    public function crawl(): BelongsTo
    {
        return $this->belongsTo(ExternalSiteCrawl::class, 'crawl_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ExternalSiteFinding::class, 'page_id');
    }

    /** The page's path (and query) for display: the host is the Business's own. */
    public function displayPath(): string
    {
        $path = (string) parse_url((string) $this->url, PHP_URL_PATH);
        $query = parse_url((string) $this->url, PHP_URL_QUERY);

        return ($path === '' ? '/' : $path).($query ? '?'.$query : '');
    }
}
