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
