<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer-facing notification the Calendar owes an appointment, per channel
 * and occurrence — see the create_appointment_notifications_table migration for
 * why the ledger exists. Written only by AppointmentNotificationScheduler and
 * AppointmentNotificationSender.
 */
class AppointmentNotification extends Model
{
    public const KIND_CONFIRMATION = 'confirmation';
    public const KIND_REMINDER = 'reminder';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'business_id',
        'business_location_id',
        'appointment_id',
        'contact_id',
        'kind',
        'channel',
        'occurrence_key',
        'offset_minutes',
        'appointment_start_at',
        'scheduled_for',
        'recipient_email',
        'recipient_phone',
        'sms_consent_at',
        'status',
        'reason',
        'dispatched_at',
        'attempted_at',
        'sent_at',
    ];

    protected $casts = [
        'offset_minutes' => 'integer',
        'appointment_start_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'sms_consent_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'attempted_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
