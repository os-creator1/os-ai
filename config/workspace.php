<?php

    return [
        /*
        |----------------------------------------------------------------
        | Client Workspace invitations (Implementation Contract 07 §5/D)
        |----------------------------------------------------------------
        |
        | A single named V1 TTL for an Agency's client-provisioning
        | invitation, kept separate from config/auth.php's 60-minute
        | password-reset broker window: a client invitation is a much
        | less time-sensitive, practical window to leave open, not a
        | security-sensitive account-recovery token.
        */

        'client_invitation_ttl_days' => env('CLIENT_INVITATION_TTL_DAYS', 7),
    ];
