<?php

namespace App\Library\Voice\Inbound;

use App\Models\Business;
use App\Models\BusinessInboundCallSetting;

/**
 * The single answer to "may inbound calling run?". It is available to every Business on the platform whenever the platform
 * is operational: the global emergency switch (VOICE_INBOUND_CALLS_ENABLED, on by default; set false to disable inbound
 * calling everywhere at once) AND the telephony stack (customer calling, browser calling, live Telnyx mode, credentials, a
 * Voice connection, a webhook key, an https webhook URL). There is no per-Business approval or allowlist. Who may use it
 * is decided elsewhere and unchanged: tenancy, CRM entitlement, permissions, and a Business's own voice-ready number.
 */
final class InboundCallingGate
{
    /** Deployment-level: emergency switch on, calling stack configured and live. */
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

    /** Whether inbound calling may run for this Business: platform-wide, with no per-Business approval. */
    public function available(Business $business): bool
    {
        return $this->platformEnabled();
    }

    /** Available AND the Business has chosen a routing mode. */
    public function routable(Business $business, ?BusinessInboundCallSetting $setting): bool
    {
        return $this->available($business) && $setting !== null && $setting->mode !== BusinessInboundCallSetting::MODE_OFF;
    }
}
