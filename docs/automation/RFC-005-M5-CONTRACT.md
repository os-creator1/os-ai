# RFC-005 Milestone 5 — Metered Feature Classification

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This contract authorizes drafting this one document only.** Merging it
authorizes the bounded M5 implementation this document specifies — to be
made as its own separate, later, explicitly bounded implementation PR.
Merging this document does **not** itself write any `app/`, `database/`,
`routes/`, `config/`, or `resources/` file, does not flip
`Conversations`' classification to `is_metered = true`, does not activate
any real retail/provider rate, does not begin M6 in any way, and does not
authorize any live charge to any real Business.

**Revision note (third refinement pass):** independent review found the
`reserved_at`/`preCallAt` freshness heuristic is not concurrency-safe;
that the Twilio/TwilioCopilot caught-exception path was misclassified as
definitive when it is not; and that gateway-*type* scoping does not pin
the actual price-determining dimension (`sending_server` + `country_id`).
All three are re-audited and resolved below, together with a fourth,
narrower fix to §6.2's manual-resolution command. Still pre-merge
refinement — no correction round is consumed.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m5-contract`, based on `origin/main`
  at `24fd1730e535d2360bb3a6fef7caf97f3272457c` (unchanged).
- **Human product decision, locked:** first metered feature is
  `PlatformFeature::Conversations`.
- `maximum_correction_rounds: 2`, unconsumed.
- Stop threshold is the allowlist's final count **plus one**.
- This refinement makes **zero** application changes — only this
  document is touched.
- **Audit discipline, this pass:** `UsageWalletManager::reserve()` and
  `ReservationResult.php` re-read for the exact transaction/return
  shape; `UsageBillingCheckoutManager::finalizeAddonPurchaseIfPending()`'s
  existing `UniqueConstraintViolationException`-absorption idiom (the
  established precedent this fix reuses, not invents); the exact Twilio
  and TwilioCopilot branches of `SendCampaignSMS::sendPlainSMS()`
  (lines 460-520) re-read character-by-character, together with
  `EloquentCampaignRepository::quickSend()`'s own
  `substr_count($data->status, 'Delivered')` check; `SendingServerBasedPricingPlans`'
  schema (keyed by `sending_server` + `country_id`) re-confirmed. No new
  files were created; no vendor package was newly inspected (the
  `vendor/twilio` absence noted in the prior pass is unchanged and still
  not required for this pass's fix, which relies only on this
  repository's own code).

---

## 1. This contract's own exact file scope

Exactly one file: `docs/automation/RFC-005-M5-CONTRACT.md`.

---

## 2. Preflight — verified

Unchanged: `origin/main` at `24fd1730e535d2360bb3a6fef7caf97f3272457c`.

---

## 3. Mandatory repository audit — findings

### 3.1–3.4a

Unchanged from the prior pass, **except 3.4a is corrected below.**

### 3.4a `reserve()`'s idempotency lookup — corrected: the freshness heuristic is unsound

**The prior pass's design — capturing `$preCallAt = Carbon::now()` before
calling `reserve()`, then comparing it to the returned row's
`reserved_at` to infer "did I create this row" — is withdrawn. It is not
concurrency-safe, exactly as the independent review's counterexample
shows:** two concurrent requests A and B, both carrying the identical
business-namespaced token, both capture a `preCallAt` before either has
written anything; if A's insert commits first, B's subsequent
`reserve()` call (or a repeat pre-check) can return that same row with
a `reserved_at` that is `>=` **both** A's and B's own `preCallAt`
captures, since nothing in the design serializes "capture timestamp" and
"another process's insert" against each other. **Additionally,
`business_usage_reservations.reserved_at` is declared
`$table->timestamp('reserved_at')` with no fractional-second precision**
(confirmed by direct re-read of the migration) — even absent the
ordering counterexample, sub-second concurrent requests could produce
identical stored timestamps, making strict inequality comparison
unreliable on its own terms.

**Correct mechanism: an atomic database fact, not a timestamp
comparison.** `business_usage_reservations.idempotency_key` already
carries a `unique` constraint (confirmed, unchanged). The established
precedent for turning a unique-constraint race into an exactly-once
claim already exists in this codebase —
`UsageBillingCheckoutManager::finalizeAddonPurchaseIfPending()` catches
`Illuminate\Database\UniqueConstraintViolationException` around a
racing `creditFromFunding()` ledger insert and treats the caught
exception as proof a concurrent/earlier caller already won. **`reserve()`
is widened to apply the identical idiom to its own reservation insert:**

- Inside `reserve()`'s existing `DB::transaction()` closure, the
  reservation `create()` call (line 284, unchanged position) is wrapped
  so that a `UniqueConstraintViolationException` on that specific insert
  propagates out of the closure. `DB::transaction()`'s own existing
  rollback behavior (unchanged Laravel semantics) then undoes
  *everything* this losing invocation did inside that transaction —
  including its own wallet-balance debit — before the exception reaches
  `reserve()`'s own code.
- `reserve()` catches `UniqueConstraintViolationException` around the
  `DB::transaction(...)` call itself (not inside the closure — the
  closure must be allowed to fully roll back first). On catch, it
  performs a **fresh** `findByIdempotencyKey()` lookup (guaranteed, by
  MySQL's own unique-index locking behavior, to now find the winning
  row — a losing insert can only ever fail with a duplicate-key error
  once the winning row is already committed and visible, whether the
  loser's insert blocked on the index lock first or failed immediately)
  and returns that row's id.
- **`ReservationResult` (`app/Library/Usage/ReservationResult.php`)
  gains one new field, added as a fourth, defaulted constructor
  parameter — confirmed backward compatible by direct search: every
  existing `new ReservationResult(...)` call site (five total, all
  inside `UsageWalletManager.php`, none elsewhere in the repository)
  uses purely positional arguments and none supplies a fourth value
  today, so appending one defaulted parameter changes no existing call
  site's behavior:**
  ```php
  final readonly class ReservationResult
  {
      public function __construct(
          public bool $granted,
          public ?int $reservationId,
          public ?string $denialReason,
          public bool $createdByThisInvocation = true,
      ) {
      }
  }
  ```
  `reserve()`'s five existing return sites are updated: the pre-
  transaction existing-found short-circuit (line 238) and the new
  post-catch existing-found path both pass `false`; the three denial
  returns (lines 271/275/279) are unaffected in substance (the field is
  meaningless when `granted` is `false`, so it is left at its default
  for clarity, never inspected by any caller in that branch); the
  successful-fresh-creation return (line 335) passes `true`.

**This directly satisfies every requirement the independent review
listed:** only the invocation that atomically wins the unique-key insert
ever learns `createdByThisInvocation === true`, and every other
concurrent or later invocation — whether it hit the fast pre-check or
raced into and lost the insert — learns `false`; a genuinely fresh
`Pending` row is only ever produced by exactly one invocation; no
transaction remains open across the provider call (the transaction
fully closes, one way or the other, before `reserve()` even returns —
unchanged from every prior pass); and the mechanism is the database's
own unique-index enforcement, not application-level timestamp
inference.

### 3.5 Twilio/TwilioCopilot outcome classification — corrected: the caught exception does not propagate

**The prior pass's rule ("thrown exception = ambiguous, non-throwing =
evaluate the status") is corrected. It rested on an inaccurate premise:
re-reading `SendCampaignSMS::sendPlainSMS()`'s Twilio and TwilioCopilot
branches (lines 468-486, 501-519) character-by-character confirms the
`catch (ConfigurationException|TwilioException $e)` block does **not**
rethrow — it assigns `$get_sms_status = $e->getMessage(); $customer_status = 'Rejected';`
and falls through to `break;`. `quickSend()` therefore never receives a
PHP exception from this path at all — it receives an ordinary return
value (eventually the `Reports` row `quickSend()` reads via
`$data->status`, per the existing `substr_count($data->status, 'Delivered')`
check, unchanged) whose status happens to be exactly the literal string
`'Rejected'`.**

**Re-audit of what other value that same field can hold, for this
gateway specifically:** the non-throwing, real-response branch (lines
475-481) sets `$customer_status = ucfirst($get_response->status)` when
`$get_response->status` is not `queued`/`accepted` — a value drawn
directly from Twilio's own Messages resource status vocabulary
(`queued`, `sending`, `sent`, `delivered`, `undelivered`, `failed`,
`accepted`, `receiving`, `received` — Twilio's documented status set
never includes a status literally named "rejected"). **This means, for
this specific gateway, the exact string `'Rejected'` can only ever
originate from the catch block — never from a genuine Twilio API
response** — a mechanical, repository-evidenced distinction, not an
assumption about exception internals (which the prior pass correctly
declined to assume, and still does not need to).

**Locked classification, exactly per the independent review's own
strong default, now confirmed rather than merely proposed:**

- `$data->status === 'Rejected'` (exact match) for a Twilio/TwilioCopilot-
  resolved send → **ambiguous.** Keep the reservation `Pending`. Do not
  call `release()`. Do not retry the provider with the same token
  (unchanged — governed by §6's `createdByThisInvocation` check, not by
  this classification). Eligible for §6.2's manual resolver or the
  existing TTL backstop.
- A non-`'Delivered'`-containing, non-`'Rejected'` status (a genuine
  `ucfirst($get_response->status)` value, always carrying the durable
  `sid`, per §3.5 of the prior pass) → **definitive non-delivery.**
  `release()` immediately, exactly as the prior pass specified for this
  sub-case.
- `'Delivered|<sid>'` → durable success → `commit()`, unchanged.

**No modification to `app/Models/SendCampaignSMS.php` is required or
authorized.** The distinction is fully mechanical from data
`quickSend()` already receives today, given this gateway's own confirmed
status vocabulary — this is a correction to the *classification rule*
`quickSend()`'s new M5 branch applies to existing data, not a change to
`SendCampaignSMS.php` itself.

### 3.6 Rate dimension — corrected: gateway *type* does not pin price; the exact `SendingServer` id does

**Confirmed, re-reading `SendingServerBasedPricingPlans`' schema
(migration `2023_05_21_113335_...`'s sibling, and its read site at
`quickSend()` line 210): the table is keyed by `sending_server` (a
specific row id) + `country_id` — not by gateway type.** Two different
`SendingServer` rows can both be configured with `settings = TYPE_TWILIO`
while carrying two different `SendingServerBasedPricingPlans` rows (and
therefore two different `plain_sms` prices) for the identical country.
`ChatBoxController::sent()` additionally permits the customer to select
a specific `sending_server` explicitly (`$request->sending_server`,
confirmed in the controller's existing code). **The prior pass's
`authorized_gateway_types` config therefore bounded a safety/capability
property (which code branch executes, relevant to §3.5's classification
rule), not the pricing identity — these are two independent concerns
that were incorrectly conflated into one config key.**

**Corrected pilot design — one singular, scalar, fail-closed tuple, not
three independent arrays, per the independent review's own explicit
preference against an accidental Cartesian product:**

```php
// config/usage_billing.php
'conversations_metering' => [
    'pilot_business_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_BUSINESS_ID'),
    'pilot_country_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_COUNTRY_ID'),
    'pilot_sending_server_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_SENDING_SERVER_ID'),
],
```

Each is a **single nullable scalar**, `null` by default (fail-closed —
no default invented, matching the file's own existing convention). A
qualifying send now requires the resolved Business id, destination
`country_id`, and resolved `SendingServer` id to **exactly equal all
three configured values simultaneously** — one tuple, not a membership
test against any list. **The resolved `SendingServer` is additionally,
separately, asserted to be `TYPE_TWILIO`/`TYPE_TWILIOCOPILOT`** — this
is not a fourth pilot dimension, it is a code-level capability
assertion tied to §3.5's classification rule (which is only proven
correct for this one gateway family); if a human ever pointed
`pilot_sending_server_id` at a non-Twilio row, this assertion fails
closed rather than silently applying an unverified classification rule
to a different gateway's response shape.

**M5 supports exactly one pilot tuple, deliberately, per the
independent review's own steer ("the smaller M5 design is one tuple").**
No mechanism for multiple tuples is built. A future milestone wanting a
second pilot (or a general rollout) would need either to prove, out-of-
band, that a second tuple's real economics are identical to the first
(unlikely, since price is precisely the thing that varies per tuple) or
to build the real per-dimension schema extension this contract has
twice already named as out of scope.

**Stated exactly, per the independent review's explicit instruction:**
once the pilot tuple is activated (`is_metered = true` **and** all three
config values populated **and** a real rate set), the RFC-005
`business_usage_rates` active rate is the sole, authoritative charge for
a qualifying send matching that exact tuple. **Legacy `sms_unit` is not
also deducted for that send** — unchanged from every prior pass's own
"exactly one authoritative charging path" framing, now restated against
the corrected, precise tuple definition.

`provider_cost_micro` remains entirely human-supplied, unchanged from
the prior pass — the legacy `options` blob is retail-only and proves
nothing about real provider cost for any tuple.

### 3.7 Testability

Unchanged.

---

## 4. Contract status model

Unchanged.

---

## 5. Exact M5 scope

### 5.1 What qualifies as an M5-metered plain SMS send

1. `$input['sms_type']` resolves to the `plain` branch.
2. The call originated from `ChatBoxController::sent()`/`::reply()`
   (`conversation_context`, §7).
3. `platform_feature_usage_classifications.conversations.is_metered`
   is `true`.
4. The sending Customer's Workspace owns exactly one Business, **and**
   that Business's id equals `conversations_metering.pilot_business_id`
   exactly (not list membership — §3.6).
5. The resolved destination `country_id` equals
   `conversations_metering.pilot_country_id` exactly.
6. The resolved `SendingServer`'s id equals
   `conversations_metering.pilot_sending_server_id` exactly, **and**
   that server's `settings` is `TYPE_TWILIO`/`TYPE_TWILIOCOPILOT`
   (§3.6's capability assertion).

Any send failing any one condition takes the exact same legacy
`sms_unit` code path unconditionally.

### 5.2–5.4

Unchanged in substance.

---

## 6. Reservation lifecycle — exact, locked order, atomic claim

For a qualifying send (§5.1), inside `quickSend()`:

1. Business/ownership/pilot-tuple guards (§5.1 items 4-6). Any failure
   → fall through to unmodified legacy code.
2. `EntitlementManager::decide(...)`. Denial → existing error response.
3. `$reservation = $walletManager->reserve($business, PlatformFeature::Conversations->value, $idempotencyKey, (string) $sms_count);`
   using the business-namespaced key (§6.1, unchanged derivation).
   Wallet-capacity denial → existing error response, no provider call.
4. **Revised — the atomic claim check, replacing the withdrawn
   `preCallAt`/`reserved_at` comparison entirely:**
   ```php
   if (! $reservation->createdByThisInvocation) {
       $row = app(BusinessUsageReservationRepository::class)->findById($reservation->reservationId);
       return match ($row->status) {
           UsageReservationStatus::Committed => /* existing "already sent" success response */,
           UsageReservationStatus::Released, UsageReservationStatus::Expired => /* existing "could not confirm" response; client must regenerate token */,
           UsageReservationStatus::Pending => /* existing "still processing" response; client retains token; log the same structured warning as before */,
       };
   }
   ```
   **Only when `$reservation->createdByThisInvocation === true`** does
   control proceed to step 5 — this is the exact, sole authority to call
   the provider, decided by the database's own unique-constraint
   enforcement (§3.4a), not by any timestamp.
5. **Provider send:** `$campaign->sendPlainSMS($preparedData)`.
6. **Outcome classification (§3.5, corrected this pass):**
   - `$data->status` contains `'Delivered'` → `commit($reservation->reservationId, (string) $sms_count)`.
   - `$data->status === 'Rejected'` (Twilio/TwilioCopilot's caught-
     exception literal, §3.5) → **ambiguous.** Do not `release()`. Leave
     `Pending`. Log the structured warning. Client retains token.
   - Any other status (a genuine, evidenced non-accepted provider
     response) → **definitive.** `release($reservation->reservationId)`.
     Client regenerates token.

**No DB transaction remains open across the provider call** — `reserve()`'s
own transaction (including the new catch-and-refetch branch) fully
closes before step 4 is even evaluated; unchanged property, now
mechanically guaranteed rather than merely asserted, since the atomic
claim itself depends on that transaction having already committed or
rolled back.

**Concurrency requirement, stated exactly:** two genuinely concurrent
processes supplying the identical business-namespaced token produce
exactly one reservation (the database's unique constraint admits only
one), exactly one `createdByThisInvocation === true` result (by
construction — only the winner of the unique-insert race gets `true`),
and therefore exactly one provider invocation and exactly one
accounting outcome — the loser always takes the step-4 non-provider-
call branch, deterministically, regardless of timing.

### 6.1 Idempotency key

Unchanged: business-namespaced derivation
(`hash('sha256', 'conversations_plain_sms:'.$business->id.':'.$token)`),
client-sourced token (session-persisted for `sent()`, JS-held for
`reply()`), retain-on-ambiguous/regenerate-on-resolved rules — now
triggered by §6's `createdByThisInvocation`-driven state machine and
§3.5's corrected outcome classification, rather than by the withdrawn
timestamp heuristic.

### 6.2 Manual ambiguous-reservation resolution — narrowed, cannot become a generic mutation tool

`app/Console/Commands/ResolveAmbiguousUsageReservation.php`:
`usage:resolve-reservation {reservation-id} {--outcome=} {--actor-user-id=} {--reason=}`.
**Before calling `commit()` or `release()`, the command must verify, in
order, and perform zero mutation if any check fails:**

1. The reservation exists (`findById()` returns non-null).
2. Its `status` is exactly `Pending` — never `Committed` (already
   resolved, nothing to do) and never `Released`/`Expired` (already
   terminal, nothing to do).
3. Its `feature_key` is exactly `PlatformFeature::Conversations->value`
   — this command is a `Conversations`-pilot-specific tool, not a
   general reservation-mutation command; it must refuse any other
   feature's reservation outright.
4. The supplied `--actor-user-id` resolves to a genuine platform
   administrator (the same `is_admin` check as §9.1, reproduced not
   modified).
5. `--reason` is supplied and non-empty.
6. The reservation's own persisted `business_id` equals the
   *currently-configured* `conversations_metering.pilot_business_id`
   (§3.6) — provable directly from persisted data plus current config,
   without needing country/sending-server columns on the reservation
   row itself, since `quickSend()`'s own guard (§5.1) is the *only*
   code path that ever creates a `conversations`-feature_key
   reservation at all — a row satisfying checks 3 and 6 together is
   mechanically proven to have originated from the M5 pilot path.

Any failure at any of the six checks aborts with a clear message and
writes nothing. Only when all six pass does the command call
`commit($reservationId)` (for `--outcome=sent`) or
`release($reservationId)` (for `--outcome=not-sent`) — zero new
`UsageWalletManager` code, unchanged from the prior pass.

---

## 7. `quickSend()` discriminator

Unchanged from the second refinement pass (the `reply()`/`sent()`
token-propagation correction stands).

---

## 8. Quantity

Unchanged.

---

## 9. Pricing activation

### 9.1 The command and actor-authority mechanism

Unchanged.

### 9.2 Unresolved human decisions

1. Numeric rate for the one pilot tuple (§3.6) — unchanged framing,
   `provider_cost_micro` still never inferred from legacy data.
2. **Revised:** the three scalar pilot values —
   `pilot_business_id`, `pilot_country_id`, `pilot_sending_server_id` —
   each `null`/fail-closed by default.
3. Lifecycle unchanged: implementation may begin and be fully tested
   against fixture-only values; `Conversations.is_metered` and all
   three pilot config values must remain unset in any real environment
   until a human has supplied all of them and separately run §9.1's
   command.

---

## 10. Existing entitlement behavior

Unchanged.

---

## 11. Schema

**Still no new migration, table, or column.** `ReservationResult`
(§3.4a) is a plain PHP value object, not a schema element — its one new
defaulted field requires no migration.

---

## 12. Exact implementation allowlist — REVISED, expanded from 12 to 16

### Modify (8)

1. `app/Http/Controllers/Customer/ChatBoxController.php` — unchanged
   scope from the second refinement pass.
2. `app/Repositories/Eloquent/EloquentCampaignRepository.php` —
   `quickSend()`'s guard chain now checks the scalar pilot tuple (§5.1
   items 4-6) instead of list membership; the state machine (§6) is
   driven by `$reservation->createdByThisInvocation`, not a timestamp
   comparison; the outcome classification (§6 step 6) uses the exact
   `'Rejected'`-string rule (§3.5).
3. `app/Http/Requests/ChatBox/SentRequest.php` — unchanged.
4. `resources/views/customer/ChatBox/new.blade.php` — unchanged.
5. `resources/views/customer/ChatBox/index.blade.php` — unchanged.
6. `config/usage_billing.php` — **corrected this pass:** three scalar
   nullable keys (`pilot_business_id`/`pilot_country_id`/
   `pilot_sending_server_id`), replacing the prior pass's three array
   keys.
7. **New this pass:** `app/Library/Usage/UsageWalletManager.php` —
   `reserve()` gains the `UniqueConstraintViolationException`-catch-
   and-refetch logic (§3.4a), reusing the exact existing idiom from
   `UsageBillingCheckoutManager::finalizeAddonPurchaseIfPending()`. No
   other method on this class changes; every other milestone's own use
   of `reserve()`/`commit()`/`release()` is unaffected (the new fourth
   `ReservationResult` field is additive/defaulted and ignored by every
   caller that does not read it).
8. **New this pass:** `app/Library/Usage/ReservationResult.php` — one
   new defaulted constructor parameter, `createdByThisInvocation`
   (§3.4a). Confirmed backward compatible against all five existing
   call sites, all internal to `UsageWalletManager.php`.

### New (8)

9. `app/Console/Commands/ActivateUsageFeatureRate.php` (§9.1,
   unchanged).
10. `app/Console/Commands/ResolveAmbiguousUsageReservation.php` (§6.2,
    now with the six explicit safety checks).
11. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`.
12. `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`.
13. `tests/Feature/Usage/ActivateUsageFeatureRateCommandTest.php`.
14. `tests/Feature/Usage/ResolveAmbiguousUsageReservationCommandTest.php`
    (now covering all six safety checks, §13).
