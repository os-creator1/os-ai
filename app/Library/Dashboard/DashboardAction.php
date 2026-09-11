<?php

namespace App\Library\Dashboard;

/**
 * Customer Experience Slice 4 §11 — one quick action: a real link the actor
 * can follow. `kind` records why it may be absent while viewing a client
 * (cost-producing, funding or provider actions never render then).
 */
final class DashboardAction
{
    public const KIND_ORDINARY = 'ordinary';
    public const KIND_COST = 'cost';
    public const KIND_FUNDING = 'funding';
    public const KIND_PROVIDER = 'provider';
    public const KIND_ACCOUNT = 'account';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $url,
        public readonly string $icon,
        public readonly string $kind = self::KIND_ORDINARY,
    ) {
    }
}
