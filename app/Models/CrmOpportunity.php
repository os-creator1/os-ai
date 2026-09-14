<?php

namespace App\Models;

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
