<?php

namespace App\Enums\Calendar;

/**
 * Implementation Contract 15 §5.5 — the two external calendar providers
 * Blueprint §12 names, and only those two.
 *
 * A User holds at most ONE active connection of EITHER provider, not one per
 * provider: Blueprint §12 (V1-MASTER-PRODUCT-BLUEPRINT.md:290) reads "Each
 * staff member connects their own Google OR Outlook calendar once, globally
 * to their User identity", and nothing anywhere authorizes both at once.
 * That cardinality is enforced by the database, not by this enum — see the
 * `active_user_id` generated column and its unique index (§5.5).
 */
enum ExternalCalendarProvider: string
{
    case Google = 'google';
    case Outlook = 'outlook';
}
