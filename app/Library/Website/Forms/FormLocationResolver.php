<?php

namespace App\Library\Website\Forms;

use App\Models\BusinessLocation;
use App\Models\WebsiteForm;
use Illuminate\Validation\ValidationException;

/**
 * The one place a public form submission's Location is decided
 * (Blueprint §14/§16, Addendum §13).
 *
 * THE RULE: the Location is the one the FORM DEFINITION carries
 * (`website_forms.location_id`) — the form a visitor posted to is the
 * deterministic evidence of where the lead belongs. Nothing else is
 * consulted: not the visitor's IP or GPS, not "the Business's first
 * Location", not a Contact's current Location, not a session guess.
 *
 * FAIL CLOSED. The form's Location is re-read from persistence on every
 * submission and must BELONG TO THE FORM'S OWN BUSINESS (the Business its
 * Website belongs to) and be ACTIVE. A form with no Location, a Location of
 * another Business, or an archived Location resolves nothing and the
 * submission is refused — no row, no Contact, no Opportunity.
 *
 * A visitor-posted Location identifier is never a source. If one is posted
 * (`location_uid`) it may only RESTATE the form's Location; any other value
 * — a sibling Location of this Business, another Business's — is refused
 * rather than ignored, so a forged identifier fails loudly.
 */
final class FormLocationResolver
{
    public const POSTED_LOCATION_FIELD = 'location_uid';

    public function resolve(WebsiteForm $form, ?string $postedLocationUid = null): BusinessLocation
    {
        $businessId = (int) $form->website()->value('business_id');

        $location = $form->location_id === null
            ? null
            : BusinessLocation::query()->where('id', $form->location_id)->where('business_id', $businessId)->first();

        if ($location === null || ! $location->isActive()) {
            throw ValidationException::withMessages([
                'form' => ['This form is not accepting submissions right now.'],
            ]);
        }

        $posted = trim((string) $postedLocationUid);

        if ($posted !== '' && $posted !== (string) $location->uid) {
            throw ValidationException::withMessages([
                self::POSTED_LOCATION_FIELD => ['That location is not valid for this form.'],
            ]);
        }

        return $location;
    }
}
