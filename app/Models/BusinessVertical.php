<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Website Guided Generation contract §6.1 -- an operator-controlled
 * catalog, never a PHP enum case per trade. `key` is the stable machine
 * identifier every reference (business_knowledge_profiles.vertical_key,
 * question_packs.applies_to_vertical_key) uses; `display_name` is the
 * only UI-facing label, and is never used as an identifier.
 */
class BusinessVertical extends Model
{
    protected $fillable = [
        'key',
        'display_name',
        'broad_industry',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function questionPacks(): HasMany
    {
        return $this->hasMany(QuestionPack::class, 'applies_to_vertical_key', 'key');
    }
}
