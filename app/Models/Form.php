<?php

namespace App\Models;

use App\Enums\Forms\FormLifecycleState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Forms V1 — the canonical, Business-wide form definition.
 *
 * Standalone product surface: NOT a Website capability and not a
 * `WebsiteForm`. What the form asks lives in its immutable `FormVersion`s;
 * where it is offered lives in its `FormDeployment`s; this row is identity,
 * ownership, the customer's label and the lifecycle.
 *
 * `lifecycle_state` and `current_version` are deliberately NOT fillable:
 * `FormManager` is the only writer of either.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property string $name
 * @property FormLifecycleState $lifecycle_state
 * @property int $current_version
 */
class Form extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'name',
        'created_by_user_id',
    ];

    protected $casts = [
        'lifecycle_state' => FormLifecycleState::class,
        'current_version' => 'integer',
        'activated_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    /** The version new submissions are validated against. */
    public function currentVersion(): ?FormVersion
    {
        return $this->versions()->where('version', $this->current_version)->first();
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(FormDeployment::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function isActive(): bool
    {
        return $this->lifecycle_state === FormLifecycleState::Active;
    }
}
