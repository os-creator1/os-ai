<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Phone Numbers + A2P lane — messaging contract §13.3: "The transition
 * from suspended to released must be explicit, audited, and preceded by
 * notification." Thrown by NumberLifecycleManager::release() whenever any
 * of that requirement's preconditions is not yet met: the number is not
 * currently Suspended, its grace period has not yet expired, the release
 * notice was never sent, or an active port-out request for it still
 * exists (§13.4's exit path must never be undercut by an unrelated
 * release). Never a generic "cannot release" — the message names exactly
 * which precondition failed.
 */
class NumberReleaseNotEligibleException extends RuntimeException
{
}
