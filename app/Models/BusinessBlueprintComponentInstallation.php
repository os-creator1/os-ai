<?php

namespace App\Models;

use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contract 20 §5.4 — what a Blueprint installation run decided about ONE
 * component for ONE Business, and the rows it created if it installed.
 *
 * The `(business_id, blueprint_id, component_key)` unique key on this table is
 * the entire "never silently update or reactivate" guarantee: an automated run
 * may only act where there is no row, or a `failed` one.
 *
 * SKIP STATES ARE PROVENANCE, NEVER AUTHORITY — see
 * BlueprintComponentInstallationState. Nothing reads a skip row to decide
 * whether a component may be added; `EntitlementManager::decide()` is re-asked
 * every time.
 *
 * `installed_record_id` intentionally has no foreign key: it points across
 * bounded contexts (`crm_pipelines` today, others later) and a polymorphic key
 * is not expressible. It is provenance for a human reading an audit trail, not
 * a referential guarantee, and nothing dereferences it to make a decision.
 *
 * @property int $id
 * @property int $business_id
 * @property int $blueprint_id
 * @property string $component_key
 * @property string $component_type
 * @property int $installed_from_version
 * @property BlueprintComponentInstallationState $state
 * @property string $required_feature_key
 * @property ?string $decision_reason
 * @property ?string $installed_record_type
 * @property ?int $installed_record_id
 * @property ?string $error_code
 * @property ?\Illuminate\Support\Carbon $installed_at
 * @property ?int $installed_by_user_id
 */
class BusinessBlueprintComponentInstallation extends Model
{
    protected $table = 'business_blueprint_component_installations';

    /**
     * DESCRIPTOR/IDENTITY INPUTS ONLY — never the outcome.
     *
     * `state`, `decision_reason`, `installed_record_type`,
     * `installed_record_id`, `error_code`, `installed_at` and
     * `installed_by_user_id` are all deliberately absent. Every one of them
     * is a FACT THE INSTALLER ESTABLISHED (§6.3, §7.2): the entitlement
     * decision it received, the row it actually created, the actor it ran as,
     * the moment the write stuck. A mass-assignable route to any of them is a
     * route around the installer's own state machine — it would let a caller
     * write `state => 'installed'` with a fabricated target and actor, and the
     * `(business_id, blueprint_id, component_key)` unique key would then make
     * that lie permanent, because an automated run never revisits an
     * `installed` row.
     *
     * The Sub-slice C installer sets these internally through `forceFill()`
     * or direct trusted assignment inside its canonical write path. That is
     * intentional and is the ONLY sanctioned way they are written; this model
     * simply declines to offer a casual second one.
     */
    protected $fillable = [
        'business_id',
        'blueprint_id',
        'component_key',
        'component_type',
        'installed_from_version',
        'required_feature_key',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'blueprint_id' => 'integer',
        'installed_from_version' => 'integer',
        'state' => BlueprintComponentInstallationState::class,
        'installed_record_id' => 'integer',
        'installed_at' => 'datetime',
        'installed_by_user_id' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(NicheBlueprint::class, 'blueprint_id');
    }

    /**
     * The only state that permanently removes a component from the addable
     * query (§8.1). Every other row is re-decided on every render.
     */
    public function scopeInstalled(Builder $query): Builder
    {
        return $query->where('state', BlueprintComponentInstallationState::Installed->value);
    }

    public function scopeForBusiness(Builder $query, Business $business): Builder
    {
        return $query->where('business_id', $business->id);
    }
}
