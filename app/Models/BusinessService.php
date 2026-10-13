<?php

namespace App\Models;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessService extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'kind',
        'description',
        'starting_price',
        'currency_code',
        'status',
        'website_visible',
        'sort_order',
        'source_questionnaire_item_key',
    ];

    protected $casts = [
        'status' => BusinessServiceStatus::class,
        'is_primary' => 'boolean',
        'website_visible' => 'boolean',
        'starting_price' => 'decimal:2',
    ];

    public const KIND_SERVICE = 'service';

    public const KIND_EVENT = 'event';

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<BusinessServiceImage> */
    public function images(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BusinessServiceImage::class)->orderByDesc('is_cover')->orderBy('position')->orderBy('id');
    }

    public function coverImage(): ?BusinessServiceImage
    {
        return $this->images->firstWhere('is_cover', true) ?? $this->images->first();
    }

    public function isEvent(): bool
    {
        return $this->kind === self::KIND_EVENT;
    }
}
