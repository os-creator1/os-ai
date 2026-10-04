<?php

namespace App\Library\MetaAds\Sync;

/**
 * The claimant's proof of ownership of one account's sync claim: the stamp it
 * wrote into `meta_ads_accounts.sync_claimed_at`. MetaAdsSyncGuard moves the
 * stamp forward on every heartbeat and releases only while the stored value
 * still equals it, so a claim taken over after going stale is never extended
 * or released by the old owner.
 */
final class MetaAdsSyncClaim
{
    public function __construct(
        public readonly int $accountId,
        public string $stamp,
    ) {
    }
}
