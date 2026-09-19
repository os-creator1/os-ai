<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Contract 18 §8.5 — a platform-owned citation directory. Global reference
 * data: no business_id, and no customer write path (nothing is fillable
 * from a request; rows change by migration/seeder only).
 */
class SeoCitationDirectory extends Model
{
    protected $table = 'seo_citation_directories';

    protected $guarded = ['*'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $directory): void {
            if ($directory->uid === null) {
                $directory->uid = (string) Str::uuid();
            }
        });
    }
}
