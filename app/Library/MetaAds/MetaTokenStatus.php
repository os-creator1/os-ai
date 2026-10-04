<?php

namespace App\Library\MetaAds;

use App\Enums\MetaAds\MetaConnectionState;
use Carbon\CarbonImmutable;

/**
 * Contract 24 §3 — what the UI needs to say "Reconnect Meta before …".
 * Carries no token.
 */
final readonly class MetaTokenStatus
{
    public function __construct(
        public MetaConnectionState $state,
        public ?CarbonImmutable $expiresAt,
        public ?int $daysLeft,
        public bool $needsReauthSoon,
        public bool $canManage,
    ) {
    }

    /** @return array{state: string, expires_at: ?CarbonImmutable, days_left: ?int, needs_reauth_soon: bool, can_manage: bool} */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'expires_at' => $this->expiresAt,
            'days_left' => $this->daysLeft,
            'needs_reauth_soon' => $this->needsReauthSoon,
            'can_manage' => $this->canManage,
        ];
    }
}
