<?php

declare(strict_types=1);

namespace App\Library\Money\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by CurrencyExponent for a currency code outside its three explicit
 * tiers (or not a 3-letter code at all). Fail closed — never a silent
 * two-decimal guess (Implementation Contract 17 §4.6).
 */
class UnsupportedCurrencyException extends InvalidArgumentException
{
}
