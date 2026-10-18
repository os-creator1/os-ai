<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One photo of a BusinessService (a booth experience). Owned by the service,
 * never a Website — same shape as CatalogItemImage / BusinessBackdropImage.
 */
class BusinessServiceImage extends Model
{
    use HasUid;

    protected $fillable = [
        'business_service_id',
        'disk',
        'path',
        'mime_type',
        'size',
        'width',
        'height',
        'alt_text',
        'position',
        'is_cover',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_cover' => 'boolean',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'business_service_id');
    }

    public function url(): string
    {
        return asset($this->path);
    }
}
