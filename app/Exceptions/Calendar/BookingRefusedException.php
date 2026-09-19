<?php

namespace App\Exceptions\Calendar;

use RuntimeException;

/**
 * Implementation Contract 15 §7 — the base for every refusal the booking
 * engine raises. Each one is thrown INSIDE the transaction, before any write,
 * so the transaction rolls back and nothing partial survives — and, per §7.4
 * step 4, no event is dispatched.
 */
abstract class BookingRefusedException extends RuntimeException
{
}
