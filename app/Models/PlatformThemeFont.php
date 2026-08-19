<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Design System M2 Slice 1 contract §6.9/§9 item 5. Uploaded webfont
 * registry. safe_filename is server-generated only, never client input.
 */
class PlatformThemeFont extends Model
{
    use SoftDeletes;

    protected $table = 'platform_theme_fonts';

    public $timestamps = false;

    protected $fillable = [
        'safe_filename',
        'original_filename',
        'mime_type',
        'file_size_bytes',
        'storage_path',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
