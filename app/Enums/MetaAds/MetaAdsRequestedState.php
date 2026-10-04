<?php

namespace App\Enums\MetaAds;

/**
 * Contract 24 §7 — the only two states V1 ever writes to Meta. The stored
 * value is the local lower-case vocabulary; providerValue() is what the
 * Graph API `status` field receives.
 */
enum MetaAdsRequestedState: string
{
    case Paused = 'paused';
    case Active = 'active';

    public function providerValue(): string
    {
        return match ($this) {
            self::Paused => 'PAUSED',
            self::Active => 'ACTIVE',
        };
    }
}
