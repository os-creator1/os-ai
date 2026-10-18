# 17B — Proposal / Contract Visual Editor V1

Extends contract 17 / 17A. Nothing here replaces the existing document
lifecycle: **Catalog → document draft line → frozen issued version →
canonical payment schedule → Stripe execution.** The editor *configures* that
chain; it never owns financial truth.

## 1. Scope decisions (approved)

| Topic | Decision |
|---|---|
| Document kind | No new `DocumentKind`. A "Contract" is a `proposal` document; the *template* carries `template_type` = `proposal` \| `contract`. Invoices are not edited in this builder. |
| Signing fields | **Signature block only.** Text / date / checkbox fields are deferred: the signing engine has one signer per document, typed-name signing, no field-placement model and no recipient roles. |
| Templates | One table `document_templates`; `business_id` NULL = platform-owned canonical template, otherwise a Business-private template. |
| Niche link | Niche blueprint component `document_template` references the platform template UID. **Nothing is copied at blueprint install.** The Business sees it as "Recommended"; "Use template" creates a Business-owned *document draft*. "Save as template" is the only way a Business template is created. |
| Images | No generic Business media seam exists (website assets are website-bound, `Business` has no logo column). Image blocks reference the Business's own `CatalogItemImage` rows only. Free upload and a logo block are **deferred gaps**. |
| Payment execution | V1 provider = Stripe (existing Connect, in-page Stripe Payment Element on the public page). Post-V1 candidate: PayPal. The document stores amount, due timing, schedule and state only — no Stripe concepts. No PayPal UI. |
| Columns / text-date-checkbox fields | Deferred. |

## 2. Block document schema (`content.blocks`, `content.schema_version = 2`)

Stored in `business_document_versions.content` (and `document_templates.blocks`).
Legacy versions (no `content.blocks`, `schema_version` 1 with `content.body`)
render and edit through the legacy path forever. Sent/signed/void versions are
never rewritten.

```
{ "schema_version": 2,
  "blocks": [ {"id":"<uuid>","type":"heading","data":{...}}, ... ] }
```

Types and data (anything else is rejected; unknown keys are dropped):

- `heading` `{level:1-3, align, runs}` · `text` `{align, runs}`
  - `runs`: list of `{t:string≤2000, b?,i?,u?, href?, merge?:token}`. `href` only `http(s)`, `mailto`, `tel`. A run with `merge` renders a merge token (see §4).
- `image` `{catalog_image_uid, alt, width_pct}` (Business-owned catalog image only)
- `divider` · `spacer {height: 8..120}` · `page_break` · `section {title}`
- `business_details` `{show:[name,phone,email,website]}`
- `product_list` `{show_description, show_quantity}` — **one per document**. Holds *presentation only*. Lines/prices/schedule live in the canonical tables.
- `payment_terms` `{}` — presentation of the canonical schedule.
- `signature` `{label}` — marks where the signing panel renders. Sendable proposals that `requires_signature` must contain exactly one.

Limits: ≤200 blocks, ≤200 KB JSON, ids unique, depth 1 (no nesting). Pure data,
never executable; all output is escaped by the renderer.

`BlockSchema` (validate + sanitise) is the only writer-side authority.
`DocumentBlockRenderer` is the only renderer: editor canvas, preview, Business
show and public page all use the same Blade partial `documents.blocks.render`
with a `mode` of `editor | preview | public | template_preview`.

## 3. Canonical commerce chain

- Product block → `DocumentManager::addCatalogLine` / `addCustomLine` /
  `removeLine` / `reorderLines` / quantity update. Catalog snapshot is taken
  when the line is added (existing behaviour). Issued versions are frozen by
  the existing content hash.
- Payment structure is stored as **intent** in `content.payment_plan`:
  `{structure: full|deposit, deposit_minor?, full_due: on_signing|date, full_due_date?, balance_due: after_deposit|date, balance_due_date?}`.
  `DocumentPaymentPlanCompiler` compiles intent → `DocumentManager::setSchedule`
  terms (`due_at` NULL = "after signing / after deposit"; a date = end of that
  day in the Business timezone). It re-runs after any line change (because
  `recalculate()` clears the schedule). Balance is always `total − deposit`;
  deposit must satisfy `0 < deposit < total` or the plan is rejected/cleared.
- UI shows formatted Business currency; "minor units" never appears.
- **Known semantic (unchanged):** `due_at` does not gate payability; the
  balance is payable after the deposit. `due_at` is displayed, drives the
  existing reminder sweep (which sends no link) and drives the automatic
  balance payment request (§7, "Automatic balance payment request").

