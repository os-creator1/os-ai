<?php

namespace App\Enums\Workspace;

/**
 * Lifecycle of one Settings → Team invitation. Pending is the only state that
 * can still be accepted, resent or revoked; the others are terminal.
 */
enum WorkspaceMemberInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
