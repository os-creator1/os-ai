<?php

namespace App\Enums\Usage;

/**
 * additional_business_slot_renewal_charges.charge_kind (RFC-005 §18/§22,
 * M4 contract §18/§19) — orthogonal to initiated_by: an admin_retry may
 * retry either kind.
 */
enum SlotRenewalChargeKind: string
{
    case ScheduledRenewal = 'scheduled_renewal';
    case MidPeriodIncrease = 'mid_period_increase';
}