## 4. Merge fields

Allow-listed tokens only, stored as `merge` runs (`contact.first_name`,
`contact.full_name`, `contact.email`, `business.name`, `business.phone`,
`business.email`, `business.website`, `document.title`). No expressions.
Authority: `DocumentMergeFields`. Tokens stay as tokens in drafts/templates.
Preview resolves live from the document's recipient/Business. **At send**,
resolved values freeze into `content.parties` (hashed with the version); public
rendering of an issued version uses only the frozen values.

## 5. Draft persistence and concurrency

`business_document_versions.lock_version` (default 1). Every draft mutation —
blocks, title, recipient snapshot, line ops, schedule/payment plan — requires the
caller's `expected_lock_version`, performs a conditional update inside the
existing row-lock transaction and returns the new value. Mismatch →
`DocumentDraftConflictException` → HTTP 409 with the current version. Autosave
(debounced, JSON) is draft-only; sent/signed/void are refused by the existing
`draft()` guard. Templates have their own `lock_version`.

## 6. Templates

`document_templates`: `uid, business_id?, template_type, name, description,
blocks (json), schema_version, status (draft|active|archived), lock_version,
created_by_user_id`.

- **Save as template** (Business): copies layout/styling/text/headings/Business
  images/merge tokens/signature position. `product_list` is stored as a generic
  placeholder; `content.payment_plan`, lines, prices, deposit, dates, contact,
  recipient, send and signature state are never stored.
- **Use template**: always creates a Business-owned document draft for an
  already-chosen Contact; the product area is a placeholder until the user adds
  a product. Sources allowed: own template, or an *active platform template the
  Business is recommended via its blueprint*. Anything else fails closed (404).
- Platform templates: no Business catalog item, price or Contact. Image blocks are
  not permitted in platform templates in V1 (no platform media seam).
- Business actions: create from blank / template, duplicate, edit, archive,
  delete (only if never used is not tracked → archive only), save document as template.
- Platform Owner: `/admin/document-templates` (create, edit in the same editor,
  label type, enable/disable, preview, assign to niches).
  Authority: `PlatformOwnerAuthority`; UUID route binding with `->missing()`.
- **Niche assignment** = a `document_template` component (`{template_uid}`) on a
  niche blueprint version, written only through `NicheBlueprintPublisher`.
  Recommended = active platform templates referenced by the *published*
  blueprint version of the Business's niche. Blueprint install does not copy.

### 6a. Business templates (stage 5 — implemented)

**Rule: save the layout, not the product or contact.** A template is built only
from `content.blocks` (never `payment_plan`, `parties`, lines, recipient, send or
signature state) and BlockSchema reduces `product_list` to its two presentation
flags, so none of that can reach `document_templates`. `payment_terms` is kept as a
presentation-only block. Merge tokens stay tokens; images are kept only when they
are the Business's own catalog images (an image the Business no longer owns is left
out, never trusted).

- `App\Library\Documents\Templates\DocumentTemplateService`: `saveFromDocument`
  (open draft blocks, or the issued version's for sent / signed — read-only; legacy /
  non-block documents refused), `createBlank`, `update` (conditional on
  `document_templates.lock_version` → `DocumentTemplateConflictException`; `$scope`
  Business = its own template, `null` = platform-owned only), `duplicate` ("Copy of …",
  Business templates only), `archive` / `restore`, `instantiate` (drafts only; copies
  blocks through `DocumentManager::saveBlocks` with the draft's `lock_version`;
  re-validates; drops images the draft's Business does not own; never copies a plan;
  the template is only read and the document keeps no reference to it).
- `DocumentTemplateAccess`: own templates by `business_id`; a platform template is
  readable / usable only if `RecommendedPlatformTemplates::forBusiness()` contains it
  (empty in stage 5; the niche-blueprint implementation is §6b). Foreign,
  forged, unrecommended and unknown uids are the same 404; an own archived template
  is refused with a message.
- Routes (`customer.workspaces.businesses.document-templates.*`, same gate chain as
  documents): `index` GET `/`, `create` POST `/` (blank → editor), `edit` GET
  `{uid}/editor`, `preview` GET `{uid}/preview`, `blocks` PUT `{uid}/blocks`
  (`blocks`, `name`, `template_type`, `description`, `expected_lock_version`;
  200/409/422/404 like the document API), `duplicate` / `archive` / `restore` POST.
  Document side: `documents.editor.save-template` POST (`name`, `template_type`,
  `description`) and `documents.store` with `via=editor` accepts `template_uid`
  (inside one transaction; a template that cannot be applied leaves no document).
