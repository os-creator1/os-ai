<?php

namespace App\Models;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyProspectCampaignMember extends Model
{
    use HasUid;

    protected $fillable = [
        'workspace_id',
        'campaign_id',
        'prospect_id',
        'stage',
        'enrolled_at',
        'last_inbound_at',
        'last_outbound_at',
        'booking_link_sent_at',
        'followup_at',
        'followup_sent_at',
        'followup_cancelled_at',
        'proposed_slot',
        'last_provider_message_id',
        'soft_negative_count',
    ];

    protected $casts = [
        'stage' => AgencyProspectStage::class,
        'enrolled_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
        'booking_link_sent_at' => 'datetime',
        'followup_at' => 'datetime',
        'followup_sent_at' => 'datetime',
        'followup_cancelled_at' => 'datetime',
        'soft_negative_count' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AgencyProspectCampaign::class, 'campaign_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(AgencyProspect::class, 'prospect_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgencyProspectMessage::class, 'campaign_member_id');
    }

    /**
     * Runtime pass — a membership is "active" (non-terminal) for the
     * one-open-conversation invariant when its stage is neither Booked
     * (6) nor StoppedOptOut (99).
     */
    public function isTerminal(): bool
    {
        return in_array($this->stage, [AgencyProspectStage::Booked, AgencyProspectStage::StoppedOptOut], true);
    }
}
