<?php

namespace App\Library\Voice\Inbound;

use App\Models\Business;
use App\Models\BusinessInboundCallSetting;

/**
 * The single answer to "may inbound calling run?". Two independent switches that BOTH must be on: the platform flag
 * (default off) and an operator's pilot approval of this specific Business. Nothing a customer can edit turns either
 * on, because voice-minute billing is not finished and commercial inbound calling must never start silently.
 */
final class InboundCallingGate
{
    /** Deployment-level: flag on, calling stack configured and live. */
    public function platformEnabled(): bool
    {
        return (bool) config('messaging.voice.inbound.enabled')
            && (bool) config('messaging.voice.customer_calls.enabled')
            && (bool) config('messaging.voice.browser.enabled')
            && config('services.telnyx.mode') === 'live'
            && trim((string) config('services.telnyx.api_key')) !== ''
            && trim((string) config('messaging.voice.connection_id')) !== ''
            && trim((string) config('services.telnyx.webhook_public_key')) !== ''
            && str_starts_with((string) (config('messaging.voice.webhook_url') ?: route('webhooks.telnyx_voice')), 'https://');
    }

    public function pilotApproved(Business $business): bool
    {
        return in_array((int) $business->id, array_map('intval', (array) config('messaging.voice.inbound.pilot_business_ids')), true);
    }

    /** The platform flag AND this Business's pilot approval. */
    public function available(Business $business): bool
    {
        return $this->platformEnabled() && $this->pilotApproved($business);
    }

    /** Available AND the Business has chosen a routing mode. */
    public function routable(Business $business, ?BusinessInboundCallSetting $setting): bool
    {
        return $this->available($business) && $setting !== null && $setting->mode !== BusinessInboundCallSetting::MODE_OFF;
    }
}
