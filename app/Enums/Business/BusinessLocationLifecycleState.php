<?php

namespace App\Enums\Business;

/**
 * Customer Experience Slice 1A — the physical-location lifecycle
 * (contract §7.3a).
 *
 * A `BusinessLocation` is only a physical branch, storefront, office or
 * service area inside one Business. It is never a tenancy, payer, wallet,
 * authorization or account-switcher boundary.
 *
 * Deliberately NOT Laravel SoftDeletes: a soft-deleted row disappears from
 * default queries and from the business_google_locations relationship,
 * whereas an archived location must stay fully readable for historical
 * records, GBP bindings, billing evidence, reactivation and audit history.
 * Archiving is a state change, never a delete (§7.3a rule 8).
 */
enum BusinessLocationLifecycleState: string
{
    /** Counts toward the Business's active physical-location capacity. */
    case Active = 'active';

    /** Retained in full, readable everywhere, consumes no capacity. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
