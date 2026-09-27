<?php

namespace App\Library\Messaging;

use App\Models\Business;
use App\Models\BusinessMessagingProvisioningIncident;
use Illuminate\Support\Facades\DB;

/**
 * PR #295 Correction Round 1, item 3 — "if the provider succeeds but local
 * finalization fails, persist enough reconciliation state that the
 * provider resource is never silently lost or invisible." This is the one
 * place that state gets written, from both
 * BusinessMessagingProvisioningService (a post-provider-success local
 * attach failure) and TelnyxProvisioningAdapter itself (a Messaging
 * Profile created but the subsequent number order failing).
 *
 * Phone Numbers + A2P lane — resolve() is this same single-writer seam's
 * other half: closing an incident out once a platform operator has
 * reconciled the provider and local state by hand. It is deliberately the
 * only path that can ever set resolved_at; nothing else in this codebase
 * writes it.
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

    /**
     * Idempotent, auditable resolution — an atomic conditional UPDATE
     * mirroring EloquentPaymentProviderEventRepository::dispose()'s exact
     * shape. Only a row that is still unresolved is touched, so a repeated
     * or concurrent resolve attempt on the same incident updates zero rows
     * rather than re-timestamping it or overwriting who resolved it and why
     * — the caller (the controller) turns "0 rows" into a clear "already
     * resolved" response instead of silently succeeding twice.
     *
     * @return int the number of rows updated — 1 on a genuine resolution, 0
     *             when the incident was already resolved (or does not exist)
     */
    public function resolve(int $incidentId, int $resolvedByUserId, string $note): int
    {
        return DB::table('business_messaging_provisioning_incidents')
            ->where('id', $incidentId)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => $resolvedByUserId,
                'resolution_note' => $note,
                'updated_at' => now(),
            ]);
    }
}
