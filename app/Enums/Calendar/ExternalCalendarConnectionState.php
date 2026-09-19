<?php

namespace App\Enums\Calendar;

/**
 * Implementation Contract 15 §5.5 — the external calendar connection
 * lifecycle, mirroring App\Enums\GoogleBusinessProfile\GoogleConnectionState.
 *
 * STATE IS THE UNIQUENESS AUTHORITY, not the timestamps. The
 * `active_user_id` stored generated column equals user_id only while the
 * state is `Pending` or `Active`, so an in-flight connect genuinely holds
 * the User's one connection slot — which is exactly what refuses a second
 * simultaneous initiation. `Disconnected` and `Revoked` are terminal, NULL
 * the generated column, and free the slot while the row itself is retained.
 */
enum ExternalCalendarConnectionState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Disconnected = 'disconnected';
    case Revoked = 'revoked';

    /**
     * The two states that occupy the User's single connection slot (§5.5).
     * Kept in sync with the generated column's own
     * `CASE WHEN state IN ('pending','active')` expression by
     * ExternalCalendarConnectionSchemaTest.
     */
    public function occupiesConnectionSlot(): bool
    {
        return $this === self::Pending || $this === self::Active;
    }
}
