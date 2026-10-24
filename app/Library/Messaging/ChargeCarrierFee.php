<?php

namespace App\Library\Messaging;

use App\Library\Usage\UsageWalletManager;
use App\Models\Business;

/**
 * Explicitly bills a real carrier charge (for example a 10DLC campaign
 * review) to the Business whose wallet it belongs to, at the carrier's exact
 * cost times the owner's multiplier (zero markup by default). It exists so
 * a cost the platform genuinely incurred is billed on purpose, with an audit
 * trail and an idempotent operation id — never invented, never absorbed
 * silently, never charged twice.
 */
final class ChargeCarrierFee
{
    public const FEATURE = 'messaging_carrier_pass_through';

    public function __construct(
        private readonly UsageWalletManager $wallet,
        private readonly MessagingChargePreview $preview,
    ) {
    }

    /**
     * @return array{status: 'charged'|'already_charged'|'not_configured'|'refused', reason: ?string}
     */
    public function charge(Business $business, int $carrierCostMicro, string $operationId, string $feature = self::FEATURE): array
    {
        if ($carrierCostMicro < 1) {
            return ['status' => 'refused', 'reason' => 'amount_must_be_positive'];
        }

        if ($this->preview->multiplier($feature) === null) {
            return ['status' => 'not_configured', 'reason' => null];
        }

        $result = $this->wallet->reserve(
            $business,
            $feature,
            sprintf('carrier_fee:%d:%s', $business->id, $operationId),
            bcdiv((string) $carrierCostMicro, '1000000', 6),
        );

        if (! $result->granted || $result->reservationId === null) {
            return ['status' => 'refused', 'reason' => $result->denialReason];
        }

        // reserve() is idempotent on the operation id: a replay returns the
        // same reservation, and commit() on a committed one is a no-op.
        $this->wallet->commit($result->reservationId);

        return ['status' => $result->createdByThisInvocation ? 'charged' : 'already_charged', 'reason' => null];
    }
}
