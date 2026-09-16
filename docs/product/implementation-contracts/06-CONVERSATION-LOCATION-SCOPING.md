# Implementation Contract 06 — Conversation Location Scoping

**Status:** Planning contract only. Does not authorize implementation.

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

**Three, and only three, live `ChatBox`-creation call sites** (confirmed
via `git grep`, excluding the model itself, the historical backfill, and
one dead commented-out line in `app/Models/Campaigns.php:851`):

1. **`app/Http/Controllers/Customer/DLRController.php:942`** — the
   **inbound** path (delivery-receipt/webhook handler). Resolves
   `business_id` from the **receiving phone number**:
   `$phone_number->business_id`, but only if that Business's `customer_id`
   matches the current `$user_id` — otherwise leaves it `NULL` (own
   comment: *"receiving number carries no Business, or one that is not
   this customer's, the conversation stays NULL: kept, and visible from no
   Business route, rather than misfiled"*). This is already the exact
   "incoming number determines [tenancy]" pattern Blueprint §19 wants
   extended to Location — but see the gap below.
2. **`app/Library/Conversations/ConversationHistoryWriter.php:77`**
   (`conversationFor(Business $business, string $businessNumber, string
   $contactNumber)`) — the **outbound managed** path. Takes an
   **already-resolved** `Business` object from its caller; does not itself
   resolve tenancy from the number.
3. **`app/Repositories/Eloquent/EloquentCampaignRepository.php:740`** —
   the **campaign/bulk-send** path. Resolves `business_id` from
   `$input['business_id'] ?? $input['conversation_business_id'] ?? null`
   — i.e., from whatever the caller already supplied, with a documented
   secondary channel (`conversation_business_id`) reserved for inbound
   keyword auto-replies specifically (own comment explains this is read
   "HERE ONLY").

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
each of the three creation sites using the **best currently-available
evidence** (§5's resolution rules), plus a new conservative backfill class
for existing rows.

**Explicitly does NOT change:** `ChatBoxMessage` (no new column — inherits
Location from its parent `ChatBox`, exactly as it already inherits
Business); `business_id`'s own existing resolution logic at any of the
three sites (this slice adds Location resolution **alongside** the
existing Business resolution, never replacing it); `BusinessMessagingNumber`
(this slice does **not** add a Location column there — that is flagged as
a separate, out-of-scope prerequisite for *full* precision, §15).

## 5. Data model contract

**`chat_boxes` — one new column:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `location_id` | `unsignedBigInteger`, FK → `business_locations.id`, nullable | Yes | `NULL` | Mirrors `business_id`'s own nullability and "stays NULL rather than guessed" philosophy exactly. `restrictOnDelete()` — never cascade, matching every other Location FK precedent in this project's own contracts (02, 04) rather than `business_locations.business_id`'s own `cascade` (deliberately different: a conversation's Location attribution is audit-relevant and must not vanish silently). |

**Per-creation-site resolution rule (§4's "prove every path" requirement,
addressed as a table, not an assumption):**

| Site | Business already known? | Location resolution (this slice's rule) |
|---|---|---|
| `DLRController` (inbound) | Yes, from the receiving number's `business_id` (existing) | **Single-Active-Location fallback**: if the resolved Business has exactly one `BusinessLocationLifecycleState::Active` Location, use it; if zero or more than one, `location_id = NULL` (mirrors the existing Business-resolution's own "ambiguous → NULL" discipline, never a guess) |
| `ConversationHistoryWriter::conversationFor()` (outbound managed) | Yes, passed in by the caller | Same single-Active-Location fallback **by default**, but the method signature gains an **optional** `?BusinessLocation $location = null` parameter so a caller that already knows the sending Location (e.g. a future Conversations UI honoring Blueprint §7's Location switcher) can supply it directly rather than relying on the fallback — additive, backward-compatible parameter |
| `EloquentCampaignRepository` (campaign/bulk send) | Yes, from `$input['business_id'] ?? $input['conversation_business_id']` (existing) | Same single-Active-Location fallback; a genuinely multi-Location campaign-targeting concept does not exist yet and is **not invented by this slice** (flagged, §15) |

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
set `location_id` to it; otherwise leave `NULL`. Logs aggregate counts
only (`resolved`/`unresolved`/`ambiguous`), no message content or phone
numbers in any log line, matching the existing class's own explicit
privacy discipline.

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
- `database/migrations/2026_09_2x_100007_add_location_id_to_chat_boxes_table.php`
- `app/Library/Business/Migration/ChatBoxLocationBackfillV1.php`
- `database/migrations/2026_09_2x_100008_backfill_chat_box_location_ids.php` (invokes the class above, mirroring how the existing Business backfill migration invokes `ChatBoxBusinessBackfillV1`)
- `tests/Feature/Conversations/ChatBoxLocationScopingTest.php`
- `tests/Feature/Conversations/ChatBoxLocationBackfillV1Test.php`

**Existing files modified:**
- `app/Models/ChatBox.php` — add `location_id` to `$fillable`, add `location(): BelongsTo(BusinessLocation::class)`.
- `app/Http/Controllers/Customer/DLRController.php` — add the single-Active-Location resolution alongside the existing `business_id` resolution at line ~942.
- `app/Library/Conversations/ConversationHistoryWriter.php` — extend `conversationFor()`'s signature with the optional `?BusinessLocation $location` parameter and the fallback resolution.
- `app/Repositories/Eloquent/EloquentCampaignRepository.php` — add the same fallback resolution at line ~740.

**No `ChatBoxMessage` file changed. No migration to `business_messaging_numbers`.**

## 13. Required tests

`ChatBoxLocationScopingTest.php`: each of the three creation sites, single-
Active-Location Business → `location_id` set correctly; multi-Location
Business → `location_id` stays `NULL`; zero-Location Business (edge case,
should not normally occur per Blueprint §6's Primary-Location-at-signup
rule, but tested defensively) → `NULL`. `ConversationHistoryWriter`'s new
optional parameter: explicit Location passed → used directly, bypassing
the fallback.

`ChatBoxLocationBackfillV1Test.php`: mirrors the structure a
`ChatBoxBusinessBackfillV1` test would have (single-Location resolves,
multi-Location leaves `NULL`, idempotent rerun touches zero already-
resolved rows, aggregate-only logging — no content/number in log output,
asserted).

## 14. Acceptance criteria

1. Every new `ChatBox` row created through any of the three sites gets a
   correctly-resolved (or correctly-`NULL`) `location_id`, proven by test
   per site.
2. Backfill resolves every single-Active-Location historical row and
   leaves every ambiguous one `NULL`, proven by test.
3. Zero existing test's behavior changes.
4. `git diff --check` clean; diff matches §12's allowlist.

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
4. Re-read the full contract, especially SS3's three-creation-site
   evidence and SS5's per-site resolution table.
5. Re-inspect the actual current state of ChatBox, ChatBoxMessage,
   ChatBoxBusinessBackfillV1, DLRController's inbound handler,
   ConversationHistoryWriter::conversationFor(), and
   EloquentCampaignRepository's chatbox-creation block -- confirm all
   three creation sites this contract found are still exactly three, and
   that no fourth site has been added since. If you find a fourth site,
   or any of the three has materially changed, STOP and report before
   proceeding.

Implement exactly the scope in this contract: the new nullable column,
the single-Active-Location fallback at all three sites (never a guess on
ambiguity -- leave NULL, matching the existing business_id precedent
exactly), the new backfill class mirroring ChatBoxBusinessBackfillV1's
structure, and ConversationHistoryWriter's new optional parameter. Do NOT
add a location_id or equivalent to ChatBoxMessage. Do NOT add a Location
column to BusinessMessagingNumber -- that is explicitly out of scope. Do
NOT wire any controller's authorization to this column (Contract 08B).

After implementing:
- Run the new focused test files for this slice.
- Run the broader Conversations-domain regression if one exists.
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
