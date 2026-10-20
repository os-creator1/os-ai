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
        'answered_at', 'bridge_requested_at', 'ended_at', 'duration_seconds', 'hangup_cause', 'hangup_source', 'last_event_at',
        'client_reported_ended_at', 'note', 'cancel_requested_at', 'bridge_dispatched_at', 'customer_call_control_id',
        'leg_teardown_status', 'leg_teardown_attempts', 'leg_teardown_error', 'leg_teardown_at', 'leg_teardown_exhausted_at',
    ];

    protected $casts = [
        'initiated_at' => 'datetime',
        'answered_at' => 'datetime',
        'bridge_requested_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_event_at' => 'datetime',
        'client_reported_ended_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'bridge_dispatched_at' => 'datetime',
        'leg_teardown_at' => 'datetime',
        'leg_teardown_exhausted_at' => 'datetime',
    ];

    public function statusEnum(): VoiceCallStatus
    {
        return VoiceCallStatus::from($this->status);
    }

    /** Reasons a retry cannot fix on its own: the number, connection or key must be repaired first. */
    private const TEARDOWN_NEEDS_ATTENTION = ['number_unavailable', 'telnyx_disconnected', 'voice_disabled', 'voice_not_configured', 'invalid_api_key'];

    /**
     * What we can honestly say about the provider side of an ended/canceled call:
     *  null       nothing was ever owed (no provider leg known)
     *  confirmed  Telnyx accepted the hangup (or reported the leg gone)
     *  pending    hangup requested / being retried
     *  attention  could not be confirmed and needs a person (retries exhausted, or the number/connection is unavailable)
     */
    public function providerHangupState(): ?string
    {
        return match ($this->leg_teardown_status) {
            null => null,
            'done' => 'confirmed',
            'failed' => ((int) $this->leg_teardown_attempts >= 6 || $this->leg_teardown_exhausted_at !== null || in_array($this->leg_teardown_error, self::TEARDOWN_NEEDS_ATTENTION, true)) ? 'attention' : 'pending',
            default => 'pending',
        };
    }
}