- UI: the ONE editor bundle opens templates with bootstrap `mode: 'template'`
  (`platform_template` is §6b): header = back · name · type ·
  status chip · save indicator · Preview · Save; no Send / Save as template / contact;
  Add product inserts the generic product placeholder (no wizard, no catalog / line /
  plan / contact-date calls); merge chips resolve to sample data. New proposal step 2:
  Start blank · My templates · Recommended (hidden while empty).
- Also fixed in this stage: the global `TrimStrings` middleware trimmed the spaces
  around merge chips in saved text runs ("for " + chip became "forAlex");
  `blocks.*.data.runs.*.t` is now excluded.
### 6b. Platform templates and niche recommendation (stages 6-7 — implemented)

**A platform template is NEVER copied into a Business.** It is a `document_templates` row with `business_id` NULL, managed only by the Platform Owner. The niche blueprint *references* it; a Business in that niche sees it under "Recommended for your business"; "Use template" creates a Business-owned document DRAFT and leaves the platform template untouched (the document keeps no reference to it). "Save as template" remains the only way a Business-private template exists.

- **Content rules.** Layout, explanatory copy, merge tokens, business details, a generic `product_list` placeholder, a `payment_terms` placeholder and the signature position only. No Business product, price, Contact, payment amount or date, and no image blocks (`BlockSchema` `allow_images=false`; no platform media seam in V1), enforced on save, on publish and again when a Business uses the template.
- **Niche link = a blueprint component, not a new configuration system.** Component type `document_template`, payload `{template_uid, label?}`, entitlement `payments_contracts`, adapter `DocumentTemplateComponentAdapter` (reference-only install; see contract 20 "Registered adapters"). The installer records the platform template as provenance; recommendations never depend on an installation row, so existing Businesses are covered too.
- **`RecommendedPlatformTemplates::forBusiness()`** (the stage-5 seam, now real; API unchanged): Business, then the SAME blueprint resolution the installer uses (`NicheBlueprintInstaller::resolveBlueprint()`: knowledge-profile vertical, else the single active broad-industry blueprint), then the **published** version's `document_template` components, then platform templates with `status = active` and `business_id` NULL, and only if `PaymentsContracts` is allowed for the Business. A draft version, a superseded version, an inactive blueprint, an unrelated niche, a draft / disabled template and a Business without the entitlement all yield nothing. Cost: resolution (1-3 reads) + 1 component read + 1 template read + the per-request-cached entitlement snapshot, independent of the number of templates. `DocumentTemplateAccess` still consults it for every read / use, so a forged or un-recommended uid is a 404.
- **Admin surface** `/{admin}/document-templates` (`admin.document-templates.*`, inside the `EnsureUserIsAdministrator` group; `{template}` binds by uid and an unresolved uid goes through `PlatformOwnerAuthority::refuseMissingTarget`; a Business-owned uid is a 404): `index` GET `/`, `create` GET `create`, `store` POST `/`, `edit` GET `{t}/editor`, `blocks` PUT `{t}/blocks` (same 200 / 409 / 422 contract as the Business template endpoint, `expected_lock_version` required), `preview` GET `{t}/preview`, `publish` POST `{t}/publish` (draft or disabled to published; needs at least one block and a clean platform `BlockSchema` pass), `disable` POST `{t}/disable` (published to disabled, stored as `archived`), `niches` GET / PUT `{t}/niches`, `niches.publish` POST `{t}/niches/publish`. The editor is the ONE bundle in bootstrap `mode: 'platform_template'` (no contact chip, no catalog / lines / plan / send, no Image tool, merge chips on sample data, header: back, name, type, status chip, save indicator, Preview, Save, Assign to niches, Publish / Unpublish; Assign and Publish flush unsaved edits first). Business owners have no route that can write a platform template (`update()` takes a NULL scope, meaning platform-only; the Business scope is refused for platform rows).
- **Assignment flow (two explicit steps, because published versions are immutable).** (1) *Save to blueprint drafts* (`niches.update`): the complete checked set of niches is recorded on each blueprint's single DRAFT version through `NicheBlueprintPublisher` (`createDraftVersion` when none exists, copying every component of the current published version into it so nothing live is dropped; `addDraftComponent` / `removeDraftComponent` for this template's `document_template` component, key `document_template_<uid>`). Nothing is recommended yet. (2) *Publish blueprint version* (`niches.publish`, one niche at a time, confirmation required): `publishVersion` through the publisher's fail-closed gate. A draft authored elsewhere on the Niche Blueprints screen is published whole (the screen says so). One-draft / one-published invariants are the publisher's. **Disabling a template needs no blueprint change**: recommendations filter on the template's own status, so it disappears at once; documents already created keep their own copy.
- **Photo Booth set.** `php artisan documents:seed-photo-booth-templates [--actor=]` creates four platform templates (Photo Booth Proposal, Wedding Photo Booth Proposal, Corporate Event Proposal, Event Agreement as a `contract` with a page break before the signature), keyed by the new nullable-unique `document_templates.seed_key` (the migration exists only so the seed is idempotent and never overwrites a template the owner renamed, edited or disabled), then assigns them to the `photo_booth` blueprint: one new version = the existing pipeline component + four `document_template` components, published; a no-op when already assigned; an interrupted run resumes its own draft; a draft holding anything the seed did not put there is left untouched and the command fails. Requires `blueprint:seed-photo-booth` v1 first (that command is unchanged). The copy is plain and generic, makes no legal claim, and carries the contract 17 §6.5 note that a typed e-signature is a record, not a legal opinion.

