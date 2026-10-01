<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Website Builder redesign — one photo of a BusinessBackdrop. Same shape
 * as WebsiteAsset for consistency, but owned by the backdrop, not a
 * Website.
 */
class BusinessBackdropImage extends Model
{
    use HasUid;

    protected $fillable = [
        'business_backdrop_id',
        'disk',
        'path',
        'mime_type',
        'size',
        'width',
        'height',
        'alt_text',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function backdrop(): BelongsTo
    {
        return $this->belongsTo(BusinessBackdrop::class, 'business_backdrop_id');
    }

    public function url(): string
    {
        return asset($this->path);
    }
}
