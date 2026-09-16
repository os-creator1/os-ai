# Implementation Contract 06 — Conversation Location Scoping

**Status:** Planning contract, corrected on the implementation branch
(`agent/v1-slice-06-conversation-location-scoping`) after a mechanical
re-trace found a fourth live `ChatBox` creation path the original survey
missed. The correction is factual — the repository survey (§3), the
per-site rules (§5), the allowlist (§12), the tests (§13), the acceptance
criteria (§14) and the implementation prompt (§18) now describe **four**
live creation paths. Scope is otherwise unchanged.

## 1. Objective

Make `ChatBox` (the Conversation entity) Location-bound (Blueprint §11,
Addendum §5), mirroring exactly how it is already Business-bound today
(`business_id`, nullable, resolved per-producer, backfilled
conservatively) — **not** merely adding a `location_id` column, but
tracing and fixing every live creation path, per the deep-dive
requirement.

## 2. Governing authority

- Blueprint §11 (Conversations), §5 (Location-bound operational records).
- Addendum §5.
- Roadmap Slice 6.
- Navigation Contract §11.2 (the original finding that flagged this gap —
  confirmed still substantively true below, with one correction: `ChatBox`
  already has `business_id`, contrary to that contract's "user-scoped, not
  Business-scoped" framing, which appears to predate the
  `ChatBoxBusinessBackfillV1` work; only Location-scoping is genuinely
  missing).

## 3. Current repository reality

**`app/Models/ChatBox.php`** (full relevant portion read): `business_id`
is a **nullable** fillable column, with its own explicit docblock rule:
*"a historical conversation whose Business cannot be proven stays NULL and
is reachable from no Business route. Messages inherit tenancy from this
row and carry no business_id of their own."* This is the **exact
precedent shape** this slice's `location_id` column follows — nullable,
resolved conservatively, inherited by messages rather than duplicated onto
them.

**`app/Models/ChatBoxMessage.php`**: no `business_id`, confirming the
inheritance rule above; this slice adds **no** column to this model.

**`app/Library/Business/Migration/ChatBoxBusinessBackfillV1.php`** (read):
the exact backfill template to mirror — evidence-based resolution order
(1: Contact evidence via matching phone number, resolved only if exactly
one distinct Business; 2: single-Business-owner fallback, only when step 1
found zero evidence; 3: otherwise `NULL`), idempotent (scoped to `WHERE
business_id IS NULL`), immutable once shipped (a correction is a new V2
class, never an edit), and explicitly logs aggregate counts only — no
phone number, name, or message content in any log line.

**Four, and only four, live `ChatBox`-creation call sites** (re-confirmed
mechanically at implementation time, excluding the model itself, the
historical backfill, one dead commented-out line in
`app/Models/Campaigns.php:851`, and `database/seeders/ChatBoxSeeder.php`,
which is not registered in `DatabaseSeeder` and is not a runtime writer):

> **Correction (implementation branch).** This section previously claimed
> *three, and only three* live creation sites. That survey was incomplete
> **when it was written** — site 4 below has existed since the July
> baseline commit and was present at this contract's own commit. It is a
> raw query-builder `insert`, which a `ChatBox::`-shaped `git grep` does
> not surface. Sites 1–3 are unchanged and were re-verified byte-identical
> at implementation time; site 3's *description* is corrected below.

