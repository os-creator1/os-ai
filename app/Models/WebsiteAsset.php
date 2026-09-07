<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Website Generation + Hosting Slice A contract §5.4/§13.1. Tenancy is
 * derived by joining through website_id -> websites.business_id
 * (business_id is deliberately not duplicated here). first_published_at
 * is a durable, monotonic marker (Correction 1) — NULL until the asset
 * first appears in a successful publish, set exactly once, and never
 * cleared afterward. Any asset with a non-null value here can never be
 * physically deleted in Slice A (contract §13.1).
 */
class WebsiteAsset extends Model
{
    use HasUid;

    protected $fillable = [
        'uid',
        'website_id',
        'disk',
        'path',
        'mime_type',
        'size',
        'width',
        'height',
        'alt_text',
        'content_hash',
        'first_published_at',
    ];

    protected $casts = [
        'first_published_at' => 'datetime',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function url(): string
    {
        return asset($this->path);
    }
}
