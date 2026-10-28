<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * PR #295 Correction Round 1, item 4 — thrown before any real provider
 * request when this platform has not yet activated a wallet-billing rate
 * for the cost-incurring action (number purchase, 10DLC brand/campaign
 * registration, or toll-free verification). This is the fail-closed state
 * today: no approved retail charge exists yet for any of these actions, so
 * the real (Telnyx) provisioning adapter refuses every cost-incurring call
 * structurally, while FakeProvisioningAdapter — which never spends real
 * money — is entirely unaffected, preserving the existing UI/fake test
 * flow exactly as item 4 requires.
 */
class MessagingFundingUnavailableException extends RuntimeException
{
    /** No meter, no active rate, or metering not enabled: a platform-owner action is missing. */
    public const REASON_PRICING_NOT_CONFIGURED = 'pricing_not_configured';

    /** The Business's own wallet/currency cannot be matched to the meter. */
    public const REASON_WALLET_CONFIGURATION = 'wallet_configuration';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $reason = self::REASON_PRICING_NOT_CONFIGURED,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
