# Implementation Contract 17A — Proposals / Contracts / E-Signature V1 completion

**Status:** completion / acceptance pass on top of Contract 17. Nothing here
rewrites Contract 17's domain model; it closes the gaps found when the
draft → send → view → sign → signed-record journey was audited end to end,
and records every place the behaviour now differs from Contract 17's text.
Where this document and Contract 17 disagree on one of the points below, this
document governs.

Base: `a55f22c5` (Packages & Products V1 completion — Catalog snapshots are
consumed through `PackageSnapshotService::snapshotForBusiness()`).
Out of scope, unchanged: invoices, payments, refunds, Stripe (Contract 21),
the central Automations files, global navigation.

## 1. The one lifecycle

`DocumentStatus::allowedTransitions()` is the single authoritative map.
`DocumentManager` checks every status move it makes against it, on the
**locked** row (`assertTransition()`), so an impossible move is refused in one
place.

| from | to |
|---|---|
| draft | sent, void |
| sent | sent *(re-issue of a revised version)*, signed, paid, expired, void |
| signed | paid, void |
| paid / expired / void | — (terminal, never move) |

There is no `viewed` state: the public GET stays side-effect-free (Contract 17
§6.3/§10). `sent → paid` is an invoice settling; a signed agreement never
expires (§8.6). The payment finalizer's `→ paid` write is not routed through
the manager and is unchanged; the map is the contract it already obeys.

## 2. Send is idempotent; delivery is honest and retryable

* A second `send()` of a document that is already `sent` with **no open draft
  version** is a **replay** (double-click, browser retry, or a concurrent
  request that queued behind the first on the row lock): it returns the document
  unchanged — no new token, no supersede, no email, **no second `DocumentSent`**.
  *(Contract 17 §7.1 said this case was refused; the refusal is now a no-op
  success because the browser that retried has no way to tell the two apart.)*
  A draft, or a revision draft, is the only thing that makes a transition.
* `status = sent` / `sent_at` mean "frozen and a link was minted". They cannot
  say the email reached the mail provider, so two durable markers were added
  (migration `2026_10_26_090001`): `link_delivered_at` and
  `link_delivery_failed_at`, describing the **current** link only. Rotating the
  token clears both.
* `SendDocumentLinkEmail` records the outcome only against the link whose
  token it carries (checked with `Hash::check` against the stored hash): a job
  holding a rotated token neither emails the dead link nor overwrites the newer
  send's outcome. `failed()` records the failure; only the exception **class**
  is logged, never its message.
* A provider failure never fails or un-sends the committed document, never
  500s the owner (a synchronous queue used to surface it from inside the
  after-commit callback, skipping `DocumentSent`), and is shown on the document
  page as "could not be delivered".
* **`DocumentManager::resendLink()`** (`POST …/documents/{uid}/resend`; the
  Payments lane's method and route, which this lane merged with rather than
  duplicated) is the recovery: it rotates the token (every earlier link dies)
  and queues a new email to the frozen recipient snapshot. It is **not** a
  lifecycle transition — status, `sent_at`, the issued version and its hash are
  untouched and no `DocumentSent` is emitted. A `sent` **or `signed`**
  unexpired document has a link to re-send (a signed proposal is payable, and
  the link is how the customer reaches the payment surface); draft, paid,
  expired and void documents do not. Re-sending clears both delivery markers
  before the new email is queued.

## 3. Immutable transactional snapshots

* Catalog lines already copy name/price/currency and reference the write-once
  `package_snapshot_uid` (Contract 16/17 §5.4). Tests now pin that a later
  Catalog rename, reprice, Location price override or archive changes neither
  the issued line rows, the snapshot, the content hash, nor the public page —
  after send **and** after signature.
* The party names the recipient reads are frozen **into the issued version's
  `content`** at send (`content.parties`: `business_name`,
  `business_location_name`, `document_title`, `recipient_name`) before the hash
  is computed, so they are covered by `content_hash`. The public pages render
  from that frozen block; only a version issued before this change falls back to
  the live Business name. The block is server-owned: anything a browser submitted
  under `parties` is overwritten at send. No migration; no second snapshot
  mechanism.
* The public page now also renders the frozen **body/terms** (`content.body`),
  escaped — the signer was previously asked to sign terms the page did not show.

