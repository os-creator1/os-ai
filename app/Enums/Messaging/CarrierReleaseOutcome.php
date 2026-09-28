<?php

namespace App\Enums\Messaging;

/**
 * Phone Numbers + A2P lane — MessagingProvisioningAdapter::releaseNumber()'s
 * result. Deliberately binary and conservative: Confirmed is returned only
 * once BOTH the resource identity (its phone_number matches what this
 * platform believes it is releasing) AND its deleted state have been
 * positively verified — either a lookup that already shows the number
 * deleted, or a delete call whose own response confirms the same. A 404
 * from the delete call is never, by itself, proof of a prior successful
 * release (it could just as easily mean a wrong or stale provider
 * reference) and is always NotConfirmed. Every ambiguous or unverifiable
 * result — a lookup/delete that cannot be matched by identity, a delete
 * response that does not clearly confirm the deleted state, a non-2xx
 * response, or a transport-level exception/timeout — is likewise
 * NotConfirmed, never guessed as a success; NumberLifecycleManager::
 * confirmCarrierRelease() never writes BusinessMessagingNumberStatus::
 * Released without Confirmed, and leaves the number Suspended with a
 * visible, retryable failure record otherwise.
 */
enum CarrierReleaseOutcome: string
{
    case Confirmed = 'confirmed';
    case NotConfirmed = 'not_confirmed';
}
