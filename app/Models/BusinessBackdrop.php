<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Website Builder redesign — a structured, repeatable backdrop offering.
 * Business-scoped (not Website-scoped) so it survives a website rebuild
 * or deletion. Deliberately not a CatalogItem: a backdrop carries no
 * price (see the create_business_backdrops_table migration for the full
 * reasoning).
 */
class BusinessBackdrop extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'category',
        'availability',
        'position',
        'source_questionnaire_item_key',
    ];

    protected $casts = [
        'availability' => 'boolean',
        'position' => 'integer',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(BusinessBackdropImage::class)->orderBy('position');
    }

    public function isActive(): bool
    {
        return (bool) $this->availability;
    }
}
