<?php

namespace App\Models;

use App\Enums\Website\WebsiteStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Website Generation + Hosting Slice A contract §3/§4/§5.1. One Website
 * per Business (business_id unique, contract §4). Carries two distinct
 * UUID-shaped identifiers: `uid` (internal, HasUid convention, used only
 * in authenticated routes) and `public_id` (a second, independently
 * generated UUID used exclusively by the public /sites/{public_id}
 * route, contract §3.1/§3.2 — Business.uid is never used for this,
 * because it is generated via the unsafe uniqid() trait default despite
 * its column type).
 */
class Website extends Model
{
    use HasUid;

    protected $fillable = [
        'uid',
        'public_id',
        'business_id',
        'name',
        'status',
        'published_revision_id',
        'theme',
    ];

    protected $casts = [
        'status' => WebsiteStatus::class,
        'theme' => 'array',
    ];

    /**
     * websites.uid is a database UUID column; HasUid's default
     * generateUid() uses uniqid(), which is not a valid UUID. Mirrors
     * Workspace::generateUid()/PlatformThemePreset::generateUid()
     * exactly (contract §3.2).
     */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    /**
     * public_id is a SEPARATE identifier from uid (contract §3.2) — it
     * needs its own creating-time generation, independent of HasUid's
     * own creating() hook (which only ever touches `uid`). Model::boot()
     * calls static::boot() then static::booted() as two independent
     * steps (Illuminate\Database\Eloquent\Model::bootIfNotBooted()), so
     * this fires correctly regardless of what HasUid::boot() does.
     */
    protected static function booted(): void
    {
        static::creating(function (Website $website) {
            if (empty($website->public_id)) {
                $website->public_id = (string) Str::uuid();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WebsitePage::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WebsiteRevision::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(WebsiteAsset::class);
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(WebsiteRevision::class, 'published_revision_id');
    }
}
