<?php

namespace App\Enums\Website;

/**
 * Website Generation + Hosting Slice B (custom domains). One linear
 * lifecycle per domain row — a domain never skips a step, and any step
 * can land on Failed with a human-readable reason attached:
 *
 * PendingVerification -> Verified -> Provisioning -> Active
 *                    \-> Failed <-/          \-> Failed
 *
 * Removing is a SEPARATE exit state any of the above can enter (an
 * owner asked to disconnect the domain) and can safely re-enter itself
 * (a retry after a failed disconnect) — it never returns to any state
 * above. See WebsiteDomainService::remove(): the row and its
 * forge_domain_id are deliberately kept, and the hostname stays
 * claimed, until Forge actually confirms the domain resource is gone.
 *
 * Only Active domains are ever used for public Host-based routing or
 * search indexing (contract §21/§40) — every earlier, Removing, or
 * Failed state behaves exactly as if the domain did not exist for a
 * visitor.
 */
enum WebsiteDomainStatus: string
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
    case Removing = 'removing';
}
