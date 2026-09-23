<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Implementation Contract 17 §7.4 / §8.7 — every reason a refund can be
 * refused, as one closed vocabulary.
 *
 * No provider message, account id or amount belonging to another tenant ever
 * reaches these strings.
 */
final class RefundException extends RuntimeException
{
    /** The payment is not `succeeded`, so there is nothing captured to refund. */
    public const PAYMENT_NOT_SUCCEEDED = 'payment_not_succeeded';

    /** §8.7 — the request exceeds the remaining refundable capacity. */
    public const EXCEEDS_REFUNDABLE = 'exceeds_refundable';

    /** A non-positive amount is not a refund. */
    public const INVALID_AMOUNT = 'invalid_amount';

    /** The payment has no provider intent to refund against. */
    public const NO_PROVIDER_PAYMENT = 'no_provider_payment';

    /** The payment's historical connection row is gone. */
    public const CONNECTION_UNAVAILABLE = 'connection_unavailable';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason, match ($reason) {
            self::PAYMENT_NOT_SUCCEEDED => 'Only a settled payment can be refunded.',
            self::EXCEEDS_REFUNDABLE => 'That is more than the amount still available to refund.',
            self::INVALID_AMOUNT => 'Enter a refund amount greater than zero.',
            self::NO_PROVIDER_PAYMENT => 'This payment has no provider record to refund.',
            self::CONNECTION_UNAVAILABLE => 'The Stripe connection this payment was taken on is unavailable.',
            default => 'That refund could not be started.',
        });
    }

    public function customerMessage(): string
    {
        return $this->getMessage();
    }
}
