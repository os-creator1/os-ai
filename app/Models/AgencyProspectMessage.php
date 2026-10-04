<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyProspectMessage extends Model
{
    use HasUid;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RECEIVED = 'received';
    /** Outreach: a send held back (e.g. insufficient balance); re-sent under the same key once the cause clears. */
    public const STATUS_PAUSED = 'paused';

    public const SOURCE_DETERMINISTIC = 'deterministic';
    public const SOURCE_AI = 'ai';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_FOLLOWUP = 'followup';
    public const SOURCE_OPENER = 'opener';

    public const PURPOSE_INITIAL = 'initial';
    public const PURPOSE_AI_REPLY = 'ai_reply';
    public const PURPOSE_FOLLOWUP = 'followup';

    protected $fillable = [
        'workspace_id',
        'campaign_member_id',
        'channel_id',
        'direction',
        'provider_message_id',
        'purpose',
        'operation_key',
        'body',
        'status',
        'intent',
        'sent_at',
        'received_at',
        'source',
        'stage_from',
        'stage_to',
        'script_version',
        'actor_user_id',
        'failure_reason',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaignMember(): BelongsTo
    {
        return $this->belongsTo(AgencyProspectCampaignMember::class, 'campaign_member_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AgencyProspectingChannel::class, 'channel_id');
    }
}