15. **New this pass:** `tests/Feature/Usage/Support/concurrent_conversations_send_runner.php`
    — a real cross-process test-support runner, modeled directly on the
    already-merged RFC-005 M4 precedent
    (`tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php`'s
    own bootstrap/database-guard/exit-code shape), needed because
    proving a genuine concurrent-process race (§13) requires two real
    OS processes, which single-process PHPUnit execution cannot produce
    on its own.
16. **New this pass:** `tests/Feature/Usage/ConversationsConcurrencyTest.php`
    — the PHPUnit test file driving item 15's runner, kept as its own
    dedicated file rather than folded into item 11, matching the
    already-established "no hidden path" convention from the prior
    refinement pass.

### Read-only dependencies (expanded)

- `Illuminate\Database\UniqueConstraintViolationException` (framework
  class, already used elsewhere in this codebase for the identical
  purpose)
- `app/Repositories/Contracts/BusinessUsageReservationRepository.php`
  (`findById()`, unchanged)
- `app/Library/Usage/UsageBillingCheckoutManager.php` (read only, to
  confirm the exact precedent idiom this pass reuses — not modified)
- `app/Models/SendingServer.php` (`TYPE_TWILIO`/`TYPE_TWILIOCOPILOT`
  constants; `sending_server_based_pricing_plans` schema, read only)
