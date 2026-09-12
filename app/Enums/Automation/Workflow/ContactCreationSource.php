<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §9 / §1.4 — how a Contact came into existence, declared by the
 * code path that created it.
 *
 * THE POINT OF A CLOSED VOCABULARY. The `contact_created` trigger may be
 * filtered to one source ("only when someone signs up through the opt-in
 * form"), and that filter decides whether a real customer is messaged. So the
 * source is an explicit argument passed by each creation path — never inferred
 * from the URL, the route name, the group, the request shape, the contact's own
 * fields, or whether anyone was authenticated. Inference is how a manual
 * back-office import ends up treated as consent.
 *
 * THE MAPPING, taken from the callers as they exist in this repository:
 *
 *   Manual     Customer\ContactsController::storeContact() — the in-app
 *              "Add contact" form, behind the create_contact permission.
 *   OptInForm  Customer\ContactsController::subscribeContact() — the PUBLIC
 *              subscribe page (recaptcha-gated, no authenticated actor). The
 *              only path that is genuinely an opt-in.
 *   Api        API\ContactsController::storeContact() and
 *              API\ContactsHTTPController::storeContact() — the customer's own
 *              API key creating a contact programmatically.
 *   Other      the default for a seam caller that declares nothing. It fires
 *              unfiltered `contact_created` workflows and matches NO source
 *              filter, so a path that forgets to declare itself can never be
 *              mistaken for consent.
 *
 * WHY THERE IS NO `Import` CASE. Bulk import (`ContactGroups::import()` via the
 * `ImportContacts` job) writes contacts with raw SQL and passes through neither
 * creation seam, so it fires no `contact_created` trigger at all — in v2 exactly
 * as in B4 (§6.B). A case for it would describe a path that cannot occur, and
 * would let a workflow be filtered to a source nothing will ever send. If import
 * is ever routed through a seam, it brings its own case and its own tests.
 */
enum ContactCreationSource: string
{
    case Manual = 'manual';
    case OptInForm = 'opt_in_form';
    case Api = 'api';
    case Other = 'other';

    /**
     * Whether a trigger's `source` filter admits a contact created this way.
     *
     * TWO VOCABULARIES, ON PURPOSE. This enum says how a contact was ACTUALLY
     * created, one case per real path. The filter names what a customer can ASK
     * FOR in the builder, and that vocabulary is V2-0's
     * NodeTypeRegistry::CONTACT_SOURCES — `any`, `opt_in_form`, `in_app` — which
     * the validator already enforces at publish time. Mapping between them here
     * keeps the builder's choices stable while this slice stays truthful about
     * the paths that exist:
     *
     *   any          every creation path.
     *   opt_in_form  the public subscribe page only. Nothing else is consent.
     *   in_app       a person adding a contact inside the application.
     *
     * `Api` and `Other` deliberately satisfy only `any`. An API key creating a
     * contact programmatically is not a person working in the app, and it is
     * certainly not an opt-in, so a workflow filtered to either specific source
     * must not fire for it. An unrecognised filter matches nothing at all: a
     * filter this product cannot honour must not be read as "fire for
     * everyone".
     */
    public function matchesFilter(string $filter): bool
    {
        return match ($filter) {
            'any' => true,
            'opt_in_form' => $this === self::OptInForm,
            'in_app' => $this === self::Manual,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Added by hand',
            self::OptInForm => 'Signed up through the opt-in form',
            self::Api => 'Created through the API',
            self::Other => 'Created another way',
        };
    }
}
