<?php

namespace App\Library\Usage;

use App\Enums\Usage\PayerType;

/**
 * RFC-005 §6/§16 — who currently pays for a Business's usage, resolved
 * from its business_payer_assignments row. effectivePaymentInstrumentId is
 * always null at M2 (no payment instrument exists until M3).
 */
final readonly class EffectivePayer
{
    public function __construct(
        public PayerType $payerType,
        public int $businessId,
        public ?int $effectivePaymentInstrumentId,
    ) {
    }
}
