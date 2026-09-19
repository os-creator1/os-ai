<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 15 §5.6 — one cached busy interval from a User's
 * external calendar. ADVISORY ONLY: it is one additional read-only source
 * unioned into §7.6's conflict check, and the canonical Appointment record's
 * authority never depends on it being present, current, or even ever having
 * existed.
 *
 * Disposable synced-derived data — it cascades away with its connection
 * (and therefore with its User, §5.5). Idempotency comes from the
 * (connection, provider_event_id) unique key, not from application logic.
 */
class ExternalCalendarBusyBlock extends Model
{
    protected $fillable = [
        'external_calendar_connection_id',
        'provider_event_id',
        'start_at',
        'end_at',
        'busy_type',
        'synced_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ExternalCalendarConnection::class, 'external_calendar_connection_id');
    }
}
