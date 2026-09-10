<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Slice 3 §4.3 — two or more candidates found, or two independent
 * resolution signals disagree.
 *
 * Covers both the data-integrity race (a second active-or-pending identity,
 * a duplicate number, two active primary numbers — each of which MySQL
 * itself rejects first) and §4.6's Profile-versus-number attribution
 * conflict. Never carries a credential or a raw provider payload.
 */
class MessagingIdentityConflictException extends RuntimeException
{
}
