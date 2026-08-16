<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by BillingProfileManager::updateBillingContact() (M2 contract
 * §6.F) when neither a contact_user_id nor both contact_name and
 * contact_email are supplied — defense in depth alongside the
 * UpdateBusinessBillingContactRequest FormRequest's own validation.
 */
class InvalidBillingContactDataException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct(
            "Business [{$businessId}]'s billing contact requires either a contact_user_id or both contact_name and contact_email."
        );
    }
}
