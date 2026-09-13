<?php

namespace App\Library\Messaging;

use App\Models\Business;
use App\Models\BusinessMessagingProvisioningIncident;

/**
 * PR #295 Correction Round 1, item 3 — "if the provider succeeds but local
 * finalization fails, persist enough reconciliation state that the
 * provider resource is never silently lost or invisible." This is the one
 * place that state gets written, from both
 * BusinessMessagingProvisioningService (a post-provider-success local
 * attach failure) and TelnyxProvisioningAdapter itself (a Messaging
 * Profile created but the subsequent number order failing).
 */
final class ProvisioningIncidentRecorder
{
    public function record(
        Business $business,
        string $stage,
        ?string $messagingProfileId,
        ?string $providerPhoneNumberId,
        ?string $phoneNumber,
        ?string $numberType,
        ?string $errorMessage,
    ): BusinessMessagingProvisioningIncident {
        return BusinessMessagingProvisioningIncident::create([
            'business_id' => (int) $business->id,
            'stage' => $stage,
            'messaging_profile_id' => $messagingProfileId,
            'provider_phone_number_id' => $providerPhoneNumberId,
            'phone_number' => $phoneNumber,
            'number_type' => $numberType,
            'error_message' => $errorMessage,
        ]);
    }
}
