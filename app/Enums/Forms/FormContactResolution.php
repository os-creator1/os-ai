<?php

namespace App\Enums\Forms;

/**
 * Forms V1 — how a submission reached (or failed to reach) a Contact. Persisted
 * on the submission and carried by `FormSubmissionRecorded`, so a later
 * Automations lane can tell "a brand-new lead" from "someone we already know"
 * from "we could not safely pick a person" without re-deriving it.
 *
 *   Created   — no Contact at this Location had this phone; one was created.
 *   Matched   — exactly one Contact at this Location had this phone; linked,
 *               never modified.
 *   Ambiguous — several Contacts at this Location share the phone (legacy
 *               duplicates). NONE is chosen; the submission is kept with no
 *               Contact link so a person can decide. Fails closed.
 *   None      — the submission carried no phone (the field was optional and
 *               left empty, or the form has no phone field), so there was
 *               nothing to resolve.
 */
enum FormContactResolution: string
{
    case Created = 'created';
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case None = 'none';
}
