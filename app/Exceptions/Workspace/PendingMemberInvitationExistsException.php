<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/** An unexpired invitation to this address already exists for this Workspace; resend it instead. */
class PendingMemberInvitationExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A pending invitation already exists for this email address.');
    }
}
