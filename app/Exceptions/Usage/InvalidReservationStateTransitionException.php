<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown when commit()/release() is called against a reservation already
 * in a terminal status (committed/released/expired) other than the exact
 * idempotent no-op case (a repeat commit() on an already-committed
 * reservation, which is not an error — RFC-005 §13, M1 contract §8 item 2).
 */
class InvalidReservationStateTransitionException extends RuntimeException
{
    public function __construct(
        public readonly int $reservationId,
        public readonly string $currentStatus,
        public readonly string $attemptedOperation,
    ) {
        parent::__construct(
            "Reservation #{$reservationId} cannot {$attemptedOperation} from status '{$currentStatus}'."
        );
    }
}
