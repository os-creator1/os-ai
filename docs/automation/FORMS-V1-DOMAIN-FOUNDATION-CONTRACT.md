# Forms V1 — domain foundation contract

Branch `agent/forms-v1-domain-foundation`. Manual lane (route 3 of `CLAUDE.md`);
scope comes from the lane's own task. This slice builds the **standalone** Forms /
Questionnaires domain and customer surface. It deliberately does **not** touch the
Website lane (§11).

Blueprint authority: `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §5 (definitions
Business-wide, submissions Location-bound), §10 (Contacts are Location-local) and
§16 (Forms and Questionnaires).

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
8. No central Automations file is edited. A durable event seam is exposed (§8, §10).

`agent/forms-lead-capture-v1-completion` is **not** continued and must not be merged:
it makes Forms a WebsiteGeneration capability. Only its submission/idempotency/event
*test ideas* were reused.

## 2. Schema (four tables, one migration each, all justified in their own docblocks)

| Table | Purpose | Key invariants |
|---|---|---|
| `forms` | The Business-wide definition: identity, owner, name, lifecycle, `current_version` | `business_id` RESTRICT; `lifecycle_state` and `current_version` are not mass-assignable — only `FormManager` writes them |
| `form_versions` | The **immutable** statement of what a form asked (fields, submit label, success message, CRM behaviour) | unique `(form_id, version)`; the model refuses update/delete; no `updated_at`; `form_id` RESTRICT |
| `form_deployments` | "This form, at this Location, from this source" — the deterministic Location evidence | unique `(form_id, business_location_id, source)`; public `uid` is a real UUID; both FKs RESTRICT |
| `form_submissions` | One logical submission, an immutable fact | unique `(form_deployment_id, operation_nonce)` (idempotency) and unique `occurrence_key`; `contact_id`/`crm_opportunity_id` SET NULL; every other FK RESTRICT |

Plus `2026_10_14_120005_backfill_forms_customer_permission` (existing customers get the
new `forms` capability — same mechanical necessity as the Catalog's backfill).
Migration prefix `2026_10_14_1200xx`, distinct from the Website (`…1000xx`) and Business
Email (`…1100xx`) lanes.

**No plan-packaging migration is needed.** `forms` is already packaged for Core, Growth
and Agency by `2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`, and the
usage-classification row exists for every `PlatformFeature` case. The Planned → Available
flip in `PlatformFeatureRegistry` is the only entitlement change.

### Field types (closed, bounded)

`text`, `textarea`, `email`, `phone`, `select`, `checkbox`, `date`. At most 25 fields, one
`phone` field (the Contact identity key), one "contact name" field (text only), 2–20
options per `select`. Not supported on purpose: file upload, conditional logic,
repeating groups, payment fields, multi-page flows.

## 3. Definition, versioning, lifecycle (`FormManager`, `FormDefinitionNormalizer`)

* `create()` writes a **draft** and version 1. `update()` writes a new immutable version
  **only when the content hash changes**; renaming whitespace or reordering keys writes
  nothing. `forms.current_version` points new submissions at it.
* An edit through the UI round-trips each question's stable `key` (a hidden input), so a
  relabelled question keeps its key and old answers stay attached to it. An API caller
  that omits keys gets keys re-derived from labels.
* Lifecycle `draft → active ⇄ inactive`. Only `active` accepts submissions. Activate /
  deactivate are idempotent; a draft cannot be "switched off".
* Every method re-loads the form **fresh, under `lockForUpdate`, by primary key** and
  compares its own persisted `business_id` to the Business the caller claims — a caller's
  model (even with a forged in-memory `business_id`) is never trusted.
* `create_opportunity` requires a phone field (otherwise it could never fire). The optional
  `opportunity_pipeline_id` must be an active pipeline of the Business; null means the
  Business's first active pipeline.

## 4. Location deployment

`FormManager::setDeployment()` offers/stops offering a form at one Location from one
source. The Location is **re-derived from persistence by id** and must belong to the
form's Business (foreign Location → refused, even when the caller's in-memory
`business_id` is forged). A *new* deployment needs an Active Location; an existing one can
always be switched off.

`FormDeploymentResolver` is the **one** place a public submission's Location is decided,
and mirrors `PublicBookingController::resolve()`:

* The Location is the one the **deployment** carries. Nothing else is ever consulted — not
  IP, not "the first Location", not session state, not a Contact.
* A visitor-posted `location_uid` may only **restate** it; anything else is refused with a
  validation error, never silently ignored.
* Every row is re-read and mutually proven (form ∈ Business, Location ∈ same Business,
  version ∈ form); then Business Active, Workspace active, account not locked, and the
  **`forms`** entitlement (not `website_generation`).
* Every refusal raises one `FormUnavailableException` → one **404** on the public link
  (unknown link, switched-off form/Location, archived or foreign Location, locked or
  unentitled account are indistinguishable to a visitor).

V1 offers a form **per Location link**. A visitor-facing Location *picker* (one link, the
visitor chooses) is deliberately not built; it would be a thin layer choosing among a
form's deployments and handing the chosen deployment uid to the same service.

## 5. The submission (`FormSubmissionService`)

1. **Authority** — resolver (§4).
2. **Operation token** — one is issued per rendered form (`FormOperationToken`):
   `<nonce>.<hmac(deployment uid, nonce)>`, stateless but unforgeable and bound to its
   deployment.
3. **Validation** against the form's *current version*; produces normalized values keyed
   by field key (phone → digits, email → lowercase, checkbox → bool, blank → null).
   Unknown posted keys are ignored and never stored. A refusal writes nothing and does
   **not** consume the token.
4. **Replay** — a row already claiming `(deployment, nonce)` is returned as-is
   (`replayed = true`): no second row, Contact, Opportunity or event. The same token with a
   **different** body is refused rather than answered with the first body.
5. **One transaction** (retried on deadlock, see §6): INSERT the submission first as the
   **idempotency claim**; resolve the Contact; create the Opportunity if configured; link
   both onto the submission; dispatch `FormSubmissionRecorded`.

Anything that throws inside the transaction (a blacklisted phone, a DB error) rolls the
claim back with it: no submission, Contact, Opportunity or event, and the token stays
usable.

**Idempotency semantics.** One rendered form = one logical submission. Double-click,
retry, two tabs of one render and concurrent requests carry the same token and converge.
Loading the form twice gives two tokens, so two genuine submissions with identical text
stay **separate**. The database unique index is the real backstop; the PHP pre-check is an
optimisation.

**Immutability.** `FormSubmission` refuses every Eloquent update/delete. The only
post-insert write is the in-transaction link step (a query-builder update of three
columns); it is never visible outside the transaction.

**An inquiry is never messaging consent.** A Contact created here is `UNSUBSCRIBE` and
fires no contact-created automation (same rule the Website form follows).

**Lead over CRM.** If the configured pipeline was archived/removed (or has no active
stage), the submission and its Contact are still recorded and the Opportunity is skipped.

## 6. Contact resolution and concurrency

`EloquentContactsRepository::findOrCreateForForm(BusinessLocation, phone, fields)` —
identity is **Business + Location + phone** (Blueprint §10); the Business is taken from the
authoritative Location row.

| Situation | Outcome (`FormContactResolution`) |
|---|---|
| no Contact at this Location with the phone | `created` (not subscribed, no automation) |
| exactly one | `matched` — linked, **never modified** |
| more than one (legacy duplicates) | `ambiguous` — **none chosen, none created**; submission kept with no Contact; no Opportunity |
| no phone in the submission | `none` |

Never merged across Locations or Businesses; a Contact with no Location is not matched.

**Durable concurrency boundary.** The same `booking_contact_identity_locks`
(Location + phone) row the booking seam uses, so a form racing a booking for one new person
also converges. Two lessons from the cross-process race tests are built in and documented
in the code:

* **Lock before the first snapshot read.** InnoDB fixes a transaction's REPEATABLE READ
  snapshot at its first plain `SELECT`. An `exists()` pre-check *before* the lock left the
  winner's freshly committed Contact invisible to the loser, which then created a second
  Contact (reproduced; fixed). The identity row is therefore acquired with a *locking*
  read first, so the match query runs after the lock is held.
* **Deadlock retry.** Two requests queued on one duplicate-key INSERT whose holder rolls
  back is a textbook InnoDB deadlock (SQLSTATE 40001). The claiming transaction is retried
  (3 attempts, Laravel-native for an outermost transaction); the retry meets the winner's
  committed claim and converges as a replay.

## 7. Opportunity (`CrmOpportunityService::createAtLocation`)

An **ids-only** method (`businessId, locationId, pipelineId, contactId, …`) so a caller's
in-memory model — whose `business_id`/`location_id` could be anything — is never an input.
It re-reads every row from persistence: the Location ∈ Business; the Contact ∈ Business
**and located at that exact Location** (a Contact with no Location cannot prove one); the
pipeline ∈ Business; an optional stage ∈ that pipeline ∈ Business. The authoritative
Location row supplies `location_id`. `create()` keeps its exact behaviour (single-Active-
Location rule) and now shares a private `persist()` tail with the new method. One
Opportunity per logical submission follows from the idempotency claim; source is
`CrmOpportunity::SOURCE_FORM` (`form`).

## 8. Event — the durable automation seam

`App\Events\Forms\FormSubmissionRecorded` (`ShouldDispatchAfterCommit`), **ids only**:

| Field | Meaning |
|---|---|
| `businessId`, `locationId` | from the persisted submission, never inferred |
| `formId`, `formVersionId` | the definition and the exact version answered |
| `submissionId` | the submission |
| `contactId`, `opportunityId` | nullable |
| `contactResolution` | `created` \| `matched` \| `ambiguous` \| `none` |
| `occurrenceKey` | `form_submission:<submission uid>` |

Exactly one per logical submission; none for failed, rolled-back or replayed submissions
(proven, including across two OS processes). It is deliberately **not** a
`WorkflowTriggerType`; registering that is the Automations lane's job.

## 9. Customer surface, authorization and navigation

Routes `customer.workspaces.businesses.forms.*` under
`/workspaces/{workspaceUid}/businesses/{businessUid}/forms`: `index`, `create`, `store`,
`edit`, `update`, `activate`, `deactivate`, `locations.set`, `submissions.index`,
`submissions.show`. Public: `public.forms.show|submit|thanks` at `/forms/{deploymentUid}`
(POST throttled 10/min; honeypot answered like a success and stores nothing; every refusal
a 404).

`AuthorizesFormsRequests` runs the chain in order, none substituting for another:

1. tenancy (404) → 2. **`forms` capability** (401, how this app renders a failed
`authorize()`) → 3. **`PlatformFeature::Forms`** entitlement (404) → 4. Location-scoped
reads/actions: the Location ∈ this Business, then `LocationAccessGuard` (404).

`forms` is a new, single customer capability (`config/customer-permissions.php`, default
true, backfilled). It is **not** satisfied by `website`, and `website` is not satisfied by
it. Forms appears in `CustomerMenuBuilder` as its own entry (and in
`ENTITLEMENT_GATED_FEATURES`, without which the snapshot would hide it for everyone).
`PlatformFeature::Forms` flips to **Available** in this slice because the full standalone
module (domain, UI, public link, Location ACL) is delivered and tested.

**Location ACL.** Definition management follows Business tenancy + capability.
Submission visibility follows the operational Location through
`LocationAccessGuard::accessibleLocationIdsForBusiness()` — the one ACL algorithm. A
Location-limited member sees only their Locations' rows (no counts or totals of others), a
`location` filter naming an unreachable Location is a **404** (not ignored), a submission
of an unreachable Location is the same 404 as an unknown uid, and deploying a form is
limited to Locations the actor can reach. Agency View As is the existing authorization:
all routes are `BusinessScoped`, reach only the viewed Business, and sibling clients 404.

**Query bounds.** A page is 25 rows; the list costs one count, one page query and three
constant eager loads regardless of volume.

## 10. Automation readiness

* **Canonical submission event:** §8.
* **Stable occurrence identity:** `occurrence_key = form_submission:<uid>`, a function of
  the persisted row alone and unique in the database. Any redelivery composes the same
  key; a consumer dedupes on it.
* **Contact/Location behaviour:** the Location is the submission's, the Contact (if any) is
  at that Location, the Opportunity (if any) is at that Location. `contactResolution`
  tells a consumer whether the person is new, known, or could not be safely chosen.
* **Replay behaviour:** a replayed token returns the original row and emits nothing; a
  rolled-back attempt emits nothing and leaves the token usable.
* **Future `FormSubmitted` trigger seam:** the Automations lane adds a
  `WorkflowTriggerType` case and a listener on `FormSubmissionRecorded`, re-reading rows by
  id and deduping on `occurrenceKey`. No change to Forms is required. Because a Contact
  created here is not subscribed, an automation must not assume messaging consent.

## 11. Website integration — explicitly deferred

Nothing here mutates `WebsiteForm`, `WebsiteFormSubmission`, the public Website form Blade,
starter generation, or any Website route/controller. When the Website lane has landed, a
**small integration slice** can consume this domain:

1. Add `FormDeploymentSource::Website` (additive; `form_deployments.source` is a string,
   no migration) and, if a per-page identity is needed, a nullable reference column.
2. The Website form partial renders a Forms form by resolving a deployment through
   `FormDeploymentResolver`, embedding `FormOperationToken::issue()` and the same honeypot
   field, and posts to `FormSubmissionService::submit()` (or the public route). Website
   remains a *presentation* consumer.
3. Existing `website_forms` presets map onto the closed field set (`Text→text`,
   `Email→email`, `Tel→phone`, `Textarea→textarea`, `Date→date`); a one-off command can
   create a `Form` + version + Website deployment per Website form at the Business's
   Location(s). Historic `website_form_submissions` stay as legacy history.
4. Only then retire the Website-owned submission writer, with approval.

## 12. Deliberately not built

Multi-page questionnaires (Blueprint §16), updating (as opposed to creating) an
Opportunity from a submission, a visitor-facing Location picker, a Contact timeline
source for submissions, plan-page marketing copy (`PlatformFeatureCopy` — Calendar and
Catalog are likewise absent), file uploads, and the `FormSubmitted` workflow trigger.

## 13. Verification

See the delivery report for the exact commands, counts and database. Tests live in
`tests/Feature/Forms/`; the cross-process concurrency suite uses its own
`TestDatabaseSafety`-approved sibling database `ultimatesms_testing_forms_domain` and
rebuilds it with `migrate:fresh` only after `TestDatabaseSafety` approves the name.