- Every other read-only dependency named in the prior two passes,
  unchanged.

**Total mechanically-authorized paths: 16. Stop threshold: 17th path.**
Reported explicitly — an increase from 12, driven by: the
`UsageWalletManager`/`ReservationResult` widening the independent review
itself authorized in advance (§3.4a), and the real cross-process
concurrency test infrastructure (2 files) the required concurrency test
(§13) mechanically needs, matching the already-established M4 precedent
for proving the identical class of claim.

---

## 13. Required tests — reconciled

New/revised:

1. **Real concurrent same-token race** (two genuine OS processes, via
   item 15/16's runner) → exactly one reservation row, exactly one
   `createdByThisInvocation === true` result, exactly one provider
   invocation (asserted via a recording fake/double shared across the
   two processes, modeled on the M4 barrier-gateway precedent's own
   shared-file recording technique), exactly one accounting outcome.
2. **Same-key second caller arriving after the first reservation's
   creation but before its completion** → zero second provider
   invocation (the losing process's `createdByThisInvocation` is
   `false`; it takes the step-4 branch, never step 5).
3. **No timestamp-based freshness heuristic remains** — a direct
   assertion (e.g. a static/reflection check, or simply the absence of
   any `reserved_at`/`preCallAt` comparison in the shipped code path)
   that the claim decision is driven solely by `createdByThisInvocation`.
4. Twilio caught-exception (`customer_status === 'Rejected'`, simulated
   via the test double raising the exact caught exception) →
   reservation remains `Pending`, `release()` is **not** called.
5. Retry against an already-`Committed` reservation → provider not
   called.
6. Retry against a still-`Pending`, pre-existing reservation
   (`createdByThisInvocation === false`, status `Pending`) → provider
   not called.
7. Retry against a terminal `Released`/`Expired` reservation → provider
   not called; client must regenerate token.
8. The exact pilot tuple (Business + country + `SendingServer` id, that
   server confirmed Twilio-type) engages RFC-005 metering.
9. The same Business and country, but a **different** `SendingServer`
   id (even if also Twilio-type) → stays legacy (proves §3.6's
   sending-server-id pinning, not merely gateway-type membership).
10. An out-of-pilot Business or country (tuple mismatch on any single
    dimension) → stays legacy.
11. Legacy `sms_unit` and the RFC-005 wallet never both charge one send.
12. Plain SMS segment quantity correctness (unchanged).
13. Non-M5 channels and the five non-ChatBox `quickSend()` callers
    unaffected (unchanged).
14. Business isolation, including cross-Business token-namespacing
    (unchanged).
15. A Workspace owning more than one Business stays legacy (unchanged).
16. `EntitlementManagerNineKeySurfaceUnchangedTest`/
    `PlatformFeatureRegistryTest` re-run unmodified.
17. `ActivateUsageFeatureRateCommandTest` (unchanged).
18. `ResolveAmbiguousUsageReservationCommandTest` — **now explicitly
    covering all six §6.2 checks:** rejects a nonexistent reservation
    id; rejects a non-`Pending` reservation (`Committed` and
    `Released` cases each tested); rejects a non-`Conversations`
    `feature_key` reservation (proving the command cannot be used as a
    generic wallet-mutation tool); rejects a missing/non-admin
    `--actor-user-id`; rejects an empty `--reason`; rejects a
    reservation whose `business_id` does not match the currently-
    configured pilot Business id; only the fully-valid case mutates.

Regression, unchanged: full `Usage`, full `Entitlement`/`Workspace`,
full suite.

---

## 14. Stop conditions

Unchanged from the prior pass, plus:

- A 17th path required beyond §12's sixteen.
- Any evidence that `ReservationResult`'s new fourth field breaks an
  existing caller not accounted for in §3.4a's five-call-site count.
- Any evidence that a gateway other than Twilio/TwilioCopilot is ever
  selected as `pilot_sending_server_id` without the §5.1 item 6
  capability assertion catching it first.

---

## 15. Verification and publication (this document only)

- `git diff --check` clean.
- `git diff origin/main --name-only` shows exactly one path.
- Commit message: `docs: make RFC-005 M5 provider claim concurrency-safe`,
  a new commit, not an amend.
- Push `chore/rfc-005-m5-contract`. PR #107 remains Draft, unmerged. No
  implementation begins.
