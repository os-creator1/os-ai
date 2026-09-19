<?php

declare(strict_types=1);

namespace App\Library\Money\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by CurrencyExponent when an amount is outside Stripe's documented
 * minimum/maximum charge bounds, before any provider call is made
 * (Implementation Contract 17 §4.6).
 */
class AmountOutOfBoundsException extends InvalidArgumentException
{
}
