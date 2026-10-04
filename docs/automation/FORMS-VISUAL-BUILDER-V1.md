# Forms visual builder — V1

Branch `agent/forms-visual-builder-v1`, stacked on the Custom Fields lane (`29920dc2`, "Business Custom
Fields and one canonical merge-field system"). Manual lane; scope comes from its own task. This slice
replaces the long admin-style Forms editor with a visual builder. It **does not** change the Forms
submission, versioning, deployment or Location architecture
(`FORMS-V1-DOMAIN-FOUNDATION-CONTRACT.md` stays authoritative) and it **does not** create a second
field-definition store (`docs/product/implementation-contracts/23-CUSTOM-FIELDS-MERGE-FIELDS-V1.md`).

## 1. What is reused, what is new

| Concern | Reused unchanged | Added |
|---|---|---|
| Definition / version / immutability | `Form`, `FormVersion` (still refuses update/delete), `FormManager` (the only writer), content-hash versioning | `FormManager::update(..., $expectedVersion)` — optional stale-version guard under the existing row lock |
| Submission | `FormSubmissionService`, `FormOperationToken` version pinning, idempotency, questionnaire sessions, `FormSubmissionRecorded` | validation/normalization for the new element types; explicit first/last-name Contact markers |
| Custom fields | `CustomFieldDefinitionManager`, `FormFieldMapping` (`custom_field_uid`), `CustomFieldValueService::applyAnswer` | compatibility entries for the new element types |
| Location | `FormDeployment`, `FormDeploymentResolver`, `LocationAccessGuard`, `FormSubmissionReader` | none — the builder never creates a Form per Location |
| Notifications | Automations trigger `form_submitted` + "Notify the team" step | a page that explains this honestly |
| Rendering | — | one shared set of public partials (`public/forms/_style`, `_card`, `_fields`) used by the public page, the thank-you page **and** the builder Preview |

New storage: **one nullable JSON column**, `form_versions.design` (migration
`2026_10_28_090001_add_design_to_form_versions_table`). Justification: how a form looked when answered is
part of what the response means, so the style is versioned with the content (hashed, immutable). It is a small
closed object — `accent`, `background`, `button_align`, `radius`, `width` — never free CSS. `NULL` / absent =
platform default; a form that sets no style hashes **exactly** as before (no `design` key is written).

## 2. Editor shell

`GET …/forms/{formUid}` (`customer.workspaces.businesses.forms.edit`) is now the builder.

* **Top bar:** Back, editable form name, save state (`Saving…` / `Saved` / `Save failed` / `Conflict — stale tab`),
  Preview, Integrate. **Tabs:** Edit · Settings · Submissions · Notifications · Analytics.
* **Edit:** left toolbox · centre live canvas · right inspector. Desktop = 3 panes; ≤1100 px the toolbox and
  inspector become drawers (toggle buttons above the canvas); the public form is fully responsive.
* **Settings** (client-side pane of the same page, same autosaved document): name, introduction, status
  (activate/switch off), confirmation message, form style (button label, accent, button alignment, corner
  radius, width, page background), opportunity creation + pipeline, and *Where it is offered* (per-Location
  deployments — the existing routes).
* The classic POST `store`/`update` endpoints remain (same contract, same tests) but no UI posts to `update`
  any more. The old `_editor.blade.php` (page-slot table, one giant row table) is deleted.

State flows as one JSON document (`#fb-state`) produced by `FormBuilderState` — an **adapter over the stored
version** (no migration, no second model). Every existing form opens as it is; absent optional keys mean
"not set". Saving writes the next version only when the content hash changes.

## 3. Elements

`FormFieldType` is still one closed enum; it grew. Three kinds share the ordered `fields` list of a version:

* **Inputs:** short text, long text, email, phone, number, currency (≥0, 2 dp), date, date & time, dropdown,
  multi-select, radio buttons, checkbox, yes/no.
* **Consent:** `consent_transactional`, `consent_marketing` — separate types, at most one of each per form,
  never pre-checked, configurable wording (the `label`), optional/required, stored as a boolean in the
  submission, wording versioned with the `FormVersion`. Recording consent does **not** subscribe the Contact
  (an inquiry is never messaging consent — Forms contract §8); wiring consent to Contact subscription state is
  deferred. The inspector says plainly that the owner is responsible for compliant wording.
* **Content:** heading, paragraph, divider, spacer. No answer, never validated, never stored in a submission.

Optional per-element keys, written **only when set** (so unchanged forms hash as before): `placeholder`,
`help`, `width: half`, `default`, `contact_part`, `custom_field_uid`. Defaults are refused where unsafe: never
for consent/checkbox, never for dates, a choice default must be one of its own options.

Bounds (normalizer): 8 pages, 25 inputs per page, 40 inputs total, 30 content blocks, ≤20 options. Content
blocks don't count toward the question limits; at least one input is required.

**Not built:** file upload (needs a storage / scanning / retention design), conditional logic, repeating groups.

## 4. Pages / questionnaires

An ordinary form is one page and shows no page chrome. **+ Add page** makes it a questionnaire; each page card
has an inline title, up/down and remove (remove is enabled only when the page has no elements — "safe"). Elements
move between pages by drag or `Alt+↑/↓` (continues across a page boundary). Keys of pages and elements are stable:
reordering is an edit of position, so the versioning rules in Forms contract §3–§4 apply unchanged. A page nobody
uses is dropped on save, exactly as before.

## 5. Contact and Custom Field mapping

* **Built-ins are explicit markers, never inferred from labels:** Full name = a short-text with `contact_name`;
  First / Last name = short-text with `contact_part: first_name|last_name` (a form uses either a full name or
  parts, not both); Email and Phone are their types (the first email question and the one phone question
  identify the person). No custom definitions are created for them.
* **Custom Fields:** the toolbox's *Custom fields* group lists the Business's **active** canonical definitions
  only. Dropping one creates the input type that holds its value (date→date, number→number, select→dropdown with
  the definition's option labels, …), the definition's label, and the explicit `custom_field_uid`. Email and
  Phone custom fields become short-text inputs on purpose, so a mapped field can never become the form's
  identity. The inspector's *Save answer to* picker offers Contact name parts (short text only) and the
  type-compatible active custom fields (the server's own `FormFieldMapping` table, handed to the browser).
* **Archived** custom fields are never offered for new insertion or mapping and are refused by the server for a
  new mapping; a version that already maps one keeps rendering, opens in the builder labelled *archived*,
  re-saves, and the submission simply stops writing it (`CustomFieldValueService::applyAnswer`).
* **Update semantics** are the agreed ones, untouched: an explicitly mapped answer may update an
  unambiguously matched Contact (only that field; blank/invalid never overwrites; ambiguous = no write).

## 6. Autosave, versioning, conflicts

The browser edits a local copy of the document (optimistic DOM updates, stable element keys, no reload) and
posts the **whole document** to `POST …/forms/{formUid}/builder` after a 1.5 s debounce, one request in flight
at a time (a later edit queues exactly one follow-up; an older response never overwrites newer state).

* The request names `base_version`. `FormManager::update()` compares it to `current_version` **under the
  `forms` row lock** and throws `FormStaleVersionException` → HTTP **409**, nothing written. The tab shows
  *Conflict — stale tab* and a reload button and stops saving.
* A rule refusal (e.g. a dropdown with <2 options) is HTTP 422 with the manager's own wording, shown in the
  banner; the document stays dirty and retries on the next edit.
* **Known trade-off:** every autosave that changes content is a new immutable `FormVersion` (the model forbids
  updates). A burst of edits separated by pauses can write several versions. Versions are small JSON and never
  referenced by submissions that didn't answer against them, but a draft buffer (autosave to a mutable draft,
  publish on demand) is the right follow-up if version noise matters — it is **not** built here because it
  would add a second definition store. Reordering/relabelling never changes a stored key.
* The Settings status buttons and Offer-here toggles flush a pending autosave before navigating.

## 7. Preview

`POST …/forms/{formUid}/builder/preview` normalizes the **unsaved** document with the same
`FormDefinitionNormalizer` and renders it through `public.forms._style` / `_card` / `_fields` — the same partials
the public page uses (an unsaved `FormVersion` instance; nothing is written, no token or deployment exists).
A questionnaire previews as one card per step. A test asserts the preview's field markup is byte-identical to
the public page's. The canvas itself is drawn client-side with the same `pf-*` classes and the same tokens.

## 8. Integrate

A dialog (also `#integrate`): per enabled Location deployment, the public link with *Copy link* / *Open* and an
`<iframe>` embed snippet with *Copy embed*; a notice when the form isn't active; a note that the public route
sends no framing restriction from the application (a reverse proxy may still add one). **Add to Website is not
offered** — Forms contract §12 defers the Website integration, and the Website still has its own form
implementation; no second one was created.

## 9. Submissions, Notifications, Analytics

* **Submissions** = the existing list filtered by form, now inside the builder shell, with date/time, Contact
  (explicit name + phone), Location, key answers (first three non-boolean answers, labelled from the **version
  answered against**) and status (contact created/matched/needs review/no contact, opportunity). Detail reads
  only the submission's own version; consent shows *Agreed / Did not agree*; content blocks are skipped.
* **Notifications:** there is no per-form notification layer. The tab states that and points to Automations
  (`form_submitted` trigger, optionally filtered to this form, + "Notify the team"), and lists what doesn't exist
  (a built-in "email me" switch, a visitor confirmation email).
* **Analytics** shows only measured numbers: completed responses (all time / 30 days), tied to a Contact,
  became an Opportunity, per Location, per day, and for questionnaires started/finished. Bounded to the
  Locations the actor may see. **Missing instrumentation (documented, not faked):** page views, and starts for a
  one-page form — nothing records them.

## 10. Payments / Catalog

**Deferred, no field shipped.** Collecting payment or selling a product inside a form needs a transaction
architecture the Forms domain doesn't have: a submission is an immutable, idempotent fact created in one
transaction with no external side effects, whereas payment needs an Invoice/Checkout hand-off, price
snapshots, failure/refund states, and a decision on what an unpaid submission is. The canonical seams exist
(`business_documents` kind=invoice, Catalog UIDs) but nothing in them is consumable from a public Forms POST
without that design. The toolbox therefore has no Payments group; a fake field was not shipped.

## 11. Tests

`tests/Feature/Forms/FormBuilderTest.php` (focused coverage of every item in the brief) plus the unchanged
Forms, Custom Fields and Automations suites; `FormsHttpTest` inventory extended with the four new routes
(authorization matrix: stranger 404, no capability 401, no entitlement 404, View-As business-scoped).

## 12. Deferred / not done

File upload; payments; redirect-after-submit URL (not supported by the domain); Add-to-Website; draft buffer
for autosave; applying consent to Contact subscription state; owner "email me every response"; page views /
one-page starts instrumentation; undo/redo; per-element conditional visibility.
