<?php

namespace App\Library\Merge;

use App\Models\AgencyProspect;
use App\Models\AgencyProspectingSetting;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\CrmOpportunity;

/**
 * The explicit facts a piece of personalised text is rendered against.
 *
 * Nothing here is looked up on the resolver's behalf: an Opportunity or an
 * Appointment is present only because the caller KNOWS which one the text is
 * about (an enrollment's trigger fact, a document's linked deal). When it is
 * null, `{{opportunity.*}}` / `{{appointment.*}}` resolve to the documented
 * missing-value behaviour — the resolver never goes looking for a "latest" row.
 *
 * Authorising the Contact (Location ACL) is the CALLER's job; the resolver only
 * proves that every record in the context belongs to the same Business and
 * otherwise treats the mismatched one as absent (see MergeFieldResolver).
 */
final readonly class MergeContext
{
    public function __construct(
        public Business $business,
        public ?Contacts $contact,
        public ?BusinessLocation $location = null,
        public ?CrmOpportunity $opportunity = null,
        public ?Appointment $appointment = null,
        public ?AgencyProspect $prospect = null,
        public ?AgencyProspectingSetting $outreach = null,
    ) {
    }

    /** The context of a Contact on its own: its Business and its own Location. */
    public static function forContact(Contacts $contact, ?Business $business = null): ?self
    {
        $business ??= $contact->business;

        if ($business === null || (int) $contact->business_id !== (int) $business->id) {
            return null;
        }

        return new self($business, $contact, $contact->location_id === null ? null : $contact->location);
    }
}
