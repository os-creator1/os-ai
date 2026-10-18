<?php

namespace App\Events\Workspace;

use Illuminate\Foundation\Events\Dispatchable;

/** An Agency created a client-workspace invitation. Raised by ClientInvitationManager, the single writer of invitations. */
final class ClientInvitationCreated
{
    use Dispatchable;

    public function __construct(public readonly int $invitationId, public readonly int $agencyWorkspaceId)
    {
    }
}
