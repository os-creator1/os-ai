<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Telnyx Call Control test call. Written only by TestCallPlacer (creation, API outcome) and
 * VoiceCallStatusRecorder (webhook status). Holds no raw destination number and no credential: `to_hash` is an HMAC.
 */
class BusinessVoiceCall extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_INITIATED = 'initiated';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_ENDED = 'ended';
    public const STATUS_FAILED = 'failed';

    protected $table = 'business_voice_calls';

    protected $guarded = [];

    protected $casts = [
        'business_id' => 'integer',
        'business_messaging_number_id' => 'integer',
        'initiated_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(BusinessMessagingNumber::class, 'business_messaging_number_id');
    }

    /** Keyed digest of an E.164 destination — matches a known number to a row without storing it. */
    public static function hashDestination(string $e164): string
    {
        return hash_hmac('sha256', $e164, (string) config('app.key'));
    }
}
