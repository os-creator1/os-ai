<?php

namespace App\Library\Voice;

use RuntimeException;

final class VoiceProviderException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidKey(): self
    {
        return new self('invalid_api_key', 'Telnyx rejected the saved access key. Reconnect the Telnyx account with a valid key.');
    }

    public static function unavailable(): self
    {
        return new self('provider_unavailable', 'Telnyx could not be reached right now. Try again in a moment.');
    }
}
