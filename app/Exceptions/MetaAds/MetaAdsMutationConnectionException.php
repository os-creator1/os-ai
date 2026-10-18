<?php

namespace App\Exceptions\MetaAds;

/**
 * The Business has no usable Meta connection for writing: none, not active,
 * no stored token, or the grant lacks `ads_management` (a read-only
 * connection). Nothing was sent. Map to 409 and tell the owner to reconnect.
 *
 * reason: `connection_not_active` | `connection_read_only`.
 */
final class MetaAdsMutationConnectionException extends MetaAdsMutationException
{
    public const NOT_ACTIVE = 'connection_not_active';

    public const READ_ONLY = 'connection_read_only';

    /** The selected account was chosen under a different Meta user than the connection now holds. */
    public const MISMATCH = 'connection_mismatch';

    public function __construct(string $reason = self::NOT_ACTIVE)
    {
        parent::__construct(
            $reason,
            match ($reason) {
                self::READ_ONLY => 'Meta is connected for reporting only. Reconnect Meta and allow ads management to pause or resume.',
                self::MISMATCH => 'Meta was reconnected as a different user. Select your ad account again in Settings.',
                default => 'Meta Ads is not connected. Reconnect it in Settings to continue.',
            },
        );
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
