<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Implementation Contract 17 §7.2/§7.3 — every reason PAY START can refuse.
 *
 * The public surface maps ALL of these onto the same uniform refusal as the
 * rest of the secure-link surface (§6.3): an end customer must not be able to
 * tell "this document is not signed yet" from "this Business is not payment
 * ready" from "someone else is already paying". `reason` exists for our own
 * tests and control flow only, and never carries a client secret, an account
 * id or a provider message.
 */
final class PaymentStartException extends RuntimeException
{
    /** §7.3 — requires_signature document still merely `sent`. */
    public const NOT_SIGNED = 'not_signed';

    /** §7.3 — the balance cannot be paid before the deposit succeeds. */
    public const DEPOSIT_OUTSTANDING = 'deposit_outstanding';

    /** The document's lifecycle forbids payment entirely. */
    public const DOCUMENT_NOT_PAYABLE = 'document_not_payable';

    /** The item belongs to a superseded version, or to no current version. */
    public const NOT_CURRENT_VERSION = 'not_current_version';

    /** Nothing is currently payable (all settled, or none pending). */
    public const NOTHING_PAYABLE = 'nothing_payable';

    /** §11.4 — the Business has no active, charge-enabled connection. */
    public const NOT_PAYMENT_READY = 'not_payment_ready';

    /** The schedule item named does not belong to this document. */
    public const ITEM_NOT_FOUND = 'item_not_found';

    private function __construct(public readonly string $reason)
    {
        parent::__construct('Payment cannot be started.');
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
