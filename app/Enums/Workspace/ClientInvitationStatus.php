<?php

namespace App\Enums\Workspace;

/**
 * The lifecycle of one Agency-initiated client Workspace invitation
 * (Implementation Contract 07 §5).
 *
 * Pending is the only state that can still be accepted or revoked.
 * Accepted/Expired/Revoked are all terminal — none of them ever transitions
 * again, matching AgencyClientRelationshipStatus's own "no delete, only a
 * terminal status" precedent.
 */
enum ClientInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