## 4. Public link and signature semantics (V1 decision preserved)

Typed-signature evidence, one signer, no countersignature, no vendor, no legal
sufficiency claim (Contract 17 §6.5) — unchanged. Link: `{uid}/{token}`, uid
UUIDv4, 64-character token stored only as a bcrypt hash, one uniform 404 for
every refusal, revoked by void, rotated by re-send, expiring with the offer —
unchanged.

New in this pass:

* **What was shown is what is signed.** The sign form carries
  `displayed_version_uid`; `DocumentManager::sign()` requires it to equal the
  locked current issued version's uid. If the owner revised and re-sent after
  the page was opened, the submission is refused (the public page re-renders the
  current version with an explanation) instead of signing terms never seen.
* **Sign is idempotent.** Repeating the *same* act (same version, signer name,
  email and typed mark) returns the existing signature row — one row, one
  `signed_at`, original evidence untouched, **one `DocumentSigned`** — and the
  public endpoint renders the same confirmation. A *different* act against a
  signed document is refused.
* After signature the document's CONTENT and lifecycle can no longer be
  mutated: edit, line add/remove/reorder, schedule change, revise and
  send / new issue are all refused; tests pin the whole matrix and that the
  stored hash still equals the hash of the stored rows.
* Secure-link RE-DELIVERY is not a content mutation and stays allowed while the
  signed document is still payable and unexpired: `resendLink()` (§2) rotates
  the token and re-emails the frozen recipient so the signer can return to the
  payment surface. It changes no document content, version, hash or lifecycle
  state, leaves the signature untouched, and emits no `DocumentSent`. Draft,
  paid, expired and void documents still have no link to re-send.

## 5. Events

`DocumentSent`, `DocumentSigned`, `DocumentVoided`, `DocumentExpired` now carry
`businessId`, `businessLocationId`, `contactId` (taken from the locked row) and
a deterministic `occurrenceKey()` (`document_version_sent:{versionId}`,
`document_signature:{signatureId}`, `document_voided:{documentId}`,
`document_expired:{documentId}`). All remain after-commit, ids/scalars only, and
are never emitted by a refused, rolled-back or replayed transition. No
Automations file was touched; no new event types were added.

## 6. Tenancy

`DocumentsController@store` now resolves Contact and Opportunity **inside** the
Business (a foreign uid is a 404, never a validation message that confirms it);
the manager still re-derives Business/Location/Contact/Opportunity integrity
(§6.6) and ignores any caller-supplied model field. Location ACL is the
canonical `LocationAccessGuard`; no second algorithm. Agency View As follows the
existing rules: Business-scoped document routes reach only the viewed
Business, and `.destroy` routes (remove line) are a prohibited deleting action
while viewing.

## 7. Customer surface

The document page now shows, for any status: customer/location, a history
(created, sent, each issued version, signed by, paid/expired/voided), the
delivery state with **Re-send payment link**, **Revise (new version)** for a
sent, unsigned document, the **read-only issued version** (body, lines,
schedule, payment status) and, once signed, the signature evidence with the
version fingerprint, alongside the Payments lane's payments and refunds panels. Draft editing is
unchanged. The list shows location, total and sent/signed/paid dates.

## 8. Not in this pass (deliberately)

* Open/view tracking (`DocumentViewed`) — Contract 17 §10 keeps it out of V1.
* Countersignature, drawn signatures, PDF rendering, vendor e-signature.
* Prefilling the recipient from the Contact (Contacts carry no email column).
* Routing the payment finalizer's `→ paid` write through `DocumentManager`.

## 9. Tests

`tests/Feature/Documents/`: `DocumentLifecycleStateMachineTest`,
`DocumentSendIdempotencyAndDeliveryTest`, `DocumentSignatureAndSnapshotTest`,
`DocumentJourneyAndTenancyHttpTest`, and `DocumentConcurrencyTest` (separate OS
processes racing for one document row held by a probe connection; runner in
`Support/concurrent_document_runner.php`). Existing tests that asserted the old
refusal-on-second-send / refusal-on-second-sign behaviour were updated to the
idempotent semantics above.


> **See also:** contract 17B (visual block editor, templates, niche recommendations, send channels). Authoring now also writes `content.blocks`; the lifecycle, hashing and signing rules in this document are unchanged.
