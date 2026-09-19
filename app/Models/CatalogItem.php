<?php

namespace App\Models;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Catalog\CatalogItemType;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Implementation Contract 16 §5.1 — one canonical, Business-wide catalog
 * item. Casts/relations only in this sub-slice; CRUD/reorder authority
 * belongs to Sub-slice B's `CatalogItemManager`.
 */
class CatalogItem extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'type',
        'name',
        'description',
        'price_minor',
        'currency_code',
        'position',
        'created_by_user_id',
    ];

    protected $casts = [
        'type' => CatalogItemType::class,
        'price_minor' => 'integer',
        'position' => 'integer',
        // Contract §5.1: not fillable — changed only through
        // CatalogItemManager's archive()/reactivate() (Sub-slice B), never
        // ordinary mass-assignment, mirroring BusinessLocation exactly.
        'lifecycle_state' => CatalogItemLifecycleState::class,
        'archived_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function locationOverrides(): HasMany
    {
        return $this->hasMany(CatalogItemLocationOverride::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PackageSnapshot::class);
    }

    public function isActive(): bool
    {
        return ($this->lifecycle_state ?? CatalogItemLifecycleState::Active) === CatalogItemLifecycleState::Active;
    }

    public function isArchived(): bool
    {
        return $this->lifecycle_state === CatalogItemLifecycleState::Archived;
    }
}
