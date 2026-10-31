<?php

namespace App\Library\Messaging\DTO;

/**
 * Outcome of a READ-ONLY progress check of an accepted number order.
 * SUCCESS carries the Telnyx phone-number RESOURCE id (never an order-item id).
 */
final readonly class NumberOrderCheckResult
{
    public const SUCCESS = 'success';

    /** Accepted, not finished (or the number is not visible yet). */
    public const PENDING = 'pending';

    /** The carrier definitively reports the order failed. */
    public const FAILED = 'failed';

    /** The carrier could not be read right now; nothing was concluded. */
    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $outcome,
        public ?string $providerPhoneNumberId,
        public ?string $purchasedAt,
        public ?string $detail,
    ) {
    }
}
