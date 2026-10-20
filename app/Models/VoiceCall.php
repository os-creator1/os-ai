<?php

namespace App\Models;

use App\Enums\Voice\VoiceCallStatus;
use Illuminate\Database\Eloquent\Model;

class VoiceCall extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND = 'inbound';

    protected $fillable = [
        'uid', 'business_id', 'workspace_id', 'location_id', 'contact_id', 'voice_number_id', 'user_id',
        'direction', 'from_number', 'to_number', 'counterpart_number', 'status', 'status_rank',
        'provider_call_session_id', 'provider_call_control_id', 'provider_leg_id', 'initiated_at',
        'answered_at', 'ended_at', 'duration_seconds', 'hangup_cause', 'hangup_source', 'last_event_at',
        'client_reported_ended_at', 'note',
    ];

    protected $casts = [
        'initiated_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_event_at' => 'datetime',
        'client_reported_ended_at' => 'datetime',
    ];

    public function statusEnum(): VoiceCallStatus
    {
        return VoiceCallStatus::from($this->status);
    }
}