## 7. Creation, send, sign → pay

- New document flow: **choose Contact → choose blank / My templates /
  Recommended → editor.** Commercial configuration (product / payment) needs the
  document's Contact (it supplies merge data and the event-date prefill).
- Date prefill (never forced, always editable): (1) the Contact's next scheduled
  `Appointment.start_at`; (2) a date-typed Contact custom field only when exactly
  one exists. No scraping, no guessing; empty if none. The source is labelled.
- Send dialog: Email and/or SMS (≥1). One `DocumentManager::send` transition;
  channels are delivery attempts of that single send. Email uses the existing
  document link email; SMS goes through `CampaignRepository::quickSend` (no
  direct provider calls). Per-channel outcome recorded
  (`link_delivered_at` / `sms_link_delivered_at` and failure markers).
- After a successful signature the recipient sees a primary "Continue to
  payment" CTA to the existing Stripe payment UI when a payment is due now;
  otherwise "Signed successfully" plus the next payment due date.

**As built (stage 3b).** `editor.send` (POST `{doc}/editor/send`, throttled) takes
`channels[]` (email|sms, ≥1), optional `message` (≤320, real link always appended
server-side), `recipient_email` / `recipient_phone` (only used while the snapshot is
empty), `expected_lock_version`, and answers `{status, document_status, sent_at,
lock_version, delivery:{email?,sms?}}` with per-channel `queued|failed` plus a reason
code. `DocumentManager::send` does ONE Draft→Sent transition with ONE token; a
`DocumentLinkDispatcher` hands the plaintext token to each channel after commit
(`SendDocumentLinkEmail`, `SendDocumentLinkSms`). No SMS code lives in
`DocumentManager`. SMS goes only through `BusinessSmsSendingPath` +
`CampaignRepository::checkQuickSendValidation/quickSend` (the path extracted from the
automation SMS action, whose tests are unchanged); an unsendable SMS (contact missing
or unsubscribed, no/invalid phone, no sending path) records
`sms_link_delivery_failed_at` and never blocks the email or the Sent transition.
`send` fills an empty `recipient_name_snapshot` from the Contact so frozen merge
fields are never blank. The block product block lists deposit/balance rows only when
the document has no separate `payment_terms` block (that block then owns them).
The signed page's CTA links to the public page `#pay` section; legacy (non-block)
pages keep their byte-pinned markup, so the CTA reaches the page without the anchor.
Editor routes serve proposals only; an invoice is 404 in the builder.

**Automatic balance payment request (closure pass).** For Deposit + Balance documents:
deposit paid → balance stays scheduled → when the balance item's **due date** (frozen `due_at`)
arrives, the customer is emailed the canonical secure link to pay. Command
`documents:dispatch-balance-requests` (hourly, `withoutOverlapping`, registered
unconditionally next to the other document sweeps; the command owns the
`documents.enabled` no-op and `--limit`, config `documents.balance_request_sweep_limit`)
runs `Delivery\DocumentBalanceRequestDispatcher`.

