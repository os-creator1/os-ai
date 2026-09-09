<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Slice 3 §4.3 — no matching, unambiguous identity or number for a given
 * resolution key. Never carries a credential or a raw provider payload.
 */
class MessagingIdentityUnresolvedException extends RuntimeException
{
}
