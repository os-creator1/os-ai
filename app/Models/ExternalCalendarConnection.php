<?php

namespace App\Models;

use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Implementation Contract 15 §5.5 — a User's own external calendar
 * connection, global to their User identity rather than per Workspace
 * (Blueprint §12).
 *
 * `refresh_token_encrypted` uses Laravel's built-in `encrypted` cast, the
 * same precedent BusinessGoogleConnection already uses. There is
 * deliberately NO access-token attribute: an access token is derived from
 * the refresh token per unit of work and never persisted (§5.5).
 *
 * $hidden covers the two attributes that must never be serialized into a
 * view, JSON response, log line, exception context or event payload. GBP's
 * own model states the same rationale: defence in depth, so that an
 * accidental toArray()/toJson() cannot leak them either.
 *
 * `active_user_id` is a VIRTUAL GENERATED column written by MySQL, never by
 * this application — it is therefore neither fillable nor guarded here, and
 * any attempt to write it would be rejected by the database. It carries no
 * information of its own, only the conditional uniqueness of §5.5.
 */
class ExternalCalendarConnection extends Model
{
    use HasUid;

    protected $fillable = [
        'user_id',
        'provider',
        'state',
        'external_account_email',
        'refresh_token_encrypted',
        'granted_scopes',
        'sync_cursor',
        'last_synced_at',
        'last_sync_failure_at',
        'sync_failure_count',
        'failure_classification',
        'oauth_state_nonce',
        'oauth_state_expires_at',
        'connected_at',
        'disconnected_at',
        'revoked_at',
        'last_refreshed_at',
        'lock_version',
    ];

    protected $hidden = [
        'refresh_token_encrypted',
        'oauth_state_nonce',
    ];

    protected $casts = [
        'provider' => ExternalCalendarProvider::class,
        'state' => ExternalCalendarConnectionState::class,
        'refresh_token_encrypted' => 'encrypted',
        'last_synced_at' => 'datetime',
        'last_sync_failure_at' => 'datetime',
        'sync_failure_count' => 'integer',
        'oauth_state_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function busyBlocks(): HasMany
    {
        return $this->hasMany(ExternalCalendarBusyBlock::class, 'external_calendar_connection_id');
    }

    /**
     * Whether this row currently holds its User's single connection slot.
     * The DATABASE is the authority for that (the `active_user_id` generated
     * column plus its unique index); this reader exists so application code
     * never has to restate the state list inline.
     */
    public function occupiesConnectionSlot(): bool
    {
        return $this->state instanceof ExternalCalendarConnectionState
            && $this->state->occupiesConnectionSlot();
    }
}
