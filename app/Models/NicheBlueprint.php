<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Contract 20 §5.1 — one canonical niche Blueprint. The Platform Template
 * Library row the Platform Owner's "Niche Blueprints" surface lists.
 *
 * Identity only: what this Blueprint CONTAINS lives on its versions, so a
 * Blueprint outlives every version of itself.
 *
 * @property int $id
 * @property string $uid
 * @property string $key
 * @property string $display_name
 * @property ?string $vertical_key
 * @property ?string $broad_industry
 * @property bool $is_active
 */
class NicheBlueprint extends Model
{
    use HasUid;

    protected $table = 'niche_blueprints';

    protected $fillable = [
        'key',
        'display_name',
        'vertical_key',
        'broad_industry',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NicheBlueprintVersion::class, 'blueprint_id');
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(BusinessVertical::class, 'vertical_key', 'key');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(BusinessBlueprintComponentInstallation::class, 'blueprint_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
