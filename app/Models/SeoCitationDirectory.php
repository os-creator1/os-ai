<?php

namespace App\Models;

use App\Enums\Seo\SeoDirectoryImportance;
use App\Enums\Seo\SeoDirectoryTrackingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Contract 18 §8.5 — a citation directory.
 *
 * Two kinds share this table:
 *  - PLATFORM catalog rows (business_id NULL): global reference data with no
 *    customer write path; they change by migration/seeder or the Platform
 *    Owner's catalog screen (SeoCitationCatalogManager).
 *  - A Business's own CUSTOM directory (business_id set): owner-scoped,
 *    always manual, written only by SeoCitationManager.
 *
 * Nothing is mass-assignable from a request; both writers use forceFill.
 */
class SeoCitationDirectory extends Model
{
    protected $table = 'seo_citation_directories';

    protected $guarded = ['*'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_platform_core' => 'boolean',
        'sort_order' => 'integer',
        'importance' => SeoDirectoryImportance::class,
        'tracking_mode' => SeoDirectoryTrackingMode::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $directory): void {
            if ($directory->uid === null) {
                $directory->uid = (string) Str::uuid();
            }
        });
    }

    public function isCustom(): bool
    {
        return $this->business_id !== null;
    }
}
