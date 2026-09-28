<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Phone Numbers + A2P lane — thrown by NumberLifecycleManager::
 * confirmCarrierRelease() when every local eligibility precondition held
 * (unlike NumberReleaseNotEligibleException, which is about NOT reaching
 * the carrier at all) but the carrier round trip itself did not positively
 * confirm the number's identity and deleted state: a lookup that could not
 * verify the resource actually matches this number, a delete response
 * that does not clearly confirm removal, a 404 (never by itself proof of
 * a prior successful release), any other non-2xx response, or a
 * transport-level exception. The number stays Suspended — never Released
 * on the strength of an attempt alone — and the attempt is already
 * durably recorded (a CarrierReleaseAttemptFailed lifecycle event plus
 * carrier_release_failed_at/carrier_release_failure_reason) by the time
 * this is thrown, so a caller catching this only needs to surface the
 * message, never re-record anything.
 */
class NumberCarrierReleaseNotConfirmedException extends RuntimeException
{
}
