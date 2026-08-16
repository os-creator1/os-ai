<?php

namespace App\Enums\Usage;

/**
 * Twelve entry types (RFC-005 §13). M1's own code only ever writes
 * Reservation/ReservationRelease/UsageCharge/UsageOverageCharge — the
 * remaining eight are structurally defined here (this enum is not itself
 * milestone-partitioned) but unexercised until M2+/M3+/M4 ship the
 * managers/authority that produce them.
 */
enum UsageLedgerEntryType: string
{
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';
    case UsageCharge = 'usage_charge';
    case UsageOverageCharge = 'usage_overage_charge';
    case PaidTopUp = 'paid_top_up';
    case AutoRecharge = 'auto_recharge';
    case ManualCredit = 'manual_credit';
    case PromotionalCredit = 'promotional_credit';
    case Refund = 'refund';
    case DisputeChargeback = 'dispute_chargeback';
    case UsageChargeReversal = 'usage_charge_reversal';
    case CorrectionReversal = 'correction_reversal';
}
