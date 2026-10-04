<?php

namespace App\Library\AgencyOutreach;

/**
 * The outcome of one canonical send, in Outreach's own terms (contract §6/§12):
 *
 *   sent     the managed provider accepted the message
 *   paused   held back because the wallet refused (insufficient balance): NOT a failure,
 *            the message is re-sent under the same operation key once funds exist
 *   blocked  refused before any provider call for an exact, actionable reason
 *            (opted_out, messaging_not_ready, campaign_assignment_not_confirmed, ...)
 *   failed   attempted and not accepted
 */
final class OutreachSendResult
{
    public const SENT = 'sent';

    public const PAUSED = 'paused';

    public const BLOCKED = 'blocked';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?string $providerMessageId = null,
    ) {
    }

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(self::SENT, null, $providerMessageId);
    }

    public static function paused(string $reason): self
    {
        return new self(self::PAUSED, $reason);
    }

    public static function blocked(string $reason): self
    {
        return new self(self::BLOCKED, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, $reason);
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }
}
