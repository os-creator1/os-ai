<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * PR #295 Correction Round 1, item 2 — thrown when a posted candidate
 * token fails to decrypt, has expired, or names a different Business than
 * the tenancy-resolved one. The customer's browser never controls which
 * provider resource an order binds to; this exception is the signal that
 * an order attempt did not carry a token this platform itself issued a
 * moment earlier from a genuine searchNumbers() result.
 */
class InvalidCandidateTokenException extends RuntimeException
{
}
