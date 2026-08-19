<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Design System M2 Slice 1 contract §6.1/§9 item 4. A named, mutable
 * theme preset. status holds only the three genuine lifecycle values
 * ('draft'|'saved'|'active'); is_factory is an independent protected
 * marker, checked separately everywhere edit/delete is attempted,
 * regardless of the row's own status.
 */
class PlatformThemePreset extends Model
{
    use HasUid;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SAVED = 'saved';
    public const STATUS_ACTIVE = 'active';

    public const FONT_CHOICE_BUNDLED = 'bundled';
    public const FONT_CHOICE_UPLOADED = 'uploaded';

    protected $table = 'platform_theme_presets';

    protected $fillable = [
        'uid',
        'name',
        'status',
        'is_factory',
        'input_tokens_json',
        'overrides_json',
        'derived_tokens_json',
        'font_choice',
        'font_bundled_key',
        'font_uploaded_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_factory' => 'boolean',
        'input_tokens_json' => 'array',
        'overrides_json' => 'array',
        'derived_tokens_json' => 'array',
    ];

    /**
     * platform_theme_presets.uid is a database UUID column; HasUid's
     * default generateUid() uses uniqid(), which is not a valid UUID.
     */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSaved(): bool
    {
        return $this->status === self::STATUS_SAVED;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
