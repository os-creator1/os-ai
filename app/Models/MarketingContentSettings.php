<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings row for the public marketing homepage's owner-editable
 * hero copy. See database/migrations/2026_10_11_120000_create_marketing_content_settings_table.php.
 */
class MarketingContentSettings extends Model
{
    protected $table = 'marketing_content_settings';

    protected $fillable = [
        'hero_headline',
        'hero_subheadline',
        'updated_by_user_id',
    ];

    /**
     * The one settings row, created on first access rather than requiring a
     * seeder — a fresh install has no marketing copy configured yet, and
     * that is a valid, renderable state (the homepage falls back to plain
     * defaults), not an error.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
