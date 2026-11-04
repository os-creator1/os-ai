<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * One exception for every reason a Team invitation cannot be used (unknown,
 * expired, revoked, already used, wrong token, wrong signed-in person, the
 * inviter's authority gone). Callers render ONE generic message whatever the
 * reason; `$reason` is for internal logs only and never contains the token or
 * an email address.
 */
class InvalidMemberInvitationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Invalid workspace member invitation: ' . $reason);
    }
}