- *Eligible* (re-verified under the document → version → schedule-item row locks at claim
  time, and again inside the job): schedule item `kind=balance`, `status=pending`,
  `due_at` not null and the due DAY begun (now ≥ the first moment of the due day in the Business timezone — `due_at` itself stays the frozen end-of-day instant); sequence-1 deposit `paid`; no item `refunded`/`void`; the
  item belongs to the document's **current issued** version; document `Signed` (or `Sent`
  when no signature is required) and its offer not lapsed; frozen
  `recipient_email_snapshot` valid; `payment_request_sent_at` null and attempts under the
  cap. Account conditions are `PublicDocumentGuard::assertAccountOperable()` — the exact
  lifecycle / Payments & Contracts entitlement / Location checks a public request runs
  (extracted from `resolve()`, behaviour unchanged). Void, Expired, Paid, Draft and
  Sent-while-a-signature-is-required are never selected. The Contact is not an input: the
  recipient is the frozen snapshot, the amount and date are the frozen schedule row, so
  changing the Contact later changes nothing.
- *Null `due_at`* ("immediately after the deposit"): nothing is scheduled — the balance is
  already payable through the existing link, so there is nothing to request.
- *Claim columns* (migration `2026_10_29_090001`, progress markers on
  `business_document_payment_schedule_items`, no commercial term touched):
  `payment_request_claimed_at`, `payment_request_sent_at`, `payment_request_failed_at`,
  `payment_request_attempts` (unsigned tinyint, default 0).
- *Claim + rotation*: one short transaction; a conditional UPDATE sets `claimed_at` and
  bumps `attempts` only while `sent_at` is null, attempts < `balance_request_max_attempts`
  (3) and no claim younger than `balance_request_lease_minutes` (30) exists, so concurrent
  sweeps cannot both win. The winner rotates the token with the SAME
  `DocumentManager::rotateAccessToken()` `resendLink()` uses (every earlier link dies),
  then queues `SendDocumentBalanceRequestEmail` (`ShouldQueueAfterCommit` +
  `ShouldBeEncrypted`, plaintext token handled like `SendDocumentLinkEmail`).
- *Email*: `DocumentBalanceRequestNotification` — "A payment of {amount} for “{title}” is
  due", business name, due date in the Business timezone, a "Pay now" button to
  `public.documents.show` with the fresh token and the `#pay` anchor; escaped; no card data.
