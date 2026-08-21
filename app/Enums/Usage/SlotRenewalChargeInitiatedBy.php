<?php

namespace App\Enums\Usage;

/**
 * additional_business_slot_renewal_charges.initiated_by (RFC-005 §18/§22,
 * M4 contract §18/§19). owner_initiated covers both a synchronous
 * mid-period-increase charge and an owner's own post-lapse recovery
 * attempt; admin_retry covers a platform administrator's resume/recovery
 * action.
 */
enum SlotRenewalChargeInitiatedBy: string
{
    case ScheduledJob = 'scheduled_job';
    case OwnerInitiated = 'owner_initiated';
    case AdminRetry = 'admin_retry';
}
