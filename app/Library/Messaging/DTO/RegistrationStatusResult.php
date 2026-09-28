<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\MessagingRegistrationStatus;

/**
 * Text messaging setup/number/compliance hub — review correction:
 * MessagingProvisioningAdapter::refreshRegistrationStatus() used to return
 * a bare MessagingRegistrationStatus, so a Rejected answer carried no
 * carrier-supplied reason anywhere past the adapter boundary —
 * BusinessMessagingRegistrationService::refreshStatus() had nothing to
 * store, and the customer only ever saw a generic fallback message,
 * whether or not Telnyx actually supplied a specific one.
 *
 * $rejectionReason is null whenever the status is not Rejected, and is
 * also null for a genuinely reason-less Rejected answer — the two cases
 * are indistinguishable to a caller, exactly as they should be: BOTH mean
 * "show the honest, explicit fallback copy," never a guessed reason.
 */
final readonly class RegistrationStatusResult
{
    public function __construct(
        public MessagingRegistrationStatus $status,
        public ?string $rejectionReason = null,
    ) {
    }
}
