<?php

namespace App\Library\Messaging\Exceptions;

/**
 * The agency chose its own Telnyx account as its sender and that connection is
 * not usable. A MessagingIdentityConflictException so every existing caller
 * already fails closed on it; the reason code lets the ones that can say why
 * (Outreach, Conversations) say so. It is never answered by sending through
 * the platform's managed credentials.
 */
class ByoSenderNotReadyException extends MessagingIdentityConflictException
{
    public function __construct(public readonly string $reasonCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'The agency\'s own Telnyx sender is not ready: ' . $reasonCode);
    }
}
