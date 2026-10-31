<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * The PLATFORM's carrier account (not the customer's wallet) could not fund a
 * number order (Telnyx HTTP 402 / 20100). Nothing was ordered or charged. The
 * customer is told purchases are temporarily unavailable — never to keep trying
 * other numbers — and operators get a critical log and an incident.
 */
class MessagingPlatformFundsException extends RuntimeException
{
}
