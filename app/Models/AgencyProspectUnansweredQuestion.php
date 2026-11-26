<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One prospect question no approved answer covered (Outreach -> Unanswered questions). */
class AgencyProspectUnansweredQuestion extends Model
{
    use HasUid;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    public const RESOLUTION_ANSWER_ADDED = 'answer_added';

    public const RESOLUTION_ALIAS_LINKED = 'alias_linked';

    public const RESOLUTION_DISMISSED = 'dismissed';

    protected $fillable = [
        'workspace_id', 'campaign_id', 'campaign_member_id', 'inbound_message_id', 'question', 'question_hash',
        'status', 'resolution', 'resolved_faq_id', 'resolved_by_user_id', 'asked_at', 'resolved_at',
    ];

    protected $casts = ['asked_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AgencyProspectCampaign::class, 'campaign_id');
    }

    public function campaignMember(): BelongsTo
    {
        return $this->belongsTo(AgencyProspectCampaignMember::class, 'campaign_member_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
