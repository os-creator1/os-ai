<?php

namespace App\Library\GoogleAds\Mutations;

/**
 * The Business has no active google_ads connection (never connected,
 * revoked, disconnected, or holds no stored authorization). Nothing was sent.
 * Map to 409 and point the user at Settings to reconnect.
 */
final class GoogleAdsMutationConnectionException extends GoogleAdsMutationException
{
    public function __construct()
    {
        parent::__construct('connection_not_active', 'Google Ads is not connected. Reconnect it in Settings to continue.');
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
