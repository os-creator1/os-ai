<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Website Builder redesign — a Package/Product's own image(s). Owned by
 * the CatalogItem (never a Website), so it survives a website rebuild or
 * deletion — see the create_catalog_item_images_table migration for why
 * WebsiteAsset is not reused here.
 */
class CatalogItemImage extends Model
{
    use HasUid;

    protected $fillable = [
        'catalog_item_id',
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

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function url(): string
    {
        return asset($this->path);
    }
}
