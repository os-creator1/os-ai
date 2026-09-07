<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Website Generation + Hosting Slice A contract §5.2/§6 — a flat,
 * mutable DRAFT page. The public renderer never reads this table
 * directly (contract §9) — only an immutable WebsiteRevision snapshot.
 * All write access to title/slug/seo_title/meta_description/noindex/
 * sections MUST go through App\Library\Website\WebsiteDraftPageService
 * (contract §17.1) — this model itself performs no validation, exactly
 * like every other Eloquent model in this codebase.
 */
class WebsitePage extends Model
{
    use HasUid;

    protected $fillable = [
        'uid',
        'website_id',
        'title',
        'slug',
        'is_home',
        'sections',
        'seo_title',
        'meta_description',
        'noindex',
        'sort_order',
    ];

    protected $casts = [
        'is_home' => 'boolean',
        'sections' => 'array',
        'noindex' => 'boolean',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
