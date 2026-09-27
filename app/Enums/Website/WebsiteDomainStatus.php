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
 * Only Active domains are ever used for public Host-based routing or
 * search indexing (contract §21/§40) — every earlier or failed state
 * behaves exactly as if the domain did not exist for a visitor.
 */
enum WebsiteDomainStatus: string
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
}
