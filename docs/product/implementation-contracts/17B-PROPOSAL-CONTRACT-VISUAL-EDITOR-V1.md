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
  balance is payable after the deposit. `due_at` is displayed and drives the
  existing reminder sweep. An auto-issued payment-request email carrying a
  fresh pay link at the due date is **not** built (token plaintext is
  unrecoverable; it needs token rotation + a new job) — reported as a gap.

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

## 9. Deferred / reported gaps

Text, date, checkbox fields; multi-recipient signing; columns; free image upload
and Business logo block (no media seam / no logo column); hosted Stripe Checkout
redirect (existing in-page Payment Element is reused); auto-issued balance
payment request at the due date; PayPal; PDF export (contract 17 §5.6).