- *Idempotency / retry*: the job re-checks eligibility, requires the token it carries to be
  the CURRENT link (a rotated or stale job sends and records nothing), emails, then
  records `payment_request_sent_at` once (and the link's `link_delivered_at`). After
  success the item is never selected again, so a replayed job or sweep sends nothing. A
  failure records `payment_request_failed_at`, releases the claim and the next sweep
  retries (rotating the link again) until the attempts cap, then stops. A claim whose job
  never reported back is retried after the lease. Honest limit: a worker crash between
  sending the mail and recording it can re-send once after the lease.
- *Not done*: no auto-charge, no Stripe call in the job, no invoice/document created, no
  terms or due dates changed, Payment/Refund finalizers untouched. **SMS is not added**:
  rotation clears `sms_link_delivered_at`, and the SMS path reads the live Contact's
  consent, so it cannot honour "the Contact is not an input" cleanly; email is the V1
  requirement.
- *Timing*: a date-typed `due_at` is the END of that day in the Business timezone (§3) and stays so for
  reminders and display, but the request goes out at the first hourly sweep on or after the START of that day (SQL prefilter `due_at <= now + 26h`, exact check per Business timezone under the row lock and again in the job).

## 8. Immutability

Only drafts (and sent documents after `revise()` that have an open draft
version) are editable, through the existing `draft()` guard. Signature still binds
`displayed_version_uid` and `content_hash`. The hash already covers `content`
(blocks, payment_plan, parties); no hashing change other than the extended
`parties` snapshot.

## 8a. Editor JSON API (stage 3a — implemented)

Routes `customer.workspaces.businesses.documents.editor.*` under the documents
group, same gate chain as `DocumentsController` (`ResolvesBusinessDocuments`).
`DocumentEditorController` is a thin adapter over `DocumentEditorService`; every
write is a `DocumentManager` draft mutation. Every mutating document endpoint
requires `expected_lock_version`; answers are `200 {status:'ok', lock_version,
...}`, `409 {status:'conflict', lock_version}`, `422 {status:'invalid', errors}`,
`404` for any foreign/unknown uid. `DocumentManager` mutations take an optional
`?int $expectedLockVersion` (null = no precondition) and always bump the open
draft's `lock_version`; a hand-written `setSchedule()` supersedes the stored
`content.payment_plan`. `recalculate()` re-applies the plan through
`DocumentPaymentPlanCompiler`; if `deposit >= total` the schedule stays cleared,
the intent is kept and the response carries `plan_invalid`. `send()` refuses a
block document that requires a signature and has no signature block.
Legacy upgrade (`editor.upgrade`) converts a DRAFT legacy body to blocks once and
keeps the original `body`.

## 8b. Editor UI (stage 4 — implemented)

`GET {doc}/editor` renders the shell (`customer.business.documents.editor`); the
vanilla-ES-module bundle `resources/js/documents/editor/` (built to the committed
`public/js/documents/editor.js`, page CSS `public/css/base/pages/documents-editor.css`)
draws everything else from the bootstrap JSON.

- **Layout.** Header (back, editable title, status chip, save indicator, Preview /
  Save / Save as template [enabled in stage 5] / Send / More),
  left toolbox (`DocumentEditorToolbox` is the one definition: Content, Commerce,
  Fields [Signature only], Structure, Business), centre page canvas (structured
  flow, `.doc-blocks` stylesheet shared with the renderer), contextual inspector.
  Under 992px the toolbox is a drawer and the inspector a bottom sheet.
- **Save model.** One promise queue for every mutation; every request reads the
  latest `lock_version`; debounced (~800ms) `PUT editor.blocks`; `beforeunload`
  guard while dirty/saving; 409 → conflict banner + read-only stale copy (never
  retried, never overwritten); 422 → the field message in a banner. An image block
  with no image chosen is kept on the canvas but left out of the save.
- **Inline text.** `contenteditable` with a mini toolbar (bold, italic, underline,
  link [http/https/mailto/tel], alignment, paragraph/H1-H3, Insert merge field);
  `serializer.js` turns the DOM into exactly the BlockSchema run format; paste is
  plain text only.
- **Product flow.** Add product → single `product_list` block + wizard (choose /
  create from the Business catalog → full or deposit+balance → due timing, date
  prefilled from `contact.dates` but never forced). Money is typed as a decimal,
  formatted with `Intl.NumberFormat` and sent as a decimal string; the wizard blocks
  invalid deposits live. After every line change the server re-applies the plan and
  `plan_invalid` / `plan_error` are shown on the block.
- **Preview.** `GET {doc}/editor/preview` renders the saved draft (or the frozen
  issued version) through `DocumentBlockRenderer` in a standalone print-width page;
  a legacy / non-block document redirects to the classic page.
- **New proposal flow.** Documents page → New proposal → choose Contact → blank
  (stage 5 adds My templates / Recommended — see §6a). `documents.store`
  with `via=editor` derives the Location from the Contact, seeds an empty block
  document and redirects into the editor; the classic POST (and invoices) behave as
  before. List rows of block / new drafts link to the editor.
- **Known gaps.** Classic show page still carries pre-17B "minor units" form
  labels (untouched). The canvas is responsive rather than a fixed 794px at narrow
  desktop widths. Native HTML5 drag-and-drop only (touch uses the toolbox click and
  the up/down buttons).

## 9. Deferred / reported gaps

Text, date, checkbox fields; multi-recipient signing; columns; free image upload
and Business logo block (no media seam / no logo column); platform-template images;
hosted Stripe Checkout redirect (existing in-page Payment Element is reused);
SMS for the automatic balance payment request (email only, §7); template thumbnails (a text
snippet is shown); template deletion (archive only); PayPal; PDF export (contract 17
§5.6).

## 10. Payment-provider seam

V1 execution provider: **Stripe** (existing Connect gateway and in-page Payment
Element). Post-V1 candidate: **PayPal**. The document stores amount due, due
timing / date, the schedule and payment state only — `content.payment_plan` and the
`business_document_payment_schedule_items` rows contain no provider concepts — so a
second provider can be added behind `PaymentManager` without changing document or
template semantics. No PayPal UI exists in V1.

## 11. Ownership boundaries

Catalog owns products and prices. `DocumentManager` owns lines, schedule, lifecycle,
hashing and signing. The editor owns only `content.blocks` and the stored
`payment_plan` intent. Business-private templates belong to one Business;
platform templates belong to the Platform Owner and reach Businesses only as
niche-blueprint recommendations; documents never reference their source template.
