<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M4 contract §8/§14/§19 — mirrors UnauthorizedPayerAssignmentException's
 * exact shape. Thrown for checkout/increase/cancellation owner-consent
 * checks and by assertPlatformAdministrator() (agreementId null for that
 * general check, since it is not scoped to one agreement).
 *
 * Carries only numeric identifiers — never User, Business, or Workspace
 * names, company, email, phone, or address.
 */
class UnauthorizedSlotAgreementActionException extends RuntimeException
{
    public function __construct(
        public readonly int $actorUserId,
        public readonly ?int $agreementId,
        public readonly string $action,
    ) {
        $suffix = $agreementId !== null ? " on additional-slot agreement [{$agreementId}]." : '.';

        parent::__construct("User [{$actorUserId}] is not authorized to perform [{$action}]{$suffix}");
    }
}
