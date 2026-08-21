<?php

namespace App\Enums\Usage;

/**
 * additional_business_slot_agreements.billing_cadence (RFC-005 §18, M4
 * contract §18/§19). monthly only, v1 — independent of RFC-004's own
 * uncast workspace_plan_catalog.billing_cycle.
 */
enum SlotAgreementBillingCadence: string
{
    case Monthly = 'monthly';
}
