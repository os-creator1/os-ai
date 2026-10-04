# Forms V1 — domain foundation contract

Branch `agent/forms-v1-domain-foundation`. Manual lane (route 3 of `CLAUDE.md`);
scope comes from the lane's own task. This slice builds the **standalone** Forms /
Questionnaires domain and customer surface. It deliberately does **not** touch the
Website lane (§12).

Blueprint authority: `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §5 (definitions
Business-wide, submissions Location-bound), §10 (Contacts are Location-local) and
§16 (Forms and Questionnaires — "Questionnaires support multi-page flows").

Revision history: the foundation shipped at `a9463d5d`; **correction/completion round 1**
added (a) version pinning of every public flow (§6) and (b) multi-page questionnaires
(§4, §7); **round 2** made the final questionnaire step converge at one atomic boundary (§7).

> **Superseded in part by `FORMS-VISUAL-BUILDER-V1.md`.** The visual builder extended the closed element
> set (number, currency, date & time, multi-select, radio, yes/no, two separate consent types, and
> heading/paragraph/divider/spacer content blocks), added optional placeholder / help / width / default /
> first- and last-name markers, a versioned `design` style, and an optional stale-version guard on
> `FormManager::update()`. Everything else in this contract — versioning, pinning, Location deployment,
> sessions, idempotency, the event — is unchanged. §2's "Field types and bounds" and §13 ("file uploads,
> payment fields") should be read together with that document; the "one customer editor" described in §4 is
> now the visual builder.

## 1. Decisions this slice implements (authoritative, not re-derived)

1. Forms / Questionnaires is its **own V1 product surface**.
2. `PlatformFeature::Forms` already existed and is **the** entitlement. Forms is
   never authorized through `PlatformFeature::WebsiteGeneration`.
3. **Definitions are Business-wide.** One `forms` row per form, never one per Location.
4. Every operational **submission resolves to exactly one Location**.
5. Forms is its own customer navigation product, independent of whether the Business
   uses the Website module.
6. A form may later be embedded in the Website, linked directly, or used by a
   booking/follow-up flow. **Website is a consumer, not the Forms owner.**
7. `QuestionPack` (Website guided generation) is not reused as a customer lead form.
8. **One canonical definition model** serves both an ordinary form (one page) and a
   questionnaire (two or more ordered pages). There is no second Questionnaire product.
9. No central Automations file is edited. A durable event seam is exposed (§9, §11).

`agent/forms-lead-capture-v1-completion` is **not** continued and must not be merged:
it makes Forms a WebsiteGeneration capability.

## 2. Schema (one migration per change, each justified in its own docblock)

| Table | Purpose | Key invariants |
|---|---|---|
| `forms` | The Business-wide definition: identity, owner, name, lifecycle, `current_version` | `business_id` RESTRICT; `lifecycle_state` and `current_version` are not mass-assignable — only `FormManager` writes them |
| `form_versions` | The **immutable** statement of what a form asked: pages, fields, submit label, success message, CRM behaviour | unique `(form_id, version)`; the model refuses update/delete; no `updated_at`; `form_id` RESTRICT; `pages` is nullable JSON (a version predating pages reads as one implicit page) |
| `form_deployments` | "This form, at this Location, from this source" — the deterministic Location evidence | unique `(form_id, business_location_id, source)`; public `uid` is a real UUID; both FKs RESTRICT |
| `form_sessions` | The **in-progress** state of one multi-page questionnaire (§7) | unique `(form_deployment_id, operation_nonce)`; pinned `form_version_id`; bounded, expiring, prunable; stamped (never deleted) when the final submit commits |
| `form_submissions` | One logical **final** submission, an immutable fact | unique `(form_deployment_id, operation_nonce)` (idempotency) and unique `occurrence_key`; `contact_id`/`crm_opportunity_id` SET NULL; every other FK RESTRICT |

Plus `2026_10_14_120005_backfill_forms_customer_permission` (existing customers get the
new `forms` capability). Migration prefix `2026_10_14_1200xx`, distinct from the Website
(`…1000xx`) and Business Email (`…1100xx`) lanes.

**No plan-packaging migration is needed.** `forms` is already packaged for Core, Growth
and Agency, and its usage-classification row exists. The Planned → Available flip in
`PlatformFeatureRegistry` is the only entitlement change.

### Field types and bounds (closed, deliberately small)

`text`, `textarea`, `email`, `phone`, `select`, `checkbox`, `date`. Bounds
(`FormDefinitionNormalizer`): at most **8 pages**, **25 fields per page**, **40 fields in
total**; one `phone` field (the Contact identity key) and one "contact name" field across the
**whole** definition; 2–20 options per `select`. **No branching or conditional-expression
engine** — pages are shown in their stored order, always. Not supported on purpose: file
upload, conditional logic, repeating groups, payment fields.

## 3. Definition, versioning, lifecycle (`FormManager`, `FormDefinitionNormalizer`)

* `create()` writes a **draft** and version 1. `update()` writes a new immutable version
  **only when the content hash changes**; whitespace, key order or blank rows write nothing.
  The hash covers the pages, so **reordering pages is a real new version**.
* The editor round-trips each question's stable `key` (a hidden input), so a relabelled
  question keeps its key; an API caller that omits keys gets keys re-derived from labels.
  A page likewise keeps its key wherever it is moved.
* Lifecycle `draft → active ⇄ inactive`. Only `active` accepts submissions.
* Every method re-loads the form **fresh, under `lockForUpdate`, by primary key** and
  compares its own persisted `business_id` to the Business the caller claims.
* `create_opportunity` requires a phone field. The optional `opportunity_pipeline_id` must be
  an active pipeline of the Business; null means the first active pipeline.

## 4. One-page Form vs multi-page Questionnaire

Same `Form`, same `FormVersion`, same submission and event. The only difference is the page
structure written into the version:

| | Ordinary form | Questionnaire |
|---|---|---|
| Pages | exactly 1 (`isMultiPage() === false`) | 2–8 |
| Public flow | one GET, one POST | start → page → page → … → final page |
| In-progress state | none | a `form_sessions` row |
| Final | the one POST | the POST of the **last** page |

* The version stores `pages` (ordered `{key, title}`) and every field carries the `page` key
  of the **one** page it belongs to. A page with no field is dropped; pages are ordered by
  their `position` (ties keep submitted order).
* `FormVersion::pages()`, `fieldsOnPage()`, `pageIndex()`, `isMultiPage()` read the structure.
  A version whose `pages` is NULL (written before this round) reads as one implicit page
  `page_1` holding every field, so nothing already stored changes meaning.
* **Historical interpretation:** a `FormSubmission` points at its `FormVersion`, whose page +
  field structure fully explains exactly what the visitor was shown and answered. The
  customer's response view groups answers by that version's pages.
* **Customer editor:** a bounded set of page *slots* (`page_1`…`page_8`) with a title and an
  order, and a page selector on each question. Leave everything on page 1 for an ordinary form.

## 5. Location deployment

`FormManager::setDeployment()` offers/stops offering a form at one Location from one source.
The Location is **re-derived from persistence by id** and must belong to the form's Business.
A *new* deployment needs an Active Location; an existing one can always be switched off.

`FormDeploymentResolver` is the **one** place a public request's Location and authority are
decided (mirrors `PublicBookingController::resolve()`): the Location is the **deployment's**;
a posted `location_uid` may only **restate** it; every row is re-read and mutually proven; the
Business must be Active, the Workspace active, the account unlocked and the **`forms`**
entitlement held. Every refusal is one `FormUnavailableException` → one **404** on the public
link. A visitor-facing Location *picker* is deliberately not built.

## 6. Immutable version pinning

**The defect that was closed.** The token used to authenticate only deployment + nonce, so a
form rendered as version N and submitted after the owner published N+1 was validated and
stored as N+1.

**The canonical identity.** `FormOperationToken` is `<nonce>.<form_version_id>.<hmac>`; the
HMAC (application key) covers the **deployment uid, the version id and the nonce**. The version
the visitor saw therefore travels *inside* the authenticated token — changing either the nonce
or the version breaks the signature, and a posted raw `form_version_id` is ignored. A token
issued for one deployment is refused at another.

**On every submission** (`FormSubmissionService::submit`):

1. `FormDeploymentResolver::resolve()` re-proves the **current** authority: deployment enabled,
   Form `active`, Location Active and the deployment's own, Business Active, account unlocked,
   `forms` entitled.
2. The token is verified, then `FormDeploymentResolver::pin()` **re-reads the version by id from
   persistence** and proves it is a version of **this deployment's Form** (a version of another
   Form or Business is not found).
3. Answers are validated against **that pinned version**; the submission persists exactly that
   `form_version_id`; the replay/payload-hash comparison uses the same pinned version.

**Preferred behaviour:** an already-rendered, legitimate form or questionnaire finishes against
its pinned immutable version while the Form/deployment stays active; it is never silently
reinterpreted as the new version. **Pinning is identity, not authority**: an inactive Form, a
disabled deployment, an archived Location, a lost entitlement or a locked account still refuse an
old, validly signed token. The thank-you page shows the message of the version the visitor
completed (`?s=<submission uid>`, honoured only when that submission belongs to the deployment).

## 7. The questionnaire: sessions, pages, in-progress vs final

**Start.** `GET /forms/{deploymentUid}` issues **one** operation token, pinned to the current
version, and shows page 1. That token is the questionnaire's operation/session identity for the
whole flow; **two separately started questionnaires are two sessions** even with identical answers.

**Pages.** Every later page is addressed `GET /forms/{deploymentUid}/s/{token}/{pageKey}`; the
token (a per-flow bearer, like the secure document link) is part of the address. Next/back never
switch version. Back is a link to the earlier page, re-shown with the server-held answers.

**Server-side session (`FormSessionStore`, the only writer of `form_sessions`).** The answers
of completed pages are held **server-side**, already validated against the pinned version, keyed
by field key — the browser never carries an answer blob. A session is bounded (only keys of the
bounded pinned definition), pinned to one version, honoured for 24 hours from its last answer
(then refused; abandoned rows are `model:prune`-able a week after expiry — scheduling that is an
operations decision), and takes no answers after it is finalized.

**Order and fail-closed.** Page N can be opened or saved only once every earlier page is
completed; a questionnaire can only *start* on page 1; a stale, skipped, invented or
wrongly-signed page address is a **404** (GET) or a validation refusal (POST).

**In progress ≠ history.** A non-final step validates that page and records it in the session in
its own short transaction (`FormSessionStore::savePage`), then returns the next page. It creates
**no** Contact, Opportunity, `FormSubmissionRecorded` event or `form_submissions` row.

**The final step is ONE atomic boundary** (`FormSubmissionService::finishQuestionnaire`). The last
page never goes through `savePage`. Its transaction **begins by locking the session row**
(`FormSessionStore::lockForFinal`, `FOR UPDATE`, before any plain read so the transaction's
snapshot postdates any committed twin), and every decision that must agree is made while that lock
is held:

1. re-read the session authoritatively by `(deployment, nonce)`; re-prove its pinned version, its
   expiry and — for a first finish — that every earlier page is completed;
2. choose the final answer set: the **locked** session's answers with this last page laid over
   them, validated as the **complete** pinned definition;
3. if the session is **already finalized** this is a replay — identical answers converge on the
   winning submission, different answers are the existing idempotency conflict; nothing is written;
4. otherwise claim the submission (`form_submissions` unique index), resolve the Contact, create the
   Opportunity, link them, and stamp **the same locked session** with the submission **and with
   exactly the values the submission was built from**;
5. dispatch `FormSubmissionRecorded` after commit.

Consequently a concurrent page save either committed first (and is part of the answers chosen in
step 2) or arrives after finalization and finds a session it may not change: no page can land
between "final answers chosen" and "session finalized". The old split (session saved and released,
then a separate transaction claiming from a detached copy) allowed a submission built from answers
X beside a finalized session holding Y; that interleaving is impossible now. A finalized session is
a faithful copy of its submission (`answers == submission.values`) and is immutable — the model
refuses every Eloquent update once `finalized_at` is set and the store's page save returns it
untouched. Nothing external happens inside this transaction. Lock order is always session → Contact
identity, and nothing takes them in the other order.

**Authority at every boundary.** Every page view and every POST re-runs the resolver (§5), so a
switched-off form, disabled deployment, archived Location or lost entitlement mid-flow refuses the
next step and the final one.

## 8. The submission (`FormSubmissionService`)

1. **Current authority** (§5), 2. **token + pinned version** (§6), 3. **validation** — a one-page
form validates all fields; a questionnaire step validates only its page, and the final step
validates everything — producing normalized values keyed by field key (phone → digits, email →
lowercase, checkbox → bool, blank → null; unknown posted keys ignored), 4. **replay**, 5. **one
transaction** (retried on deadlock): INSERT the submission first as the **idempotency claim**;
resolve the Contact; create the Opportunity if configured; link both onto the submission;
finalize the session; dispatch `FormSubmissionRecorded` **after commit**.

**Idempotency.** One started form or questionnaire = one logical submission. Double-click,
retry and concurrent requests carry the same token and converge on the one row whose unique
`(deployment, nonce)` they claim; a replay creates and emits nothing; the same token with
**different** answers is refused. Two starts with identical text stay separate. The database
unique index is the real backstop. For a questionnaire, a replay of the final step is judged — under
the session lock — on what it posted laid over what was recorded, so a replay with different final
answers is refused with the same conflict and an identical one converges.

Anything that throws inside the transaction rolls the claim back with it: no submission, Contact,
Opportunity or event, and the token stays usable. A Contact created here is not subscribed and
fires no contact-created automation. If the configured pipeline is archived, the submission and
its Contact are still recorded and the Opportunity is skipped.

## 9. Contact resolution, concurrency and the Opportunity

`EloquentContactsRepository::findOrCreateForForm` — identity is **Business + Location + phone**:
`created` / `matched` (linked, never modified) / `ambiguous` (none chosen, none created, no
Opportunity) / `none`. Never merged across Locations or Businesses; a Location-less Contact is not
matched. It shares the `booking_contact_identity_locks` row with booking, taking the lock with a
**locking read before the transaction's first snapshot read** (an `exists()` pre-check first left
the winner's committed Contact invisible to the loser, which created a duplicate — reproduced
and fixed), and the claiming transaction retries on InnoDB deadlock.
`CrmOpportunityService::createAtLocation` is **ids-only** and re-derives Business, Location,
Contact (must sit at that Location), Pipeline and Stage from persistence.

## 10. Customer surface, authorization and navigation

Routes `customer.workspaces.businesses.forms.*`: `index`, `create`, `store`, `edit`, `update`,
`activate`, `deactivate`, `locations.set`, `submissions.index`, `submissions.show`. Public:
`public.forms.show|page|submit|thanks` (POST throttled 10/min; honeypot answered like a success
and stores nothing; every refusal a 404).

`AuthorizesFormsRequests`: tenancy (404) → **`forms` capability** (401) → **`PlatformFeature::Forms`**
(404) → for Location-scoped work, the Location ∈ this Business then `LocationAccessGuard` (404).
Submission visibility follows the operational Location through
`LocationAccessGuard::accessibleLocationIdsForBusiness()`; a filter naming an unreachable Location
is a 404; a page is 25 rows with a constant number of queries. Agency View As is the existing
authorization (all routes `BusinessScoped`). Forms is its own `CustomerMenuBuilder` entry.

## 11. Automation readiness

* **Canonical event:** `App\Events\Forms\FormSubmissionRecorded` (`ShouldDispatchAfterCommit`),
  **ids only**: `businessId`, `locationId`, `formId`, `formVersionId`, `submissionId`, nullable
  `contactId`/`opportunityId`, `contactResolution`, `occurrenceKey`.
* **Occurrence semantics.** `occurrenceKey = form_submission:<submission uid>`, a function of the
  persisted row alone and unique in the database. **Exactly one event per logical submission.**
  For a questionnaire that means exactly one event, **when it is finally submitted**: moving
  between pages, saving a session or abandoning the flow emits nothing, and a replay of the final
  step emits nothing. A rolled-back attempt emits nothing and leaves the token usable.
* **Contact/Location behaviour:** the Location is the submission's; the Contact (if any) is at that
  Location; `contactResolution` says whether the person is new, known or could not be chosen.
* **Future `FormSubmitted` trigger seam:** the Automations lane adds a `WorkflowTriggerType` case
  and a listener on `FormSubmissionRecorded`, re-reading rows by id (including the version, whose
  pages/fields explain the values) and deduping on `occurrenceKey`. No change to Forms is needed.
  A Contact created here is not subscribed; an automation must not assume messaging consent.
  There is deliberately no event for "questionnaire started/progressed" — only the final fact.

## 12. Website integration — explicitly deferred

Nothing here mutates `WebsiteForm`, `WebsiteFormSubmission`, the public Website form Blade,
starter generation, or any Website route/controller. When the Website lane has landed, a **small
integration slice** can consume this domain:

1. Add `FormDeploymentSource::Website` (additive; `form_deployments.source` is a string).
2. The Website form partial renders a Forms form by resolving a deployment through
   `FormDeploymentResolver`, embedding `FormOperationToken::issue($deployment, $version)` and the
   same honeypot field, and posts to the public route / `FormSubmissionService::submit()`. Website
   remains a *presentation* consumer; a questionnaire embed carries the same token and `page` key.
3. Existing `website_forms` presets map onto the closed field set (`Text→text`, `Email→email`,
   `Tel→phone`, `Textarea→textarea`, `Date→date`) as one-page forms; a one-off command can create a
   `Form` + version + Website deployment per Website form. Historic `website_form_submissions`
   stay as legacy history.
4. Only then retire the Website-owned submission writer, with approval.

## 13. Deliberately not built

Arbitrary conditional logic / branching, file uploads, payment fields, a visitor-facing Location
picker, updating (as opposed to creating) an Opportunity from a submission, a Contact timeline
source for submissions, plan-page marketing copy (`PlatformFeatureCopy`), and the `FormSubmitted`
workflow trigger. `EloquentContactsRepository::findOrCreateForBooking()` is untouched (Calendar is a
separate, already-reviewed domain) although its `exists()`-then-lock shape resembles the hazard §9
describes.

## 14. Verification

See the delivery report for the exact commands, counts and database. Tests live in
`tests/Feature/Forms/`; the cross-process concurrency suite uses its own
`TestDatabaseSafety`-approved sibling database `ultimatesms_testing_forms_domain` and rebuilds it with
`migrate:fresh` only after `TestDatabaseSafety` approves the name.
