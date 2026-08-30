<?php

namespace App\Enums\Usage;

/**
 * dispute_webhook is never produced by any M2 code path (requires M3
 * webhook processing) — the case exists because the schema ships at M2
 * (M2 contract §6.G), never called in production until M3.
 */
enum BillingStatusTransitionSource: string
{
    case DisputeWebhook = 'dispute_webhook';
    case AdminAction = 'admin_action';

    /**
     * RFC-005 Remediation #6 §6 — a provider-confirmed cumulative refund
     * exceeding what could be honored as a cash refund (policyExcessMicro
     * > 0). Never repurposes DisputeWebhook/AdminAction.
     */
    case ProviderRefundMismatch = 'provider_refund_mismatch';
}
