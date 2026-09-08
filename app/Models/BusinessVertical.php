<?php

namespace App\Models;

use App\Enums\Business\BusinessIndustry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Website Guided Generation contract §6.1 -- an operator-controlled
 * catalog, never a PHP enum case per trade. `key` is the stable machine
 * identifier every reference (business_knowledge_profiles.vertical_key,
 * question_packs.applies_to_vertical_key) uses; `display_name` is the
 * only UI-facing label, and is never used as an identifier.
 *
 * Every write through this model (create/update/save) is validated by
 * the `saving` hook below -- this protects `BusinessVertical::create()`/
 * `update()`/`save()` calls only, including this contract's own
 * fixtures and any future seeder or admin surface built on top of this
 * model. It does NOT and cannot protect a hypothetical raw
 * `DB::table('business_verticals')->insert(...)` call, which bypasses
 * Eloquent (and therefore this hook) entirely; no such call exists
 * anywhere in this contract's implementation, and none is authorized.
 */
class BusinessVertical extends Model
{
    private const KEY_PATTERN = '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/';

    private const MAX_KEY_LENGTH = 40;

    private const MAX_DISPLAY_NAME_LENGTH = 80;

    protected $fillable = [
        'key',
        'display_name',
        'broad_industry',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (BusinessVertical $vertical) {
            static::assertValid($vertical);
        });
    }

    private static function assertValid(BusinessVertical $vertical): void
    {
        if (! is_string($vertical->key) || $vertical->key === '' || mb_strlen($vertical->key) > self::MAX_KEY_LENGTH || ! preg_match(self::KEY_PATTERN, $vertical->key)) {
            throw ValidationException::withMessages([
                'key' => ['key must be a lowercase, hyphen/underscore-separated string of at most ' . self::MAX_KEY_LENGTH . ' characters, with no leading, trailing, or repeated separators.'],
            ]);
        }

        if (! is_string($vertical->display_name) || trim($vertical->display_name) === '' || mb_strlen($vertical->display_name) > self::MAX_DISPLAY_NAME_LENGTH) {
            throw ValidationException::withMessages([
                'display_name' => ['display_name is required and must be a non-blank string of at most ' . self::MAX_DISPLAY_NAME_LENGTH . ' characters.'],
            ]);
        }

        if ($vertical->broad_industry !== null && BusinessIndustry::tryFrom($vertical->broad_industry) === null) {
            throw ValidationException::withMessages([
                'broad_industry' => ['Invalid broad_industry value.'],
            ]);
        }

        if (! is_bool($vertical->is_active)) {
            throw ValidationException::withMessages([
                'is_active' => ['is_active must be a boolean.'],
            ]);
        }
    }

    public function questionPacks(): HasMany
    {
        return $this->hasMany(QuestionPack::class, 'applies_to_vertical_key', 'key');
    }
}
