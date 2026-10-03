<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings row for the public marketing homepage's owner-editable
 * hero copy. See database/migrations/2026_10_11_120000_create_marketing_content_settings_table.php.
 *
 * Review correction: resolving/creating the singleton row moved to
 * App\Repositories\Eloquent\EloquentMarketingContentSettingsRepository::current(),
 * which is race-safe against the unique `singleton_key` database
 * constraint below — a plain `firstOrCreate([])` here had no such
 * constraint to race against, so two concurrent first requests could each
 * insert their own row.
 */
class MarketingContentSettings extends Model
{
    /**
     * The fixed identity every row-resolving read/write targets. A
     * constant, not a per-row generated value, since exactly one value is
     * ever meant to exist.
     */
    public const SINGLETON_KEY = 'default';

    protected $table = 'marketing_content_settings';

    protected $fillable = [
        'singleton_key',
        'hero_headline',
        'hero_subheadline',
        'updated_by_user_id',
    ];
}
