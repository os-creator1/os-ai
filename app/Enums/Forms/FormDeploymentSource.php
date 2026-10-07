<?php

namespace App\Enums\Forms;

/**
 * Forms V1 — where a form is offered from. A deployment is "this form, at this
 * Location, from this source"; its public uid is the deterministic evidence of
 * which Location a submission belongs to.
 *
 * Sources: the form's own public link, and the Website reference. The Website
 * case was added by the visual builder as the embeddable seam (see
 * docs/automation/FORMS-V1-DOMAIN-FOUNDATION-CONTRACT.md §11) — adding a case
 * is additive: the column is a plain string, so no migration is needed.
 */
enum FormDeploymentSource: string
{
    case DirectLink = 'direct_link';

    /** Offered to Website pages: the stable embeddable reference (see FormWebsiteEmbed). */
    case Website = 'website';
}
