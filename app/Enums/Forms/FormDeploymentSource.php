<?php

namespace App\Enums\Forms;

/**
 * Forms V1 — where a form is offered from. A deployment is "this form, at this
 * Location, from this source"; its public uid is the deterministic evidence of
 * which Location a submission belongs to.
 *
 * V1 has exactly one source, the form's own public link. The Website lane
 * will add its own case when it consumes this domain (see
 * docs/automation/FORMS-V1-DOMAIN-FOUNDATION-CONTRACT.md §11) — adding a case
 * is additive: the column is a plain string, so no migration is needed.
 */
enum FormDeploymentSource: string
{
    case DirectLink = 'direct_link';
}
