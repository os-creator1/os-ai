<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Phone Numbers + A2P lane — review correction. Thrown when a port-out
 * request is attempted against a number that is not currently in a
 * portable status: Pending (not yet an actually acquired, confirmed
 * number — see PortOutRequestManager's own PORTABLE_STATUSES docblock) or
 * Released (nothing left to port). PortOutRequestManager::request() checks
 * this independently of any caller-side filtering, so a posted number id
 * that names an ineligible number can never be requested regardless of
 * what the controller believes about it.
 */
class PortOutRequestNumberNotPortableException extends RuntimeException
{
}
