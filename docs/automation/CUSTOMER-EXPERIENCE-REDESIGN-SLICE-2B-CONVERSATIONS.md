# CUSTOMER EXPERIENCE REDESIGN SLICE 2B — BUSINESS-SCOPED CONVERSATIONS
# IMPLEMENTATION CONTRACT

## 0. Status and authority

| Field | Value |
|---|---|
| Contract only — authorizes no implementation | true |
| `origin/main` at issuance | `634ff2b0d4840ecb4cdd1304d8083647cfcd16b9` |
| Chat A (`agent/customer-experience-slice-3-messaging-provider-implementation`) at issuance | `bc5954d2390d5693192ef3aceb956d29b3aef9a4` — re-fetched during this pass, unchanged |
| B1 (`agent/b1-outreach-business-scoped`) | **Merged**, PR #200, merge commit `30f43bfc85e3c44c28c5fed6044af1160c17c5b0` |
| This document's own branch | `agent/customer-experience-redesign-slice-2b-conversations-contract`, created fresh from `origin/main` at the SHA above |
| Predecessor: Slice 2A navigation | Contract merged (`docs/automation/CUSTOMER-EXPERIENCE-REDESIGN-SLICE-2A-NAVIGATION.md`, PR #237). **Implementation not yet observed on `origin/main`** — §20 depends on it landing first. |
| Predecessor: Slice 1 terminology | **Contract merged**, PR #240, merge commit `1d4c859afc3a5341e8db332f5dd982be95bcd463`. **Implementation still not yet observed on `origin/main`** — the contract merging is not the same thing as the implementation landing; §17's dependency order is unaffected. |
| Governance | Route 3 (AGENTS.md, "Explicitly human-authorized manual lanes") — scope comes from this task's own instructions, not from `AI-AUTONOMY-STATE.json`, which remains `gate_label: "ai:paused"` and grants no standing authority. This document does not change any field in that state file. |
| **Correction 1** (prior pass) | `origin/main` normal-merged from `634ff2b0` to `826d2310face763256937235231b4f15139a7850` (PR #239, the Slice 4 Dashboard contract — doc-only, one file, no conflict). Chat A re-inspected at its newest head `8893e9f31ccbe8ab27a8ab90d0fa4cb6b9178a29`. Corrected §4's backfill counterpart rule and added §5's explicit live-write orientation invariant; every other locked decision (§1-§3, §6-§20) was re-checked and confirmed unchanged. |
| **Correction 2** (this pass) | Status-sync only, no architecture change. `origin/main` normal-merged from `826d2310` to `1d4c859afc3a5341e8db332f5dd982be95bcd463` (PR #240, the Slice 1 terminology contract — doc-only, one file, no conflict). Updates this row and §17's overlap note to reflect that the Slice 1 terminology **contract** is now merged, while the Slice 1 terminology **implementation** — the actual code change to `resources/views/customer/ChatBox/new.blade.php` and the rest of that contract's allowlist — still has not landed on `origin/main`. Every decision from Correction 1 (§1-§20, including §3's tenancy column, §4's backfill algorithm, §5's live-write orientation invariant, §10's Contact display rule, §11's block rule, §21's test matrix) is unchanged. |

---

## 1. Correction of the reconnaissance's stale B1 claim

The prior reconnaissance stated *"B1 Outreach replies (`agent/b1-outreach-compose`, unmerged)"*. That branch name was never the one that merged. **The merged branch is `agent/b1-outreach-business-scoped`, PR #200, merge commit `30f43bfc85e3c44c28c5fed6044af1160c17c5b0`.** `agent/b1-outreach-compose` is a separate, still-unmerged branch this contract does not rely on and does not need to track.

**Mechanical audit of current `origin/main`'s merged B1 behavior:**

- `app/Http/Controllers/Customer/OutreachController.php` (last touched by B1's own commits `d69ad40`/`048d0be`) contains **zero references to `ChatBox` or `chat_box`**. It threads `business_id` extensively into `$sendData`/`$input` before calling `$this->campaigns->quickSend($campaign, $sendData)` (confirmed at four call sites: lines 221/269, 323/372, 426, 466).
- `app/Repositories/Eloquent/EloquentCampaignRepository.php::quickSend()` **still contains the exact `ChatBox::firstOrNew()` block** the reconnaissance found, **byte-identical**, at what is now lines 492-516: keyed on `['user_id', 'from', 'to', 'sending_server_id']` only, with no `business_id` in either the lookup key or the write. **B1 did not touch this block.**
- **`$input['business_id']` is already in scope at this exact line** — the same `quickSend()` method reads `$input['business_id'] ?? null` at two other points in the same file (confirmed at lines 886 and 1269), proving the value is available on the same `$input` array the ChatBox block already has direct access to. **This is a surgical, low-risk gap, not a design problem**: B1 made the data available and simply didn't extend to this one block, which predates B1 entirely.
- `app/Http/Controllers/Customer/ChatBoxController.php` was **not** touched by B1 (its own git history's last commits are `79ee6df "fix: secure Slice 6 ChatBox conversations"`, `6b553ea`/`15e7528` RFC-005 M5 work) — it remains the sole conversation-reply surface, unchanged, exactly as the reconnaissance described it.

**Exact result, recorded so it is never re-derived:**

| Question | Answer |
|---|---|
| Does merged B1 create ChatBoxes? | **Yes**, through the same pre-existing `quickSend()` block every caller (Outreach and ChatBoxController alike) shares — gated on `$sending_server->two_way && $input['originator']=='phone_number'` and `sms_type` in `{plain, unicode, mms}`. |
| Does merged B1 create ChatBoxMessages? | **Yes**, same block, `ChatBoxMessage::create()` immediately after. |
| Does merged B1 only create Campaign/Reports rows? | **No** — it also reaches the ChatBox/ChatBoxMessage block above whenever the two-way/phone-number-originator condition holds. |
| Does merged B1 intentionally skip the legacy AI chat-box hook? | **Yes, but by a pre-existing guard, not a B1-specific one.** The legacy AI-Prospecting hook (`campaignBuilder()`, not `quickSend()`) is gated on `$outreachBusinessId === null`; B1's Outreach compose flow always supplies a real `business_id`, so it structurally never enters that branch. This is the same "Business-aware campaign must never enter the AI sales state machine" guard the reconnaissance already found, confirmed still intact and still correctly excluding B1 traffic. |
| Can DLRController later deliver replies into a B1-created conversation? | **Yes, mechanically** — `DLRController::inboundDLR()`'s `ChatBox::updateOrCreate(['user_id','from','to'], [...])` (line 531) will match the same row B1's `quickSend()` created, because both key on the identical `(user_id, from, to)` triple. **Today this match happens without any Business check on either side**, which is exactly the gap §5 closes. |

No producer was invented on the strength of an old branch name. Every claim above is read directly from current `origin/main`.

---

## 2. Correction of the legacy-AI-schema blocker claim

The reconnaissance correctly found that current `origin/main` (at its own, older base SHA) lacks `chat_boxes.ai_replied`, `chat_boxes.ai_stage`, and `ai_box_campaign_map`, and correctly flagged the live code referencing them as broken *on that base*. **Re-verified unchanged on this contract's own base SHA (`634ff2b0`)**: `ai_replied` is still referenced at `DLRController.php:619` and `ai_stage`/`ai_box_campaign_map` still only at `EloquentCampaignRepository.php`, with no migration anywhere on `origin/main` supplying any of the three.

**Chat A's actual migration, read directly from `origin/agent/customer-experience-slice-3-messaging-provider-implementation:database/migrations/2026_09_12_100006_complete_legacy_ai_messaging_schema.php`:**

```php
Schema::table('chat_boxes', function (Blueprint $table): void {
    if (! Schema::hasColumn('chat_boxes', 'ai_replied')) {
        $table->boolean('ai_replied')->default(false)->after('reply_by_customer');
    }
    if (! Schema::hasColumn('chat_boxes', 'ai_stage')) {
        $table->unsignedTinyInteger('ai_stage')->nullable()->after('ai_replied');
    }
});

if (! Schema::hasTable('ai_box_campaign_map')) {
    Schema::create('ai_box_campaign_map', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('box_id');
        $table->unsignedBigInteger('campaign_id');
        $table->timestamp('created_at')->nullable();
        $table->index(['campaign_id', 'box_id'], 'ai_box_campaign_map_campaign_box_index');
        $table->foreign('box_id')->references('id')->on('chat_boxes')->onDelete('cascade');
        $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('cascade');
    });
}
```

Idempotent (`hasColumn`/`hasTable` guards), additive, reversible narrowly, producers deliberately untouched by that migration's own docblock ("the producers are deliberately untouched... stop-listed for B5 and belong to a separate contract").

**Binding contract clause**: the missing-schema condition is **not** a Slice 2B blocker, **conditioned on Chat A actually merging with this exact migration intact.** Slice 2B implementation:

1. **Begins only after Chat A's final Slice 3 merge** (already required independently by §17's dependency order).
2. **At implementation start, re-runs** `Schema::hasColumn('chat_boxes','ai_replied')`, `Schema::hasColumn('chat_boxes','ai_stage')`, `Schema::hasTable('ai_box_campaign_map')` (or the migration-status equivalent) against the then-current merged `origin/main`.
3. **If present**: preserve as-is; Slice 2B's own migration must not redefine, rename, or duplicate any of the three. Slice 2B's `up()` should itself use the same `hasColumn`/`hasTable`-guarded idempotent style for its own additive work, consistent with this repository's established retrofitting convention (the migration's own docblock names three precedents: `2024_03_05_162536_update_contacts_table.php`, `2025_05_29_131834_add_direction_to_reports_table.php`, `2025_10_13_144953_add_performance_indexes_to_reports_and_others.php`).
4. **If absent despite this contract's predecessor requirement**: **STOP and report a predecessor discrepancy** — do not silently re-author the missing migration, and do not silently proceed without it. Report exactly which of the three pieces is missing and do not implement Slice 2B until a human resolves the discrepancy.

`ai_stage`/`ai_box_campaign_map`'s producer (`campaignBuilder()`'s legacy AI-Prospecting branch) **remains distinct from Business Conversations for all of Slice 2B** — see §13.

---

## 3. `chat_boxes` tenancy column — locked

```php
Schema::table('chat_boxes', function (Blueprint $table) {
    $table->unsignedBigInteger('business_id')->nullable()->after('user_id');
    $table->index('business_id', 'chat_boxes_business_id_index');
    $table->foreign('business_id', 'chat_boxes_business_id_foreign')
        ->references('id')->on('businesses')->restrictOnDelete();
});
```

Matches the Business Data Tenancy Foundation Pass 1 pattern exactly (`database/migrations/2026_09_05_120001_add_nullable_business_id_to_tenancy_tables.php`), which this contract extends rather than reinvents.

**`chat_box_messages` gets no `business_id` column.** Parent ownership — `chat_box_messages.box_id → chat_boxes.business_id` — is sufficient, because every current and contracted message consumer resolves its parent, Business-scoped `ChatBox` first (§4, §11). No child tenancy column is added without a concrete query or security need, and none was found.

**NULL-`business_id` legacy rows are preserved, never deleted, and permanently inaccessible from any Business-scoped route.** Every Business-scoped query in §11's controller adds `WHERE business_id = ?` as a hard filter. **`business_id = selected OR business_id IS NULL` is prohibited in any Business route, without exception.**

---

## 4. Backfill — exact algorithm, `ChatBoxBusinessBackfillV1`

`LegacyBusinessResolver` is **not modified**. `LegacyBusinessResolver::resolveForCustomer()`'s `is_primary` fallback branch is a general-purpose, intentionally-looser contract for the eleven Pass-1 tables; widening it — or adding a second public method to it — is out of scope, and this backfill's stricter, single-purpose historical-evidence rule belongs in its own class, not in a shared abstraction other callers depend on.

**Locked**: `App\Library\Business\Migration\ChatBoxBusinessBackfillV1` — a new, versioned, immutable class, matching `BusinessDataTenancyBackfillV1`'s own convention (chunked, idempotent on `business_id IS NULL`, never fails the migration, logs an aggregate summary only).

### Producer orientation audit (Correction 1)

This contract's first draft proposed branching the backfill's counterpart column on whether a historical row was "created inbound" or "created outbound." **That branch is withdrawn — `chat_boxes` carries no durable creation-direction marker, and no such branch is safe.** Every live write path to `chat_boxes` was re-audited mechanically, on current merged `origin/main` (`826d2310face763256937235231b4f15139a7850`, after normal-merging PR #239) and on Chat A's newest head (`8893e9f31ccbe8ab27a8ab90d0fa4cb6b9178a29`), tracing caller assignments rather than inferring from variable names:

| # | Producer | `chat_boxes.from` | `chat_boxes.to` | Evidence |
|---|---|---|---|---|
| 1 | `EloquentCampaignRepository::quickSend()` (outbound compose/reply, `ChatBox::firstOrNew()`, line ~492) | `$sender_id = $input['sender_id']` (line 100) — the compose-time chosen sending identity: **owned** | `$phone = str_replace(..., $input['country_code'].$input['recipient'])` (line 246) — the typed recipient: **external** | Direct read, both current `origin/main` and unchanged on Chat A's head |
| 2 | `DLRController::inboundDLR()` (the single shared write, `ChatBox::firstOrNew()`/`updateOrCreate()`, all ~70 legacy-provider callers funnel through it) | its own 5th parameter `$from` | its own 1st parameter `$to` | Traced `inboundTwilio()` as the concrete caller: `$to = $request->input('From')` (Twilio's sender — **external**), `$from = $request->input('To')` (the Business's own Twilio number — **owned**), passed positionally into `inboundDLR($to, $message, $sendingServer, $cost, $from, ...)`. **Structural proof this holds for every one of the ~70 callers, not just Twilio**: `inboundDLR()` only creates a `Reports`/`ChatBox` row at all when its `$from` argument resolves to an owned, `status = 'assigned'` row in `PhoneNumbers` (`PhoneNumbers::where('number', $from)->where('status','assigned')...`, re-confirmed hardened to an exact/ambiguous-fails-closed match on Chat A's newest head); a caller that passed its external number into that slot would simply fail the lookup and write nothing, never a wrong-oriented row. Chat A's newest head fixes the earlier partial-match lookup bug and the blank-`uid` bug in this same block; **it changes none of the `from`/`to` orientation**, confirmed by direct read of both. |
| 3 | `EloquentCampaignRepository::campaignBuilder()`'s legacy AI-Prospecting hook (`DB::table('chat_boxes')->insertGetId()`, line ~1192 — dead on current schema per §2/§13, unchanged by this correction) | `$sender_id[0] ?? null` — the campaign's chosen sender identity: **owned** | `$phone` derived from `$contact->phone` — the Contact's own number: **external** | Direct read; consistent with #1 and #2 even though this producer is otherwise out of scope |

**No producer with reversed orientation was found.** Every one of the three write sites — covering 100% of the code that ever inserts a row into `chat_boxes`, on both current `origin/main` and Chat A's actively-changing head — agrees: **`chat_boxes.from` is always the owned/Business-side number, `chat_boxes.to` is always the external counterparty's number.** §4's opposite-orientation escape hatch (originally §4 of the task) is therefore **not invoked** — there is no need to restrict Contact evidence to a mechanically-provable subset of rows, because the orientation is provable for every row by construction, not merely by convention.

### Resolution order

**Step 1 — Contact evidence.**

1. Normalize `chat_boxes.to` — **always this column, unconditionally, with no branch on how the row was created** — using the canonical phone normalizer already in use elsewhere in this codebase for the same purpose (`libphonenumber\PhoneNumberUtil`, the same library `ChatBoxController::sent()`/`reply()` already call — no second normalizer is introduced).
2. Query:
   ```php
   Contacts::where('customer_id', $chatBox->user_id)
       ->where('phone', $normalizedCounterpart)   // or the repo's own normalized-comparison convention if phone is stored in a non-normalized form — verify at implementation time against how Pass 1's own contacts backfill compared values
       ->whereNotNull('business_id')
       ->pluck('business_id')
       ->unique();
   ```
3. **If the distinct set contains exactly one `business_id`**: resolve to it. Two or more Contact rows that happen to share that one Business are **not** ambiguous — only more than one *distinct* candidate Business is.
4. **If the distinct set contains more than one `business_id`**: this row is **AMBIGUOUS** — fall through to Step 2 only if Step 2 can still apply (it independently can, since it doesn't use Contact evidence at all), but if Step 2 also fails to produce a single answer, this row is **AMBIGUOUS**, not resolved by Step 1's partial evidence. (In practice: Step 1 giving >1 candidates and Step 2 giving exactly 1 Business is still a real conflict — Step 2's answer should **not** override an actual multi-Business Contact conflict. Implementation rule: if Step 1 produces ≥2 distinct candidates, stop and record UNRESOLVED for this row; do not consult Step 2.)

**Step 2 — Single-Business customer** (only reached when Step 1 produced **zero** candidates, i.e. no Contact evidence existed at all):

```php
$businesses = Business::where('customer_id', $chatBox->user_id)->get();
if ($businesses->count() === 1) {
    $businessId = $businesses->first()->id;
}
```
**Exactly one Business only.** More than one: do not resolve, no primary, no lowest-id, no first().

**Step 3 — otherwise**: `business_id` stays `NULL`.

**Explicitly excluded from this algorithm, as the task requires**: `SendingServer`-based inference, primary-*Business*-location inference, any percentage/majority-vote or statistical backfill, and `LegacyBusinessResolver`'s own `is_primary` branch.

**Idempotency, chunking, logging**: identical discipline to `BusinessDataTenancyBackfillV1` — every query scoped to `business_id IS NULL`, chunked (500-row pages consistent with the existing class's `CHUNK_SIZE`), and the migration logs only an aggregate `resolved`/`unresolved` count per run. **No phone number, contact name, or any other PII appears in a log line** — this is a stricter requirement than Pass 1's own backfill (which logs table names and counts only, already PII-free) and is called out explicitly here because Conversations backfill is the first of these classes to touch phone numbers directly as its resolution evidence.

---

## 5. Live-write policy

**General rule**: every producer that already has explicit Business identity in scope **must** write it. **Never re-derive `business_id` from `Auth::id()`/`LegacyBusinessResolver` when a Business route, or an already-threaded `$input['business_id']`, already supplied it.**

**Orientation invariant, locked (Correction 1).** Every write to `chat_boxes.from`/`chat_boxes.to`, in every producer this slice touches or adds, follows exactly one domain orientation, confirmed universal by the audit in §4:

```
chat_boxes.from = the selected Business's own sender identity / owned number
chat_boxes.to   = the external conversation counterparty's number
```

This is a **domain** orientation, not a provider-wire-format one. A provider adapter or controller may internally receive, and may internally keep calling, fields named `From`/`To` in whatever sense that specific provider's API uses them (Twilio's webhook `From` is the *external* sender, for instance — the opposite of this domain's `from`) — that is the provider's own naming, not this application's. **The one seam that writes to `chat_boxes` must normalize whatever the provider/controller called things into this exact domain orientation before the write**, exactly as `inboundDLR()` already does today (its own local `$from`/`$to` parameters are already, correctly, in the *domain* sense confirmed by §4 — it is each caller's job to map its provider's wire fields onto `inboundDLR()`'s domain-oriented parameters correctly, which `inboundTwilio()` already does and which any new Slice 2B compose/reply path must do identically). No new code in this slice may write `chat_boxes.from`/`chat_boxes.to` from a provider-named variable without first confirming, by the same reasoning as §4's audit, which domain side that variable actually represents.

**Business compose/reply** (`EloquentCampaignRepository::quickSend()`, lines 490-517, and its future Business-route callers per §6):

```php
$chatbox = ChatBox::firstOrNew([
    'user_id'           => $user->id,
    'business_id'       => $businessId,      // NEW — part of the uniqueness key
    'from'              => $sender_id,
    'to'                => $phone,
    'sending_server_id' => $sending_server->id,
]);
```
where `$businessId = $input['business_id'] ?? null` — **read from the exact variable already available in scope at this line** (confirmed present, §1), never re-derived. **Adding `business_id` to the `firstOrNew` key set is mandatory, not optional**: the task's own instruction — "the same user/from/to/server tuple in Business A must not collapse into Business B's box" — is only satisfied by including it in the lookup key itself, not only in the write payload. A caller with `$businessId === null` (a legacy, non-Business-scoped caller, if any survive after §7/§8's cutover) continues to key on `business_id => null`, which Eloquent's `firstOrNew` treats as a normal equality match against NULL and will not collide with any Business-scoped row.

**Inbound legacy callback** (`DLRController::inboundDLR()`, line 531): mechanically inspect the **final merged** Slice 3 DLR path before writing code — this file is Slice 3's until Chat A merges, and Slice 2B's own edit here is narrow and late (§19). Rule: use an authoritative, already-persisted Business id **only** where the callback's own resolved phone/identity/provider mapping actually supplies one (e.g., if Chat A's merged code already resolves a `business_id` for the inbound `Reports::create()` two lines above this block via `app(LegacyBusinessResolver::class)->resolveForCustomer(...)`, in which case that same value may be reused here — read, not re-derived independently). **If the legacy callback can resolve only the legacy `User` and not a Business**, it may still create/update the ChatBox with `business_id => null` for compatibility (this is exactly the "NULL-business legacy row" case §3 already provides for), but it **must never guess** a Business, and that conversation **must not** become visible through any Business-scoped route (§11) as a result.

**Managed messaging**: re-verify final Slice 3 at implementation start. **If**, and only if, Chat A's merged `BusinessMessagingIdentity`/`BusinessMessagingNumber` path already creates its own Business-attributed conversation/message rows (its own completion document, read in an earlier pass of this program, described a separate managed operation ledger — `business_messaging_operations` — not `chat_boxes`), then Slice 2B threads that already-known Business id into the conversation seam **only if** Chat A's merged code actually produces Business Conversation rows through this same `ChatBox`/`ChatBoxMessage` pair — it must **not** duplicate Chat A's own message ledger, and must not invent a bridge Chat A's own contract didn't design. If Chat A's managed path turns out not to touch `chat_boxes` at all (the more likely outcome given its own documented table set), Slice 2B does nothing here and this clause is inert. **Do not merge Agency Prospecting** under any reading of this clause (§13, §20).

---

## 6. Business-scoped compose dependencies

`ChatBoxController::new()`/`sent()`/`reply()` currently resolve every compose-time resource (`PhoneNumbers`, `CustomerBasedSendingServer`, `Templates`, `Blacklists`, sender identity) by `Auth::user()->id` alone. Every one of these models already carries `business_id` (Business Data Tenancy Foundation Pass 1, §2 of the reconnaissance, re-confirmed unchanged in this pass). The rewrite:

- Every such lookup becomes `->where('business_id', $selectedBusiness->id)` instead of `->where('user_id', Auth::id())`, mirroring the exact pattern already established and merged in `OutreachController.php` (lines 132, 134, 148-150, 214, 326) — **reuse that pattern verbatim**, do not invent a parallel one.
- **HTTP actor** (who is authenticated, whose permissions/entitlement gate the request): the authenticated Workspace member — unchanged from every other Business-scoped controller in this program.
- **Persistence/billing owner** where a legacy `user_id` column is structurally required (e.g., `ChatBox.user_id`, `Reports.user_id`, the legacy `sms_unit` wallet decrement in `quickSend()`): the **selected Business's owning customer identity**, never the staff actor's own `Auth::id()`. This matches B1's own established convention — `OutreachController` resolves the Business first and uses its owning customer for every legacy-shaped write, never the logged-in staff member's id.
- **Every submitted resource id** (sending server, number, template, sender identity) must be **re-validated server-side** as positively belonging to the selected Business (`->where('business_id', $selectedBusiness->id)->where('id', $submittedId)->firstOrFail()` or equivalent) before use — **no capability-only acceptance of a foreign Business's resource merely because the actor has permission to compose at all.** This closes the exact "foreign sending server / foreign phone / foreign template / foreign sender identity" class named in §21's test matrix.
- **No redesign of provider configuration** — the resource-scoping rewrite changes *which* rows a query is allowed to return, never how `SendingServer`/credentials are structured or resolved.

---

## 7. Canonical route set — locked

```
GET  /workspaces/{workspaceUid}/businesses/{businessUid}/conversations
GET  /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/new
POST /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/sent
POST /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/{uid}/messages
POST /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/{uid}/notification
POST /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/{uid}/reply
POST /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/{uid}/delete
POST /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/{uid}/block
POST /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/{uid}/pin
POST /workspaces/{workspaceUid}/businesses/{businessUid}/conversations/load
```

`load`'s verb is locked to **POST**, not `ANY` — the current route (`Route::any('/load', ...)`) accepts any verb only because it was never deliberately chosen; `loadChatUsers()` is read-only in effect but is called via AJAX POST by the existing first-party JS (confirmed by the existing route's usage pattern alongside every other action in the same prefix, all POST). Locking it to POST is the narrowest verb mechanically compatible with the existing AJAX call and avoids leaving a GET-accessible, unauthenticated-cacheable alias with no corresponding benefit.

Route names: `customer.workspaces.businesses.conversations.*`. `ChatBox.uid` remains the sole external conversation identifier in every URL — **no numeric-primary-key route exists or is added.**

---

## 8. Legacy flat routes — locked disposition

**Removed as state-changing/read mutation paths, effective at cutover — not left as User-scoped compatibility shims:**

```
POST /chat-box/sent
POST /chat-box/{box}/messages
POST /chat-box/{box}/notification
POST /chat-box/{box}/reply
POST /chat-box/{box}/delete
POST /chat-box/{box}/block
POST /chat-box/{box}/pin
POST|ANY /chat-box/load
```
After cutover, none of these route names exist; a request to any of these URIs 404s (`Route::has()` false), the same standard this program already applies to retired debug/legacy routes elsewhere.

**Compatibility retained only for the two navigation GET entry points**, and only as **redirectors**, never as data-serving endpoints:

```
GET /chat-box       (was ChatBoxController@index)
GET /chat-box/new   (was ChatBoxController@new)
```

Resolver behavior for both, using the actor's accessible-Business set (the same enumeration `CustomerContext`/`WorkspaceManager` already expose elsewhere in this program):

- **0 accessible Businesses**: redirect to the existing "no Business yet / create your first Business" destination already used by every other Business-only entry point in this program (do not invent a Conversations-specific empty state for this redirect — reuse the existing one).
- **Exactly 1 accessible Business**: redirect to `customer.workspaces.businesses.conversations.index` for that one Business. This is deterministic, not a primary-Business guess — there is exactly one candidate.
- **More than 1 accessible Business**: redirect into the Account/Agency frame (the existing Business chooser), never guess, never pick primary.

**No POST redirect** exists at either bare path — both are GET-only, matching their current declaration, and neither accepts or forwards any state-changing payload.

---

## 9. Authorization chain — locked

```
Workspace UID (route)
  → Business inside Workspace (route-resolved, same pattern as every
    customer.workspaces.businesses.* controller)
  → WorkspaceManager::userCanAccessBusiness(actor, Business)   [app/Library/Workspace/WorkspaceManager.php:97 — exists, reused verbatim]
  → chat_box permission (Gate/authorize('chat_box') — category gate, unchanged in meaning)
  → Conversations entitlement (PlatformFeature `conversations`, via 2A's
    MenuEntitlements/EntitlementManager snapshot seam — §20)
  → ChatBox resolved by uid AND business_id == Business.id (replaces
    resolveOwnedChatBox's current `user_id` clause with `business_id`)
  → any message/contact/resource read alongside it resolved through the
    same Business scope, never independently re-derived
```

Foreign scope at **any** link — wrong Workspace, wrong Business, wrong `business_id` on the ChatBox row itself, or a nonexistent `uid` — returns the same **404**, collapsing every failure mode identically, exactly matching `resolveOwnedChatBox`'s own existing "foreign and nonexistent are indistinguishable" discipline (§4 of the reconnaissance), now keyed on `business_id` instead of `user_id`.

**Explicitly prohibited as a tenancy boundary anywhere in this chain**: `Auth::id()`-only scoping, `customer_id`-only scoping (the current `block()` action's independent key, corrected in §11 of this contract... see §11 below — this document's own §11 is route set; the block-action correction is §11 of the *task*, folded here into §16), primary-Business inference, and `LegacyBusinessResolver` (reserved for the one-time backfill only, never a request-time authorization path).

**View-as**: uses the **already-resolved viewed Business** from the request's `CustomerContext` (which is already View-as-aware elsewhere in this program) — never re-derives a Business from the Agency actor's own accessible set. An Agency staff member viewing Business A cannot reach Business B's conversations through this chain even though both may belong to Businesses they otherwise manage.

---

## 10. Contact decision — locked, no new prerequisite

**A Conversation may exist without a Contact.** Slice 2B does **not**: auto-create Contacts from inbound conversations, add any uniqueness constraint to `contacts`, or otherwise touch Contacts schema.

**Display-only lookup**, replacing `ChatBox::contact()`'s current unscoped `belongsTo(Contacts::class, 'to', 'phone')`. Per §4's producer audit, the external counterparty is **always** `chat_boxes.to`, regardless of whether the conversation was created by an outbound send or an inbound message — the lookup is never branched:

```php
Contacts::where('business_id', $selectedBusiness->id)
    ->where('phone', $normalize($chatBox->to))
    ->get();
```
- **Exactly one match**: the UI may show that Contact's name.
- **Zero matches**: show the phone number.
- **More than one match**: **do not** pick `first()` — show the phone number / a neutral fallback, identical to the zero-match case from the UI's perspective.

The existing `ChatBox::contact()` Eloquent relationship, as currently defined, **must not remain in use for any Business-facing rendering** — it is either replaced by the explicit, Business-scoped read above (recommended: a plain method on `ChatBox`, e.g. `resolveDisplayContact(Business $business): ?Contacts`, not an Eloquent relationship, since the "more than one → neutral fallback" rule cannot be expressed as a `belongsTo`), or its call sites are repointed to the new seam and the old relationship method is left defined-but-unused for whatever non-Business surface, if any, still legitimately calls it (none was found in this pass — if implementation confirms none, delete the unused relationship method as ordinary cleanup within this same PR, not a separate one).

---

## 11. Block action — locked

Current `block()` (`ChatBoxController.php:651-687`): creates a `Blacklists` row with `user_id` only, then does `Contacts::where('phone', $box->to)->where('customer_id', Auth::id())->first()` — a third, independently-scoped key, unscoped by Business.

**Confirmed correct counterpart, unchanged by Correction 1**: `$box->to` is already, and remains, the external counterparty under the orientation §4 confirms — the existing code's choice of column was never the defect; only its Business scoping was. This holds identically whether the conversation was created by the customer's own outbound send or by an inbound message, since `to` carries the same domain meaning either way — no branch is needed here, matching §4/§10.

**Locked replacement:**

```php
Blacklists::create([
    'business_id' => $selectedBusiness->id,
    'user_id'     => $selectedBusiness->owning customer id (legacy compatibility column — the
                     Business's persistence owner, exactly per §6's rule, never the staff actor),
    'number'      => $box->to,
    'reason'      => 'Blacklisted by ' . auth()->user()->displayName(),
]);

Contacts::where('business_id', $selectedBusiness->id)
    ->where('phone', $box->to)
    ->update(['status' => 'unsubscribe']);
```

- The blacklist row is created with `business_id`, since `blacklists` already carries it (Pass 1, confirmed).
- The Contact update is scoped to `business_id = $selectedBusiness->id` **and applies to every matching row within that one Business** — if duplicate Contact rows exist for the same phone *within the same Business*, all of them are updated (this is not the cross-Business ambiguity §10 guards against; it is ordinary same-tenant duplicate cleanup, and leaving half of a Business's own duplicate Contacts subscribed while the other half is blocked would itself be a defect).
- **A Contact belonging to a different Business is never touched**, even if its phone number matches, closing the exact cross-Business leak the task names.

---

## 12. `send_by` data-integrity correction — locked

**Mechanically confirmed historical precedent**, read directly from `app/Models/Campaigns.php:849-873` (a commented-out, dead block, never executed, but the repository's own only surviving record of the field's intended semantics before the live bug was introduced):

```php
// ChatBoxMessage::create([
//     ...
//     'send_by' => 'from',
//     ...
// ]);
```
for an **outgoing** message. This is internally consistent with the schema (`enum('from','to')`) and with `ChatBox.from`/`ChatBox.to` semantics: `send_by='from'` means the box's own `from` number sent this message (outgoing), `send_by='to'` means the box's `to` (counterpart) number sent it (incoming) — exactly mirroring `direction`.

**Locked correction**, applied at the one live call site Slice 2B already owns (`EloquentCampaignRepository.php:511`):

```php
ChatBoxMessage::create([
    'box_id'            => $chatbox->id,
    'message'           => $message,
    'direction'         => Reports::DIRECTION_OUTGOING,
    'sms_type'          => 'plain',
    'sending_server_id' => $sending_server->id,
    'media_url'         => $input['media_url'] ?? null,
    'send_by'           => 'from',   // corrected from $user->id
]);
```

`direction` remains the canonical, customer-visible direction field everywhere the Inbox reads it — this correction does not change that; it only stops writing an invalid value into a column nothing currently depends on for correctness, so this is a genuinely narrow, same-slice, no-schema-change fix, not a design change. **No enum/schema change.** **No historical-row rewrite** — existing corrupted `send_by` values on already-written rows are left exactly as they are; this contract does not authorize a backfill or cleanup pass over them, only stopping the ongoing corruption for new rows.

---

## 13. Legacy AI fields — locked disposition

Assuming Chat A merges with its migration intact (§2):

- `chat_boxes.ai_replied`, `chat_boxes.ai_stage`, `ai_box_campaign_map`: **preserved, untouched, not removed, not duplicated.**
- The legacy AI campaign-builder hook (`campaignBuilder()`'s `$outreachBusinessId === null` branch) **remains legacy** and **out of Slice 2B's edit scope** entirely — Slice 2B's own producer edit (§5) targets `quickSend()`, a different method in the same file, not `campaignBuilder()`.
- **B1's Business-aware Outreach already, structurally, never enters this branch** (§1) — Slice 2B changes nothing about that guard and must not weaken, remove, or "improve" it.
- This branch **must never cause a NULL-`business_id` legacy AI chat box to appear in any Business Inbox** — already guaranteed by §3's hard `WHERE business_id = ?` filter and by the branch's own `business_id`-less writes never being backfilled toward a guess (§4, Step 3).
- `ChatBoxMessage::booted()`'s `cg_ai_conversations`/`cg_ai_messages` hook: confirmed dead (unreachable condition, references tables with no migration anywhere). **Not touched, not deleted, not expanded** in Slice 2B. Recorded here, again, for whoever eventually owns dead-code cleanup — this contract does not assign that work to anyone.
- **No Agency Prospecting table or model is touched** — `agency_prospect_messages` and its siblings remain fully outside this contract's allowlist (§19) and stop-list (§20).

---

## 14. Query/relation design — locked

Current `index()`/`loadChatUsers()` eager-load `chatBoxMessages` (the **entire** message history) purely to render a one-line preview — confirmed by direct read, `with(['chatBoxMessages', 'contact'])` with no `latest()`/`limit()` qualifier. **Not carried forward.**

**Locked replacement**: a bounded latest-message relation, e.g.
```php
public function latestMessage()
{
    return $this->hasOne(ChatBoxMessage::class, 'box_id', 'id')->latestOfMany();
}
```
(Laravel's native `latestOfMany()` — no custom subquery machinery needed, avoids N+1 by batching exactly like any other eager-loaded relation) used in place of `chatBoxMessages` for every list/preview rendering; the full `chatBoxMessages` relation remains available, unchanged, for the actual open-conversation message-thread view (`messages()`), where the full history is genuinely needed.

**Indexes, locked**:
- `chat_boxes_business_id_index` (§3, required).
- `(business_id, pinned, updated_at)` — covers the pinned-rail query and the `recents` filter.
- `(business_id, notification)` — covers the `unread`/`read` filters.
- `chat_box_messages(box_id, created_at)` — **added only if**, at implementation time, `EXPLAIN` against the real `WHERE box_id = ? ORDER BY created_at` query shows the existing FK-implicit index insufficient. This contract does not mandate adding it unconditionally; it mandates checking.

No index beyond these four/conditional-fifth is authorized — no speculative pile.

---

## 15. Dashboard read seam — locked, now required

**Consistency sweep note (Correction 1)**: the Slice 4 Dashboard contract, merged into `origin/main` since this document's first draft (PR #239, §9.1), names its expected method `conversationsStarted(Business, CarbonImmutable, CarbonImmutable): int` on an unnamed class, with the same predicate and half-open range shape locked below under the name `startedCount()` on `BusinessConversationReadModel`. The two are semantically identical; only the method name differs. Slice 4's own contract explicitly anticipates and accepts this: *"If the seam 2B lands differs in name or signature from §9.1, Slice 4 consumes what 2B actually shipped and records the difference — it does not build its own."* **No rename is made here** — renaming the seam is outside this correction's scope (orientation only), and Slice 4's own contract already resolves the mismatch without requiring one. This is recorded so the discrepancy is never mistaken for an oversight.

`App\Library\Conversations\BusinessConversationReadModel`:

```php
final class BusinessConversationReadModel
{
    public function startedCount(Business $business, CarbonImmutable $startUtc, CarbonImmutable $endUtc): int
    {
        return ChatBox::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<', $endUtc)
            ->count();
    }

    public function unreadCount(Business $business): int
    {
        return ChatBox::query()
            ->where('business_id', $business->id)
            ->where('notification', '!=', 0)
            ->count();
    }
}
```

- `startedCount()` is the exact predicate the parent redesign's "conversations started" dashboard metric needs — `business_id = Business.id AND created_at` half-open range — and is called **twice** by Dashboard Slice 4 (current 30 local days, previous 30 local days), passing explicit `CarbonImmutable` boundaries computed by the caller in the caller's own timezone convention; this seam performs no timezone logic of its own.
- `unreadCount()` is exposed because it is honestly supportable without new schema (§17 of the reconnaissance already established this), but Dashboard Slice 4 deciding to consume it is that slice's own choice, not mandated here.
- **No cross-Business aggregate, no delivery metric, no join to `Reports`** — all three explicitly excluded, matching §15 of the reconnaissance's own finding that delivery state has no FK path from `chat_box_messages` today, and the task's explicit exclusion.
- **No Dashboard code is written in this slice.** This class and its two methods are the entire Slice 2B deliverable toward Dashboard Slice 4.
- Index support: both methods are served by the `(business_id, notification)` and the `business_id` index already locked in §14 plus §3; `startedCount()`'s `created_at` range additionally benefits from an index on `(business_id, created_at)` — **add this as a fifth locked index**, since it's a real, named query this contract itself defines (not speculative): `chat_boxes_business_id_created_at_index`.

---

## 16. 2A navigation integration — locked

Slice 2A's **contract** is merged (PR #237, doc-only). Its **implementation** — the actual `MenuEntitlements`/`EntitlementManager::snapshotBusinessFeatureDecisions()` seam described in that contract's §6.3-6.5 — was **not found on current `origin/main`** in this pass (`app/Library/Navigation/CustomerMenuBuilder.php`'s Conversations entry, re-checked at this contract's base SHA, still reads exactly `$this->item($user, 'conversations', 'Conversations', 'message-square', ['chat_box'], 'customer.chatbox.index', [], $current, ['customer.chatbox.'])` — the same hardcoded, unentitled, User-route form the reconnaissance already recorded). **This confirms §17's dependency order is a real, currently-unmet predecessor, not a formality.**

Once 2A's implementation lands, Slice 2B's own integration is exactly:

```php
$items[] = $this->item(
    $user, 'conversations', 'Conversations', 'message-square',
    ['chat_box'], 'customer.workspaces.businesses.conversations.index',
    $scoped, $current, ['customer.workspaces.businesses.conversations.'],
);
```
gated additionally on `$menuEntitlements->allows('conversations')` per 2A's own contracted `MenuEntitlements` value object, the same way `automations`/`website_generation`/`google_business_profile_module` are already gated per that document. **This is the entire navigation change** — no other line in `CustomerMenuBuilder.php` is touched, and the navigation tree is not rebuilt.

**Any 2A-authored test that locks the old flat `customer.chatbox.index` URI as unchanged** (2A's own contract, line ~471, names such a test as one of its own acceptance criteria) **is explicitly superseded by Slice 2B and must be updated to assert the new route, not silently deleted** — the task's own instruction, recorded here as a binding requirement on the implementer, not a suggestion.

---

## 17. Dependency order — locked, no circularity

```
1. Chat A / Customer Experience Slice 3 (messaging-provider-implementation) — final merge
2. Slice 1 terminology — IMPLEMENTATION complete
     (the Slice-1-terminology contract is now merged as PR #240; during
     the earlier audit that produced this dependency, it had been
     inspected directly on its own branch, before that merge. The
     mechanically-confirmed overlap this dependency rests on is
     unchanged either way: it authorizes two label changes inside
     resources/views/customer/ChatBox/new.blade.php — "sending_server"→
     "messaging_provider" at its line 44, "originator"→"sender_identity"
     at its line 65. Slice 2B's own controller/view rewrite touches this
     same file. The contract merging does not mean this relabel has been
     implemented — it has not yet landed on origin/main. Landing Slice
     2B before Slice 1's terminology IMPLEMENTATION would either
     overwrite that pending relabel or force Slice 2B to re-implement it
     ad hoc — land strictly after.)
3. Slice 2A navigation — IMPLEMENTATION complete (§16)
        │
        ▼
Slice 2B implementation (this contract) — ONE PR (§18)
        │
        ▼
Dashboard Slice 4 — consumes §15's read seam
```

**B1 needs no predecessor relative to this contract — it is already merged (§1).** No circular dependency exists: Chat A, Slice 1, and 2A are each independently completable without Slice 2B, and Slice 2B depends on all three without any of them depending back on it.

---

## 18. One coherent implementation PR — locked

**One atomic PR**, owning together: the nullable `business_id` migration, `ChatBoxBusinessBackfillV1`, model scoping, the Business route family, the controller rewrite, compose/reply resource scoping (§6), live producer Business-threading (§5), Contact display safety (§10), the Business-scoped blacklist behavior (§11), the `send_by` correction (§12), the bounded list query (§14), the 2A Inbox route/entitlement swap (§16), the Dashboard read seam (§15), and the full test matrix (§21).

**Read routes are never split from producer writes.** A partially Business-scoped Inbox — new routes reading `business_id` while a producer still writes only `user_id` — is explicitly worse than waiting, per the task's own instruction, and this contract provides no exception to that rule.

---

## 19. Implementation allowlist — locked

```
database/migrations/<new>_add_business_id_to_chat_boxes.php
database/migrations/<new>_backfill_chat_boxes_business_id.php

app/Library/Business/Migration/ChatBoxBusinessBackfillV1.php

app/Models/ChatBox.php
app/Models/ChatBoxMessage.php                          (only the latestOfMany() relation and, if §14's
                                                          EXPLAIN check requires it, the box_id/created_at index)

app/Http/Controllers/Customer/ChatBoxController.php     (full rewrite: all ten actions, §9's chain,
                                                          §6's resource scoping, §11's block correction)

app/Repositories/Eloquent/EloquentCampaignRepository.php
                                                         (ONLY: the ChatBox::firstOrNew key/write at
                                                          quickSend() lines ~492-516, and the send_by
                                                          correction, §12. NOT campaignBuilder(), NOT
                                                          any RFC-005 metering code in the same file.)

app/Http/Controllers/Customer/DLRController.php
                                                         (ONLY: producer #3's ChatBox/ChatBoxMessage
                                                          business_id threading, §5. EXTRA CARE — this
                                                          file is Slice 3's until Chat A merges; re-read
                                                          it fresh, in full, against the actual merged
                                                          content before editing a single line, since
                                                          Chat A is under active Security Correction 37
                                                          and its exact line numbers WILL have shifted.)

app/Library/Conversations/BusinessConversationReadModel.php

routes/customer.php                                     (the chatbox.* block: replace/retire per §7-8)

app/Library/Navigation/CustomerMenuBuilder.php           (one entry, §16)

resources/views/customer/ChatBox/*                       (repoint to new routes; apply Slice 1's two
                                                          already-landed label changes if not already
                                                          present, never revert them)
public/js/scripts/pages/chat.js                          (only if it hardcodes any /chat-box/* endpoint
                                                          URL rather than consuming a server-rendered
                                                          route() value — verify at implementation time)

tests/Feature/Security/ChatBoxSecurityTest.php            (extended with the Business dimension, not replaced)
tests/Feature/DesignSystem/ChatBox*.php                   (re-pointed at new routes)
tests/Feature/Business/ChatBoxBusinessBackfillV1Test.php  (new)
tests/Feature/Conversations/BusinessConversationReadModelTest.php (new)
```

No directory wildcard beyond the one unavoidable exact view family (`resources/views/customer/ChatBox/*`, four known files, §4/§7 of the reconnaissance).

---

## 20. Stop-list — locked

```
Agency Prospecting, agency_prospect_*
B4 Automations
B5 Analytics formulas
Dashboard implementation (seam only, §15)
Usage Wallet / Billing
Provider credential system
BusinessMessaging operation ledger (business_messaging_operations) — except the narrow,
  conditional bridge §5's "Managed messaging" clause describes, and only if Chat A's
  final merged content actually requires it
Contacts schema, Contacts uniqueness
LegacyBusinessResolver (reused read-only for its existing public method; never modified)
Google Business Profile, Website, SEO, Ads, Calendar, Forms, Payments
AI COO, AI Workforce
General legacy-provider webhook security work beyond this contract's own narrow
  DLRController edit (§19's "EXTRA CARE" line)
RFC-005 usage-measurement implementation
cg_ai_* dead-hook cleanup (§13 — recorded, not actioned)
Any rewrite of historical ChatBoxMessage rows (§12)
Any primary-Business inference, anywhere, for any purpose
```

---

## 21. Test matrix — locked, exact map

| Group | Tests |
|---|---|
| **Migration/backfill** | nullable `business_id` exists + indexed + FK `restrictOnDelete`; two duplicate same-Business Contact rows resolve deterministically (not ambiguous); Contacts spanning two distinct Businesses leave the row unresolved; exactly-one-Business-customer fallback resolves; multi-Business customer with no Contact evidence stays NULL (no primary); a pre-existing NULL legacy row is retained across a rerun; rerun is idempotent (no row re-touched, count unchanged) |
| **Counterpart orientation (§4/§5, Correction 1)** | outbound `quickSend()` persists `from = owned sender identity`, `to = the typed recipient`; the canonical inbound webhook path persists `from = the Business's own receiving number`, `to = the external sender`; an inbound reply to a box the customer's own outbound send created, and an outbound reply to a box an inbound message created, both converge on the **same** `(user_id, business_id, from, to)` tuple — i.e. inbound and outbound traffic for one real-world pair never produce two boxes; backfill Contact evidence reads `chat_boxes.to`, never branched by how the row was created; an outbound-created historical row and an inbound-created historical row both resolve identically given the same Contact evidence; no test or production code guesses orientation from a "created inbound" vs "created outbound" marker, because none exists |
| **Tenancy — every one of the six mutating actions + messages/notification** | owner (correct Business) succeeds; Workspace admin succeeds; selected staff (Business-scoped role) succeeds; view-as (viewed Business only) succeeds and cannot escape it; same actor, Business A vs Business B — B denied while acting inside A; foreign Workspace denied; foreign Business (same actor, different Workspace) denied; NULL-`business_id` legacy row denied via every Business route; foreign `uid` denied; numeric id cannot substitute for `uid` (extends the existing `test_numeric_primary_key_cannot_resolve_a_chatbox_for_any_of_the_six_actions` under the new scoping) |
| **Compose resources (§6)** | foreign sending server rejected; foreign phone number rejected; foreign template rejected; foreign sender identity rejected; a staff actor's compose persists the **Business's** owning-customer id, never the staff actor's own id |
| **Contact (§10) and block (§11)** | same phone in two Businesses never leaks the other Business's Contact name; duplicate Contacts within one Business use the neutral phone-fallback display, never `first()`; `block()` unsubscribes only same-Business Contact rows, never a foreign Business's matching-phone row; a Conversation with zero matching Contacts still renders (phone-only); `block()` on a conversation whose box was created by an **inbound** message blacklists the **external sender's** number, not the Business's own receiving number (the exact regression the task requires); the Business's own `from` number is never, under any code path, looked up as if it were a Contact or blacklist target |
| **Producers (§5)** | Business compose writes `business_id` onto the created/matched ChatBox; Business reply preserves it on the existing box; an inbound callback with an authoritative resolved Business writes it; an inbound callback with no resolvable Business writes NULL and never guesses; B1's existing (pre-Slice-2B) Outreach behavior is unchanged by this slice's own regression suite (no Campaign/Reports/wallet behavior differs); Agency Prospecting's own test suite is unaffected (run, not edited) |
| **Data integrity (§12)** | a newly-created outgoing ChatBoxMessage has `send_by = 'from'` and `direction = 'outgoing'`; no existing row's `send_by` value is altered by this migration or this deploy |
| **Routes (§7-8)** | every canonical Business route resolves and is authorized per §9; every retired flat POST route 404s (`Route::has()` false for each of the eight old names); the two bare GET routes redirect correctly for 0/1/>1 accessible Businesses, never a primary guess; every first-party Blade/JS reference is repointed (grep-verified zero remaining references to `customer.chatbox.*` route names outside this migration's own historical-compatibility redirect controller) |
| **Entitlement (§16)** | Conversations menu entry absent when `conversations` is unavailable per the entitlement snapshot; a direct request to a Business Conversations route when the feature is unavailable is refused per whatever this program's own established Business-feature-unavailable convention is (404, matching T-AUTHZ-1 elsewhere in this program) — not invented fresh here; the menu entry correctly consumes 2A's actual snapshot, not a re-derived check |
| **Query (§14-15)** | list rendering uses the bounded `latestOfMany()` relation, not the full history; no N+1 across a multi-conversation list (assert query count); the four/five locked indexes exist; `startedCount()`/`unreadCount()` each execute within a small, explicitly asserted query count |
| **Dashboard seam (§15)** | `startedCount()` is Business-isolated (a second Business's conversations never counted); current-vs-prior-range fixtures produce the correct two distinct counts; `unreadCount()` correct when exposed; no query anywhere in this class touches a second Business's rows |
| **Separation (§13, §20)** | no Agency Prospecting table/row is read or written by any new code; no B5 mutation; no billing mutation; `chat_box_messages` gains no `business_id` column; no Contacts uniqueness migration is added |
| **Full regression** | existing `ChatBoxSecurityTest`/`ChatBoxComponentAdoptionTest`/`ChatBoxDesignSystemContentTest`/`ChatBoxExistingBehaviorPreservedTest` (updated for new routes, still green); B1 Outreach's own existing suite; Chat A's final merged Messaging suite; 2A Navigation's suite; Workspace/Business suites; Entitlement suites; one full-suite run reported with exact database name, migration count, and test/assertion totals per AGENTS.md's own reporting requirement |

---

## 22. One-PR conclusion

**Confirmed feasible as one PR** (§18) — the domain is small and now fully enumerated (one controller, two models, one new backfill class, one new read-model class, ten routes, one menu line, a handful of narrowly-scoped edits to two files this contract does not otherwise own). The only force that could split it is Chat A's DLR surface shifting materially between this contract's issuance and Slice 2B's actual start (§19's "EXTRA CARE" clause) — if that shift is large enough that the DLR edit itself becomes a multi-file, multi-day undertaking, that specific edit (and only that one) may be sequenced as a fast-follow within the same overall slice rather than blocking the rest of the PR indefinitely; this is not authorized as a default, only as a named escape hatch if §19's re-read at implementation time proves it necessary, and it must be reported as a deliberate, explicit deviation, not a silent split.

---

## 23. Blockers

1. **Chat A must actually merge with the exact migration content quoted in §2**, or Slice 2B implementation stops per §2's explicit predecessor-discrepancy clause.
2. **Slice 1 terminology and Slice 2A navigation implementations must land on `origin/main`** — neither was found there in this pass (§0, §16, §17); their current state is contract-only.
3. **`DLRController.php`'s exact content will have shifted** by the time Chat A actually merges (it is mid-Security-Correction-37) — §19's re-read requirement is not optional ceremony, it is the only way §5's inbound-producer edit stays correct.
4. No blocker was found in this pass regarding schema, authorization primitives, or established patterns to reuse — `WorkspaceManager::userCanAccessBusiness`, the Business Data Tenancy Foundation's migration/backfill pattern, and B1's own resource-scoping pattern are all confirmed present and reusable exactly as designed above.

---

## 24. Readiness

This contract resolves every decision point the reconnaissance left open (backfill algorithm without `LegacyBusinessResolver` modification, Contact display without a new uniqueness prerequisite, block-action Business scoping, the exact `send_by` value, the Dashboard seam's exact signature, the exact route/verb set, the legacy-route disposition, the 2A integration's exact one-entry change) either by direct instruction from this task or by mechanical evidence gathered from current `origin/main` and the two named branches in this pass. No item in §1-§21 is left as "implementation may choose." The three items in §23 are genuine external predecessor dependencies, not open product decisions, and are reported as blockers rather than hidden.
