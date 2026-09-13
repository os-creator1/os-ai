<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * PR #295 Correction Round 1, item 8 — thrown when the normal customer
 * edit route attempts to change an already-Approved registration. Once a
 * carrier has approved the exact legal/compliance data this platform
 * submitted, that record must never silently drift from what was actually
 * approved; a genuine correction requires a new registration lifecycle,
 * which this slice does not yet implement.
 */
class MessagingRegistrationImmutableException extends RuntimeException
{
}
