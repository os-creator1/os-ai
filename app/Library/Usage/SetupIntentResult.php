<?php

namespace App\Library\Usage;

/**
 * M3 contract §8/§10 — the gateway's normalized return shape for
 * createSetupIntent()/retrieveSetupIntent(). clientSecret is exposed to the
 * browser exactly once and never persisted (M3 contract §10 item 5).
 */
final readonly class SetupIntentResult
{
    public function __construct(
        public string $providerSetupIntentId,
        public ?string $clientSecret,
        public string $status,
        public ?string $providerPaymentMethodId,
    ) {
    }
}
