<?php

namespace App\Models;

use App\Enums\Seo\SeoDirectoryImportance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Citations V1 — "this niche recommends this catalog directory". Platform-owned
 * reference data (no business_id): written only through
 * SeoCitationCatalogManager, read live for every Business of the niche.
 */
class SeoNicheCitationRecommendation extends Model
{
    protected $table = 'seo_niche_citation_recommendations';

    protected $guarded = ['*'];

    protected $casts = [
        'importance' => SeoDirectoryImportance::class,
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if ($row->uid === null) {
                $row->uid = (string) Str::uuid();
            }
        });
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(SeoCitationDirectory::class, 'seo_citation_directory_id');
    }
}
