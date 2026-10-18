# Forms / Questionnaires V1 — lead capture completion

Blueprint §16 (definitions Business-wide, submissions Location-bound), §14
(deterministic Location for website leads), §10 (Location-local Contact
identity), Addendum §5/§13.

## Canonical architecture

There is **one** form-definition model: `website_forms` (`WebsiteForm`). It is
Business-wide because a Business has exactly one Website (§14) and tenancy is
reached through `websites.business_id`. `WebsiteForm` is the *definition and
its presentation config* (fields, button label); the Website renderer consumes
it through the immutable published snapshot. `QuestionPack` is the Website
*generation* questionnaire (niche questions asked once during setup) and is not
a lead-capture form — it is untouched.

There is **one** submission writer: `WebsiteFormSubmissionService::submit()`.
The public `WebsiteFormController` is a consumer of it; nothing else writes
`website_form_submissions`.

```
public POST (form uid + page uid, published snapshot proves both)
  -> FormLocationResolver        the Location the FORM carries; fail closed
  -> Contact find/create         Business + Location + phone (Location-local)
  -> CrmOpportunityService       when the definition asks for one
  -> website_form_submissions    the durable record
  -> FormSubmissionRecorded      after commit
```

One transaction. A failure anywhere leaves no submission, Contact, Opportunity
or event.

## Location resolution rule

The Location is `website_forms.location_id` — "the form itself carries the
Location" (§16). Nothing else is consulted: not IP/GPS, not the Business's first
Location, not a Contact's current Location, not a session guess.

Re-read from persistence on every submission, inside the form-row lock, and it
must **belong to the form's own Business** and be **Active**. Otherwise the
submission is refused (no row, no Contact, no Opportunity) with
`form: This form is not accepting submissions right now.`

A visitor-posted `location_uid` is never a source. It may only *restate* the
form's Location; any other value (a sibling Location, another Business's) is
refused rather than ignored.

A Business with several Locations gives each Location its own form (the form is
placed on that Location's page, §14). `UNIQUE (website_id, type, location_id)`.
A form with no Location (a multi-Location Business that has not chosen one) is
shown as "No location" on the Forms screen and accepts nothing. The migration
backfills legacy forms/submissions only when the Business has exactly one Active
Location (Contract 08B §5, as the Contacts/Opportunities backfills do).
`WebsiteStarterDraftService::ensurePhotoBoothQuoteForm()` applies the same
single-Active-Location rule when the generator creates the form.

## Contact matching

Identity is **Location-local** (§10): `business_id + location_id + phone`.

| Situation | Result | `contact_resolution` |
|---|---|---|
| No such Contact | created (Location set, **unsubscribed**, no automation dispatch) | `created` |
| One Contact at this Location | linked, **not modified** | `matched` |
| Several Contacts at this Location share the phone | **no row picked, none created**; submission kept, no Opportunity | `ambiguous` |
| Same phone at another Location of the Business | separate Contact (no cross-Location merge in V1) | `created` |
| Same phone in another Business | unreachable (Business is part of the key) | `created` |
| No phone submitted | no Contact | `none` |

The lookup is serialized with the Calendar booking seam on the existing
`booking_contact_identity_locks` (Location + phone) row, so a form and a booking
racing for one new person cannot create two Contacts. A matched Contact is never
overwritten by an anonymous public submission. A blacklisted phone refuses the
whole submission (rolled back).

## Opportunity behaviour

When `create_opportunity` is true (default) and the Business has an active
pipeline, one `CrmOpportunity` is created through `CrmOpportunityService::create()`
with `source = website_form`, the Business, the **form's Location** (new optional
`?BusinessLocation $location` argument — refused if it is another Business's or
differs from the Contact's Location), the Contact, the form's configured
pipeline (`crm_pipeline_id`; the Business's first active pipeline when unset) and
that pipeline's starting stage. Every distinct submission is a distinct inquiry
and opens its own Opportunity; a *retry of the same submission* never does. A
pipeline that cannot take a deal never costs the Business the inquiry.
Automation triggers on Opportunities are untouched.

## Idempotency

The rendered form carries a hidden `submission_token` (a UUID, one per page
render). `(website_form_id, idempotency_key)` is `UNIQUE`; the same token posted
again — double-click, browser retry, network retry, two concurrent requests —
returns the original row and writes nothing. Concurrent requests are serialized
by the `website_forms` row lock, so the second sees the first's row.

Content is **never** compared: two separate inquiries (fresh page render, fresh
token) that happen to read alike are both kept, and a post with no/invalid token
is its own submission. The previous 10-minute content-hash dedupe
(`dedupe_key`) silently dropped such repeats and is no longer written (column
kept, now nullable, for legacy rows).

## Submission record

`website_form_submissions`: `location_id`, `contact_id`, `crm_opportunity_id`,
`contact_resolution`, `page_slug` + `page_uid` (a home page has a NULL slug),
`source_revision_id` (the published revision the visitor saw), `data` (only the
published fields), `idempotency_key`, `ip_hash` (never a raw IP), `is_spam`,
`status`. Field values are validated against the **published snapshot's** fields,
so a later definition edit cannot change what an old submission means.

## Durable event for Automations

`App\Events\Forms\FormSubmissionRecorded` (`form_submitted`), `ShouldDispatchAfterCommit`,
ids only:

`businessId`, `locationId`, `formId`, `formUid`, `submissionId`, `submissionUid`,
`contactId` (null when ambiguous / no phone), `opportunityId` (null when none),
`contactResolution`, `occurrenceKey` (`form_submitted:{submissionId}`, from the
persisted row).

Raised once per logical submission; never for spam, a replay, or a rolled-back
submission. **Not yet a `WorkflowTriggerType`** and nothing in Automations is
edited — the later Automations lane consumes this event.

## Validation / security

Only the form's published fields are accepted; anything else in the request is
discarded (never stored, never mapped onto a Contact/Business). Fields are
string-typed (arrays and uploaded files fail the field rule — V1 forms have no
file fields). Required/type rules per `WebsiteFormFieldType`; phone must be 7–15
digits. The whole payload is bounded (32 KB). Honeypot spam is recorded but
creates nothing. The route keeps `web` (CSRF) and `throttle:10,1`.

## Customer surface

Business → Website → Forms: list definitions (Location, active state, inquiry
count), create the Photo Booth quote form (for a Location), edit (name, button,
Location, accepting submissions, opportunity + pipeline), and view inquiries.
Inquiries are filtered to the Locations the viewer may reach
(`LocationAccessGuard::accessibleLocationIdsForBusiness`), paginated 25/page with
a constant query count. Legacy rows with no Location are visible only to a viewer
who reaches every Location. Linked submissions also appear on the Contact
activity timeline (`FormSubmissionActivitySource`).

## Shared-file notes

Narrow edits outside this lane's own files, for reviewers watching the Website
lane: `resources/views/public/website/components/form.blade.php` (hidden token
input + visible error line), `Public\WebsiteFormController::submit()` (passes the
revision and page uid), `WebsiteStarterDraftService::ensurePhotoBoothQuoteForm()`
(one default: `location_id`), and `GuidedGenerationContactFormSubmissionTest`
(creates a Location, since a Location-less form now accepts nothing).
