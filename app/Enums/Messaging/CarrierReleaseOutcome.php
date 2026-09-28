<?php

namespace App\Enums\Messaging;

/**
 * Phone Numbers + A2P lane — MessagingProvisioningAdapter::releaseNumber()'s
 * result. Deliberately binary and conservative: Confirmed is returned only
 * when the carrier itself has confirmed the number is no longer on the
 * account (a successful delete response, or a 404 to the delete call,
 * which is standard idempotent-delete semantics for "already gone" rather
 * than "still active"). Every other outcome — a non-2xx/non-404 response,
 * or a transport-level exception/timeout — is NotConfirmed, never guessed
 * as a success; NumberLifecycleManager::confirmCarrierRelease() never
 * writes BusinessMessagingNumberStatus::Released without Confirmed.
 */
enum CarrierReleaseOutcome: string
{
    case Confirmed = 'confirmed';
    case NotConfirmed = 'not_confirmed';
}
