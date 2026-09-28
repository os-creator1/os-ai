<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Phone Numbers + A2P lane — messaging contract §13.3: "The transition
 * from suspended to released must be explicit, audited, and preceded by
 * notification." Thrown by NumberLifecycleManager::recordReleaseDecision()
 * whenever any of that requirement's preconditions is not yet met: the
 * number is not currently Suspended, its grace period has not yet
 * expired, the release notice has not yet been confirmed delivered, the
 * required minimum notice period since confirmed delivery has not yet
 * elapsed, or an active port-out request for it still exists (§13.4's
 * exit path must never be undercut by an unrelated release decision).
 * Never a generic "cannot release" — the message names exactly which
 * precondition failed. About eligibility to DECIDE, never about a real
 * carrier-side release, which this slice never performs.
 */
class NumberReleaseNotEligibleException extends RuntimeException
{
}
