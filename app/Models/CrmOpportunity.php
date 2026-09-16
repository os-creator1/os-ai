<?php

namespace App\Models;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Crm\CrmContactStatus;
use App\Enums\Crm\CrmContactStatusSource;
use App\Enums\Crm\CrmOpportunityStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A CRM sales deal with one contact.
 *
 * NOT App\Models\Opportunity — that is the AI COO / Business Advisor
 * recommendation. Changes go through CrmOpportunityService, which keeps the
 * history and the automation events in step; nothing else writes these rows.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property int $pipeline_id
 * @property int $stage_id
 * @property ?int $contact_id
 * @property string $title
 * @property ?int $value_minor
 * @property ?string $currency_code
 * @property CrmOpportunityStatus $status
 * @property CrmContactStatus $contact_status
 * @property ?CrmContactStatusSource $contact_status_source
 * @property string $source
 * @property ?string $lost_reason
 */
class CrmOpportunity extends Model
{
    use HasUid;

    public const SOURCE_MANUAL = 'manual';

    protected $table = 'crm_opportunities';

    protected $fillable = [
        'business_id',
        'location_id',
        'pipeline_id',
        'stage_id',
        'contact_id',
        'title',
        'value_minor',
        'currency_code',
        'status',
        'contact_status',
        'contact_status_source',
        'contact_status_changed_at',
        'source',
        'lost_reason',
        'stage_entered_at',
        'won_at',
        'lost_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'value_minor' => 'integer',
        'status' => CrmOpportunityStatus::class,
        'contact_status' => CrmContactStatus::class,
        'contact_status_source' => CrmContactStatusSource::class,
        'contact_status_changed_at' => 'datetime',
        'stage_entered_at' => 'datetime',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Implementation Contract 08B — the Location this deal belongs to,
     * when one could be proven. Null is an ordinary, expected value.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    /**
     * Implementation Contract 08B §5 — the ONE definition of the
     * single-Active-Location rule for this model, mirroring
     * ChatBox::singleActiveLocationIdFor() exactly (Contract 06). Never
     * derives from the linked Contact's own Location: this stays a direct
     * structural mirror of the established pattern rather than inventing
     * a new inference rule.
     *
     * Exactly one ACTIVE Location: that Location. Zero, several, or no
     * Business at all: null. An archived Location is never chosen.
     *
     * CrmOpportunityLocationBackfillV1 deliberately keeps its own
     * set-based copy of this rule, for the same reason
     * ChatBoxLocationBackfillV1 does.
     */
    public static function singleActiveLocationIdFor(?int $businessId): ?int
    {
        if ($businessId === null || $businessId <= 0) {
            return null;
        }

        $locations = BusinessLocation::query()
            ->where('business_id', $businessId)
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        return $locations->count() === 1 ? (int) $locations->first() : null;
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmPipelineStage::class, 'stage_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(CrmOpportunityHistory::class, 'opportunity_id')->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return $this->status === CrmOpportunityStatus::Open;
    }
}
