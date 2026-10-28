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
}
