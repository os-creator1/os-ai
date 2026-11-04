<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * The number's price cannot be confirmed as exactly what the customer
 * agreed to (no quote, a changed price, no longer available, or the
 * carrier could not be asked). Raised before any funds are reserved or
 * order placed; the customer must search and confirm the price again. The
 * message is safe to show.
 */
class NumberPriceChangedException extends RuntimeException
{
}