1. **`app/Http/Controllers/Customer/DLRController.php:942`** — the
   **inbound** path (delivery-receipt/webhook handler). Resolves
   `business_id` from the **receiving phone number**:
   `$phone_number->business_id`, but only if that Business's `customer_id`
   matches the current `$user_id` — otherwise leaves it `NULL` (own
   comment: *"receiving number carries no Business, or one that is not
   this customer's, the conversation stays NULL: kept, and visible from no
   Business route, rather than misfiled"*). `NULL` is therefore a real and
   ordinary outcome here, not an edge case. The row is inserted by
   `$chatBox->save()` (line ~955). This is already the exact "incoming
   number determines [tenancy]" pattern Blueprint §19 wants extended to
   Location — but see the gap below.
2. **`app/Library/Conversations/ConversationHistoryWriter.php:77`**
   (`conversationFor(Business $business, string $businessNumber, string
   $contactNumber)`) — the **managed** path. Takes an **already-resolved**
   `Business` object from its caller; does not itself resolve tenancy from
   the number. **`conversationFor()` does not persist anything**: it
   returns an existing or a *prepared, unsaved* `ChatBox` (it mints the
   `uid` only when the model is new). The two real persistence points are
   later in the same class:
   - `recordManagedInbound()` — `$conversation->save()` (line ~113);
   - `recordManagedOutbound()` — `$conversation->save()` (line ~222),
     inside that method's own `DB::transaction()`, on the model returned by
     the private `resolveConversationForWrite()` (line ~495), which either
     re-loads an existing box by id **within the same Business** or
     delegates to `conversationFor()`.
   Any `location_id` this slice sets must therefore be set **on the model
   `conversationFor()` returns**, so that both save points persist it.
3. **`app/Repositories/Eloquent/EloquentCampaignRepository.php:740`** —
   the **Business-aware `quickSend()` two-way conversation path** (inside
   `public function quickSend(Campaigns $campaign, array $input, bool
   $conversationContext = false)`, guarded by the two-way/`phone_number`
   originator branch at ~714; inserted by `$chatbox->save()` at ~759).
   **Correction:** this contract previously called this block "the
   campaign/bulk-send path". It is not — the campaign/bulk path is site 4.
   Resolves `business_id` from `$input['business_id'] ??
   $input['conversation_business_id'] ?? null` — i.e., from whatever the
   caller already supplied, with a documented secondary channel
   (`conversation_business_id`) reserved for inbound keyword auto-replies
   specifically (own comment explains this is read "HERE ONLY"). A caller
   that supplies neither keys on `business_id IS NULL`.
4. **`app/Repositories/Eloquent/EloquentCampaignRepository.php:1481`** —
   the **legacy Agency AI-Prospecting `campaignBuilder()` path**: a raw
   `DB::table('chat_boxes')->insertGetId([...])`, one row per subscribed
   contact, inside `public function campaignBuilder(Campaigns $campaign,
   array $input)`. It runs **only** on the `if ($outreachBusinessId ===
   null)` branch (~1452) — i.e. only when the caller supplied **no**
   Business at all (`CampaignController::tenantSafeInput()` strips
   `business_id`, so every controller call through it takes this branch).
   The insert writes `uid`, `user_id`, `to`, `from`, `ai_stage`,
   timestamps — and **no `business_id`**, so these rows are `business_id
   = NULL` by construction. It is live: reached from
   `CampaignController@campaignBuilder` call sites behind the
   `POST /campaign-builder` routes. Because it is a query-builder insert it
   bypasses `$fillable`, casts and model events entirely.

**Critical gap found, not assumed (deep-dive requirement):**
`app/Models/BusinessMessagingNumber.php` (full relevant portion read) —
the managed-number model — has **no Location column at all**; it keys
only on `business_messaging_identity_id` (Business-level). Blueprint
§19's "incoming number determines Location" therefore **cannot be
achieved with full precision by this slice alone** — the number itself
isn't Location-scoped yet. This is a real, separate gap this contract
narrows around rather than silently papers over (§4, §15).

## 4. Delta from current state to target

**Changes:** `ChatBox` gains a nullable `location_id` column, resolved at
each of the **four** creation sites using the **best currently-available
evidence** (§5's resolution rules), plus a new conservative backfill class
for existing rows. At site 4 the only honest resolution is an explicit
`NULL`, written deliberately rather than by omission (§5).

**Explicitly does NOT change:** `ChatBoxMessage` (no new column — inherits
Location from its parent `ChatBox`, exactly as it already inherits
Business); `business_id`'s own existing resolution logic at any of the
four sites (this slice adds Location resolution **alongside** the
existing Business resolution, never replacing it — in particular site 4
keeps writing no `business_id`, and this slice does **not** port,
re-architect or "fix" the legacy Agency AI-Prospecting path);
`BusinessMessagingNumber` (this slice does **not** add a Location column
there — that is flagged as a separate, out-of-scope prerequisite for
*full* precision, §15).

## 5. Data model contract

**`chat_boxes` — one new column:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `location_id` | `unsignedBigInteger`, FK → `business_locations.id`, nullable | Yes | `NULL` | Mirrors `business_id`'s own nullability and "stays NULL rather than guessed" philosophy exactly. `restrictOnDelete()` — never cascade, matching every other Location FK precedent in this project's own contracts (02, 04) rather than `business_locations.business_id`'s own `cascade` (deliberately different: a conversation's Location attribution is audit-relevant and must not vanish silently). |

**Per-creation-site resolution rule (§4's "prove every path" requirement,
addressed as a table, not an assumption):**

| Site | Business already known? | Location resolution (this slice's rule) |
|---|---|---|
| 1. `DLRController` (inbound) | **Sometimes** — from the receiving number's `business_id`, and only when that Business belongs to this customer; otherwise `NULL` (existing rule, unchanged) | **Single-Active-Location fallback**, applied only when a Business was actually resolved: if that Business has exactly one `BusinessLocationLifecycleState::Active` Location, use it; if zero or more than one — or if `business_id` is `NULL` — `location_id = NULL` (mirrors the existing Business-resolution's own "ambiguous → NULL" discipline, never a guess) |
| 2. `ConversationHistoryWriter::conversationFor()` (managed inbound + outbound) | Yes, passed in by the caller | Same single-Active-Location fallback **by default**, but the method signature gains an **optional** `?BusinessLocation $location = null` parameter so a caller that already knows the sending Location (e.g. a future Conversations UI honoring Blueprint §7's Location switcher) can supply it directly rather than relying on the fallback — additive, backward-compatible parameter. A supplied Location belonging to another Business, or one that is not `Active`, is **ignored** (falls back), never written. The value is set on the prepared model, so **both** persistence points (`recordManagedInbound()`'s save and `recordManagedOutbound()`'s in-transaction save) store it |
| 3. `EloquentCampaignRepository::quickSend()` (Business-aware two-way conversation) | Only when the caller supplied one: `$input['business_id'] ?? $input['conversation_business_id'] ?? null` (existing) | Same single-Active-Location fallback when a Business **is** supplied; when none is supplied the row keys on `business_id IS NULL` and `location_id` is `NULL` too. A Business is **never** derived merely to obtain a Location. A genuinely multi-Location campaign-targeting concept does not exist yet and is **not invented by this slice** (flagged, §15) |
| 4. `EloquentCampaignRepository::campaignBuilder()` (legacy Agency AI-Prospecting raw insert) | **No — by construction.** The branch runs only while `$outreachBusinessId === null`, and the insert writes no `business_id` | **`business_id = NULL` and `location_id = NULL`, written explicitly.** There is no Business to resolve a Location from, and none may be invented: not the user's primary Business, not a Business derived from the contact or its group, not a Location borrowed from another Business the user happens to own, not a campaign Business that is absent on this branch. The insert gains a literal `'location_id' => null` so the intent is visible at the write site rather than implied by omission. This is an intentional transitional legacy path; the column is nullable precisely so unprovable legacy rows stay unattributed instead of being misfiled |

**Presence of *possible* Businesses is not evidence.** At sites 1, 3 and 4
alike, a customer owning one Business — or several, or one marked primary —
never causes a conversation to acquire a Business or a Location it cannot
prove. This is the same discipline `ChatBoxBusinessBackfillV1` already
applies to historical rows, restated here for live writes.

**Why "single-Active-Location fallback" and not a hard requirement to
resolve the true originating number's Location:** because
`BusinessMessagingNumber` has no Location column (§3's flagged gap), there
is **no evidence anywhere in the system today** for which Location a
multi-Location Business's specific number belongs to. For the common case
this slice actually needs to serve well — Core/Growth Businesses (single
Location today, and Client Workspaces from Contract 07, also single-
Location by construction) — the single-Active-Location fallback resolves
**correctly and unambiguously** every time. For a genuinely multi-Location
Business, `location_id` conservatively stays `NULL` (matching the existing
Business-resolution precedent's own ambiguity handling) until a future,
separately-scoped contract adds Location-scoping to
`BusinessMessagingNumber` itself — this is the honest, evidence-based
boundary of what this slice alone can achieve, not a shortcut taken
silently.

## 6. Authority / security contract

Not applicable in the usual actor-permission sense — this slice adds no
new actor-facing action, only a new attribution column resolved
server-side at write time. The relevant "authority" concern is Contract
02/08B's downstream: once Location ACL is wired into Conversations
(Contract 08B), a `Selected`-scope staff member's visibility into a
Conversation will depend on this column being correctly populated — this
slice's accuracy is a **security-relevant prerequisite** for Contract 08B,
even though this slice itself performs no authorization check.

## 7. Transaction / concurrency boundary

No new locking need — `location_id` resolution happens inline with the
existing `ChatBox::firstOrNew()`/`save()` calls at each site, using the
same transaction boundary each site already has (not widened or narrowed
by this slice).

## 8. Migration / backfill

**New class `ChatBoxLocationBackfillV1`**, mirroring
`ChatBoxBusinessBackfillV1`'s exact shape and philosophy: immutable once
shipped (a correction is a new V2 class); idempotent, scoped to `WHERE
location_id IS NULL`; chunked (`CHUNK_SIZE = 500`, matching the existing
class's own constant); resolution rule: for every `ChatBox` row with a
non-null `business_id`, if that Business has exactly one Active Location,
set `location_id` to it; otherwise leave `NULL`. A row whose `business_id`
is `NULL` — including every legacy Agency AI-Prospecting row from site 4 —
is **never** resolved: with no Business there is no evidence, and the
backfill counts it as unresolved rather than guessing. Logs aggregate counts
only (`resolved`/`unresolved`/`ambiguous`), no message content or phone
numbers in any log line, matching the existing class's own explicit
privacy discipline.

`ambiguous` means the row's Business exists but has **two or more** Active
Locations; `unresolved` is every row left `NULL` for any reason (no
Business, no Active Location, or ambiguity).

**Preflight/dry-run/stop condition:** not required at the severity this
contract's other migration-heavy slices (10, 12, 13) need — this is a
low-risk, purely additive, NULL-safe attribution backfill with no money or
tenancy-authorization consequence on its own (Contract 08B is where
consequence begins). A simple `--dry-run` flag reporting the three counts
without writing is still recommended, matching this codebase's general
caution, but no human stop-and-review gate is required the way Contract
10's payer matrix needs one.

## 9. Backwards compatibility

Every existing `ChatBox` row with `location_id = NULL` behaves exactly as
it does today in every code path that doesn't yet consume `location_id`
(everything except Contract 08B, not yet built). No existing test should
need to change.

## 10. Events / audit

No new domain event — `location_id` is ordinary attribution data on an
existing row, not a state transition. No audit trail beyond the row itself
is needed, matching `business_id`'s own precedent (no dedicated event
exists for a `ChatBox`'s Business attribution either).

## 11. Billing/provider safety

Not applicable — this slice touches no payer, wallet, cap, or provider
call. (STOP/DND enforcement, mentioned in Blueprint §11/§19, is entirely
independent of `location_id` and is not touched by this slice.)

## 12. Exact implementation allowlist

**New files:**
- `database/migrations/2026_09_20_100007_add_location_id_to_chat_boxes_table.php` (dated after the latest migration on main at implementation time)
- `app/Library/Business/Migration/ChatBoxLocationBackfillV1.php`
- `database/migrations/2026_09_20_100008_backfill_chat_box_location_ids.php` (invokes the class above, mirroring how the existing Business backfill migration invokes `ChatBoxBusinessBackfillV1`)
- `tests/Feature/Conversations/ChatBoxLocationScopingTest.php`
- `tests/Feature/Conversations/ChatBoxLocationBackfillV1Test.php`

**Existing files modified:**
- `app/Models/ChatBox.php` — add `location_id` to `$fillable`, add `location(): BelongsTo(BusinessLocation::class)`, and add the **one canonical implementation** of the single-Active-Location rule as a static helper (`singleActiveLocationIdFor(?int $businessId): ?int`) so all three Business-aware live sites resolve identically instead of carrying three copies. This mirrors the existing precedent of a shared static helper used by live writers (`ChatBoxBusinessBackfillV1::normalizeCounterparty()`, called by this same model).
- `app/Http/Controllers/Customer/DLRController.php` — site 1: add the single-Active-Location resolution alongside the existing `business_id` resolution at line ~942.
- `app/Library/Conversations/ConversationHistoryWriter.php` — site 2: extend `conversationFor()`'s signature with the optional `?BusinessLocation $location` parameter and the fallback resolution, setting `location_id` on the prepared model **before** it is returned, so both persistence points (`recordManagedInbound()` ~113 and `recordManagedOutbound()` ~222) save it.
- `app/Repositories/Eloquent/EloquentCampaignRepository.php` — site 3 (`quickSend()`, ~740): the same fallback resolution. Site 4 (`campaignBuilder()`, ~1481): an explicit `'location_id' => null` in the raw insert, with a comment recording why nothing may be resolved there. No new production file is needed for site 4 — the file is already allowlisted.

**No `ChatBoxMessage` file changed. No migration to `business_messaging_numbers`. No change to the legacy AI-Prospecting path beyond the explicit NULL column and its comment.**

## 13. Required tests

`ChatBoxLocationScopingTest.php`: each of the **four** creation sites,
each driven through its **real production entry point**
(`DLRController::inboundDLR()`, `recordManagedInbound()` /
`recordManagedOutbound()`, `quickSend()`, `campaignBuilder()`) — never a
test-side copy of the writer's code, which would stay green if the
production change were reverted. Only the external provider may be stubbed.

- **Site 1 — `DLRController` inbound:** single-Active-Location Business →
  `location_id` set; multi-Location Business → `NULL`; zero-Location
  Business (edge case, should not normally occur per Blueprint §6's
  Primary-Location-at-signup rule, but tested defensively) → `NULL`; a
  receiving number whose Business is not this customer's → both
  `business_id` and `location_id` `NULL`.
- **Site 2 — `ConversationHistoryWriter`:** the fallback applies, and the
  value **survives both real persistence paths** — one test through
  `recordManagedInbound()` and one through `recordManagedOutbound()`,
  asserting the saved row (not just the prepared model). The new optional
  parameter: an explicit Location is used directly, bypassing the
  fallback (proven by passing a Location of a Business that has several,
  where the fallback would yield `NULL`); a Location belonging to another
  Business, or an archived Location of the same Business, is ignored
  rather than written.
- **Site 3 — `quickSend()`:** known Business + exactly one Active Location
  → assigned; known Business + zero or several Active Locations → `NULL`;
  no Business supplied → `business_id` and `location_id` both `NULL`.
- **Site 4 — legacy `campaignBuilder()` AI-Prospecting branch:** with
  `$outreachBusinessId === null`, the created rows have `business_id`
  `NULL` **and** `location_id` `NULL`; the rows are still created normally
  and AI-stage/campaign-mapping behaviour is unchanged (`ai_stage = 1`,
  `ai_box_campaign_map` rows written as before). Crucially, this holds
  when the acting user owns exactly one Business with exactly one Active
  Location, when the user owns several Businesses, and when one of them is
  primary — presence of a resolvable Business elsewhere must not leak into
  this legacy conversation.

`ChatBoxLocationBackfillV1Test.php`: mirrors the structure a
`ChatBoxBusinessBackfillV1` test would have (single-Location resolves,
multi-Location leaves `NULL` and counts as ambiguous, a `business_id IS
NULL` legacy row stays `NULL`, an archived-only Location resolves to
`NULL`, an already-resolved row is never overwritten, idempotent rerun
touches zero already-resolved rows, aggregate-only logging — no
content/number in log output, asserted).

## 14. Acceptance criteria

1. Every new `ChatBox` row created through any of the **four** live sites
   gets a correctly-resolved (or correctly-`NULL`) `location_id`, proven by
   test per site — including site 4, where the correct answer is always
   `NULL`.
2. Backfill resolves every single-Active-Location historical row and
   leaves every ambiguous one — and every `business_id IS NULL` one —
   `NULL`, proven by test.
3. Zero existing test's behavior changes; no behaviour changes anywhere
   except Location attribution.
4. `git diff --check` clean; diff matches §12's allowlist.
5. A mechanical re-trace after implementation accounts for **exactly**
   these four live creation paths; any fifth is a stop-and-report, and
   dead/commented/seeder-only paths are documented as such rather than
   counted as live writers.

## 15. Non-goals

Does **not** add a Location column to `BusinessMessagingNumber` — flagged
as a genuine, separate gap (§3) that full "incoming number determines
Location" precision eventually needs, but which is not one of the 14
priority slices this contract factory was asked to produce. Does not wire
Location ACL into any Conversations controller (Contract 08B). Does not
build multi-Location campaign targeting. Does not touch STOP/DND logic.

## 16. Merge prerequisites

None beyond Roadmap Wave 0. Independent of Contracts 01–05 — safe
concurrent.

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contracts 01–05 | none | Safe concurrent |
| Contract 08B (Location ACL consumer wiring) | consumes this slice's `location_id` column for Conversations | **Serialize** — hard dependency, and benefits from this slice landing first per the Roadmap's own note |

## 18. Implementation prompt

```
You are implementing Slice 6 of the V1 architecture migration for the
os-creator1/os-ai repository: Conversation (ChatBox) Location scoping, per
docs/product/implementation-contracts/06-CONVERSATION-LOCATION-SCOPING.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify this contract is reachable (on main or its source branch -- if
   not, STOP and report).
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-06-conversation-location-scoping).
4. Re-read the full contract, especially SS3's four-creation-site
   evidence and SS5's per-site resolution table.
5. Re-inspect the actual current state of ChatBox, ChatBoxMessage,
   ChatBoxBusinessBackfillV1, DLRController's inbound handler,
   ConversationHistoryWriter::conversationFor() and its two persistence
   points, EloquentCampaignRepository::quickSend()'s chatbox block, and
   EloquentCampaignRepository::campaignBuilder()'s raw
   DB::table('chat_boxes')->insertGetId() -- confirm the creation sites
   are still exactly these four. Trace by DATA SHAPE (raw query-builder
   inserts included), not only by "ChatBox::" grep: the original survey
   missed site 4 precisely because it is a raw insert. If you find a
   fifth site, or any of the four has materially changed, STOP and report
   before proceeding.

Implement exactly the scope in this contract: the new nullable column,
the single-Active-Location fallback at the three Business-aware sites
(never a guess on ambiguity -- leave NULL, matching the existing
business_id precedent exactly), an explicit NULL location_id at the legacy
AI-Prospecting site, the new backfill class mirroring
ChatBoxBusinessBackfillV1's structure, and ConversationHistoryWriter's new
optional parameter. Do NOT add a location_id or equivalent to
ChatBoxMessage. Do NOT add a Location column to BusinessMessagingNumber --
that is explicitly out of scope. Do NOT wire any controller's
authorization to this column (Contract 08B). Do NOT port, re-architect or
otherwise "fix" the legacy Agency AI-Prospecting path, and do not make
business_id mandatory anywhere.

After implementing:
- Run the new focused test files for this slice, including the legacy
  site-4 cases (business_id NULL and location_id NULL, with a
  single-Business, multi-Business and primary-Business user alike).
- Re-run the mechanical creation-site trace and confirm exactly four.
- Run the broader Conversations-domain and Campaign regression if one exists.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, and the backfill's resolved/unresolved/ambiguous counts
from your test run. Do NOT begin or authorize Contract 08B or any other
later slice.
```
