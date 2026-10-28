<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * The carrier registry's own rules refuse this registration as declared
 * (brand not eligible for the campaign type, or the declared purposes fall
 * outside its minimum/maximum). The message is safe to show the customer.
 * Raised before a campaign is created: nothing is charged and nothing the
 * customer declared is ever dropped to fit.
 */
class MessagingRegistrationNotEligibleException extends RuntimeException
{
    /**
     * True when the carrier could not be asked or did not answer clearly
     * (transport error, rate limit, server error, unreadable rules, a brand
     * the carrier has not finished verifying). Nothing was concluded about
     * the business, so the customer may simply retry; it is never recorded as
     * definitive ineligibility.
     */
    public bool $temporary = false;

    public static function temporary(string $message): self
    {
        $e = new self($message);
        $e->temporary = true;

        return $e;
    }
}
