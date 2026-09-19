<?php

namespace App\Models;

use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Contract 20 §5.2 — a draft, or an immutable published/superseded version.
 *
 * THE LIFECYCLE FIELDS ARE NOT MASS-ASSIGNABLE, ON PURPOSE. `state`,
 * `version_number`, `published_at` and `published_by_user_id` are the
 * publisher's (Sub-slice B) to set, and `$fillable` deliberately excludes all
 * four so no casual `create()`/`update()` elsewhere can publish a version,
 * renumber one, or forge its publication metadata. Sub-slice B sets them
 * explicitly through its own write path. `draft_guard` and `published_guard`
 * are database-generated and are never written by PHP at all.
 *
 * @property int $id
 * @property string $uid
 * @property int $blueprint_id
 * @property int $version_number
 * @property NicheBlueprintVersionState $state
 * @property ?string $notes
 * @property ?\Illuminate\Support\Carbon $published_at
 * @property ?int $published_by_user_id
 */
class NicheBlueprintVersion extends Model
{
    use HasUid;

    protected $table = 'niche_blueprint_versions';

    /**
     * Deliberately excludes `state`, `version_number`, `published_at` and
     * `published_by_user_id` (the publisher's to set — see the class docblock)
     * and the two STORED generated guard columns, which PHP never writes at
     * all. An allowlist is used rather than `$guarded` precisely so a column
     * added later is excluded until someone decides otherwise.
     */
    protected $fillable = [
        'blueprint_id',
        'notes',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'state' => NicheBlueprintVersionState::class,
        'published_at' => 'datetime',
        'published_by_user_id' => 'integer',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(NicheBlueprint::class, 'blueprint_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(NicheBlueprintComponent::class, 'blueprint_version_id')
            ->orderBy('position')
            ->orderBy('id');
    }
}
