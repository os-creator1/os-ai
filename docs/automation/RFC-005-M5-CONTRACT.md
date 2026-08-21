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

**Revision note (fourth refinement pass):** independent review found the
prior pass's Twilio-outcome classification factually wrong — the literal
string `'Rejected'` never actually reaches `quickSend()`, because
`sendPlainSMS()`'s own shared post-processing collapses `customer_status`
to exactly `'Delivered'`/`'Failed'` before returning, erasing the
distinction entirely. No existing field can safely separate a genuine
provider rejection from a caught, potentially-ambiguous exception. This
pass authorizes the smallest bounded production change
(`app/Models/SendCampaignSMS.php`, M5-only, opt-in) to make that
distinction explicit, and separately tightens the unique-constraint catch
so it cannot mask an unrelated DB error. Still pre-merge refinement — no
correction round is consumed.

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
- **Audit discipline, this pass:** `app/Models/SendCampaignSMS.php`
  lines 71-13429 (the full `sendPlainSMS()` boundary) re-read
  end-to-end, specifically the two Twilio/TwilioCopilot case blocks
  (lines 457-520, confirmed the *only* two occurrences of
  `SendingServer::TYPE_TWILIO`/`TYPE_TWILIOCOPILOT` inside this
  method's own boundary) together with the shared post-processing at
  lines 13379-13424 (`$cost`, `$customer_status` normalization,
  `Reports::create()`, and the return). `UsageWalletManager::reserve()`'s
  proposed catch block re-read against the independent review's own
  "don't swallow an unrelated unique-constraint failure" requirement.

---

## 1. This contract's own exact file scope

Exactly one file: `docs/automation/RFC-005-M5-CONTRACT.md`.

---

## 2. Preflight — verified

Unchanged: `origin/main` at `24fd1730e535d2360bb3a6fef7caf97f3272457c`.

---

## 3. Mandatory repository audit — findings

### 3.1–3.4a

Unchanged from the prior pass, except `ReservationResult`'s default
value is corrected below (§3.4a).

### 3.4a `reserve()`'s atomic claim — corrected: default must be `false`, and the catch must verify before treating a race as idempotent success

**Two corrections this pass, both defensive tightenings of an already-
sound direction, not a redesign.**

**First — the constructor default was wrong.** The prior pass wrote
`public bool $createdByThisInvocation = true` and left the three
existing wallet-capacity-denial return sites (lines 271/275/279,
`granted: false`) relying on that default, reasoning the field would
"never be inspected" when `granted` is `false`. On reflection this is
exactly the kind of ambiguity the independent review is right to close
even when a bug can't yet reach it: a `true` default paired with
`granted: false` is misleading on its own terms. **Corrected:**

```php
final readonly class ReservationResult
{
    public function __construct(
        public bool $granted,
        public ?int $reservationId,
        public ?string $denialReason,
        public bool $createdByThisInvocation = false,
    ) {
    }
}
```

Locked semantics, exactly as instructed:

- **Authorized, newly-created reservation** (the sole winner of the
  unique-key insert): `createdByThisInvocation = true`, passed
  explicitly at `reserve()`'s successful-creation return site.
- **Authorized, pre-existing/race-lost reservation** (the fast pre-check
  hit, or the new catch-and-refetch branch below): `createdByThisInvocation = false`,
  passed explicitly for clarity even though it now matches the
  corrected default.
- **Denied/no reservation** (`granted: false`, any of the three existing
  wallet-capacity denial sites): `createdByThisInvocation = false` — now
  correct by the corrected default, with no call-site change needed at
  those three sites, since they never pass a fourth argument.

**Second — the catch must verify before declaring idempotent success,
not swallow any unique-constraint failure unconditionally.** The prior
pass's catch block re-fetched by idempotency key and returned it
unconditionally on catching `UniqueConstraintViolationException`. This
does not prove the *specific* violation was the reservation's own
`idempotency_key` collision — a transaction can, in principle, touch
more than one unique-constrained insert, and blindly treating *any*
unique-constraint failure inside `reserve()`'s transaction as "someone
else already reserved this" would mask a genuinely unrelated bug.
**Corrected sequence:**

```php
try {
    $result = DB::transaction(function () use (...) { /* unchanged body */ });
} catch (UniqueConstraintViolationException $exception) {
    $existing = $this->reservationRepository->findByIdempotencyKey($idempotencyKey);

    if ($existing === null) {
        throw $exception; // not this reservation's own key collision — do not mask it
    }

    return new ReservationResult(true, $existing->id, null, false);
}
```

Only when the re-fetch **finds** a row matching the exact expected
`idempotency_key` is the exception treated as a race the losing
invocation should converge on. Any other unique-constraint violation
propagates unchanged, exactly as it would today without this catch
existing at all.

Everything else about §3.4a (the unique-index-enforced atomic claim
itself, the withdrawal of the timestamp heuristic, the
`UsageWalletManager`/`ReservationResult` paths this requires) is
unchanged from the prior pass.

### 3.5 Twilio/TwilioCopilot outcome classification — corrected: no existing field distinguishes rejection from ambiguity

**The prior pass's claim — that `$data->status === 'Rejected'`
mechanically identifies the caught-exception path — is withdrawn as
factually incorrect.** Direct end-to-end re-read of `sendPlainSMS()`
confirms the independent review's finding exactly:

- Inside the catch (lines 483-486, 516-519, unchanged from every prior
  pass's own reading): `$get_sms_status = $e->getMessage(); $customer_status = 'Rejected';`.
- **But this is not the end of the method.** After the entire gateway
  `switch ($gateway_name)` block closes, shared post-processing runs
  (lines 13379-13398, confirmed the *only* such block within
  `sendPlainSMS()`'s boundary):
  ```php
  $cost = substr_count($get_sms_status, 'Delivered') == 1 ? $data['cost'] : '0';
  if (! isset($customer_status)) {
      $customer_status = $get_sms_status;
  }
  $customer_status = substr_count($customer_status, 'Delivered') == 1 ? 'Delivered' : 'Failed';
  $reportsData = [
      ...
      'status' => $get_sms_status,
      'customer_status' => $customer_status,
      ...
  ];
  $status = Reports::create($reportsData);
  ```
  **This final line unconditionally collapses `$customer_status` to
  exactly `'Delivered'` or `'Failed'` — the caught-exception path's
  `'Rejected'` value is overwritten to `'Failed'` here, never reaching
  the returned `Reports` row as `'Rejected'` at all.** `quickSend()`
  itself reads `$data->status` (mapped from the *raw*, unmangled
  `$get_sms_status`, per `'status' => $get_sms_status` above) — for the
  caught-exception path, that is `$e->getMessage()`, an arbitrary,
  provider/SDK-dependent string with no proven consistent shape across
  every possible exception. **Neither `$data->status` nor
  `$data->customer_status` can safely distinguish "Twilio's API
  genuinely rejected this" from "the local code caught an exception of
  uncertain origin" — the prior pass's string-heuristic instinct (a
  `'|'`-delimiter check, floated but not adopted) is correctly rejected
  by the independent review, and is not adopted here either, for
  exactly the reason given: neither the provider-reference format nor
  the full space of exception-message text has been proven to support
  it.**

**Conclusion: no currently-returned or currently-persisted field
provides the required distinction. The smallest bounded production
change is authorized, exactly as the independent review's strong
default specifies.**

**`app/Models/SendCampaignSMS.php` is authorized for modification, M5-
only, opt-in, narrowly scoped to the two Twilio/TwilioCopilot case
blocks plus one shared attachment point:**

- **Input flag, locked name:** `$preparedData['m5_conversations_usage_tracking'] = true;`
  — set only by `EloquentCampaignRepository::quickSend()`'s own new M5-
  guarded branch (§5.1), for a qualifying send, and by no other caller.
- **Inside the two Twilio/TwilioCopilot case blocks (lines 457-520)
  only:** when
  `($data['m5_conversations_usage_tracking'] ?? false) === true`, an
  additional local variable, `$m5Outcome`, is set alongside the
  existing, **completely unchanged** `$get_sms_status`/`$customer_status`
  assignments:
  - Accepted (`queued`/`accepted`, unchanged branch): `$m5Outcome = 'accepted';`.
  - Non-throwing, non-accepted (unchanged branch): `$m5Outcome = 'definitive_rejection';`.
  - Caught exception (unchanged catch body, otherwise untouched):
    `$m5Outcome = 'ambiguous_exception';`.
  When the flag is absent or `false`, **none of this new code executes at
  all** — `$m5Outcome` is simply never set, exactly as today.
- **At the method's existing return point** (immediately after
  `$status = Reports::create($reportsData);`, before `return $status;`,
  line ~13419-13422, unchanged otherwise): `if (isset($m5Outcome)) { $status->m5_outcome = $m5Outcome; }`.
  **This is a non-persisted, dynamic Eloquent attribute** — it is not a
  key of `$reportsData`, so `Reports::create()` neither receives nor
  persists it; setting a property on an already-created model instance
  after the fact has no database effect whatsoever. `quickSend()` reads
  `$data->m5_outcome` only inside its own new M5-guarded branch; the
  property is simply absent (`null`/undefined) for every other caller,
  channel, or gateway, exactly as before this change.

**Proof this satisfies every constraint the independent review named:**

- **No schema change:** `m5_outcome` is never written to `$reportsData`
  and never reaches the `reports` table — confirmed by the exact
  attachment point being *after* `Reports::create()` returns.
- **Legacy `Reports` persistence is completely unchanged:** every key in
  `$reportsData` (`status`, `customer_status`, `cost`, etc.) is built by
  code this contract does not touch; the new lines add a variable and a
  post-creation property assignment, nothing more.
- **Every non-M5 caller sees exactly its current behavior:** the new
  code is gated entirely behind
  `$data['m5_conversations_usage_tracking'] ?? false`, which is `false`
  for bulk Quick Send, API sends, contact-triggered sends, DLR replies,
  and every other existing caller of `sendPlainSMS()` — none of them is
  modified to pass this key, so the added branches are dead code for
  them, provably by the same guard-clause discipline already applied
  throughout this contract (§7).
- **Only the M5 qualifying path consumes the marker:** `quickSend()`'s
  new branch is the only reader of `$data->m5_outcome` anywhere in the
  repository.

**Locked outcome semantics, exactly as instructed:**

| `m5_outcome` | Action |
|---|---|
| `accepted` | `commit($reservation->reservationId, (string) $sms_count)` |
| `definitive_rejection` | `release($reservation->reservationId)`; client regenerates token |
| `ambiguous_exception` | leave `Pending`; do **not** call `release()`; do **not** retry the provider with the same token; client retains token; eligible for §6.2's manual resolver or the existing TTL backstop |

**The durable provider reference (`$get_response->sid`) is preserved
exactly as before** — the new code adds `$m5Outcome`, it does not alter
`$get_sms_status`'s own existing `'Delivered|'.$sid` / `$status.'|'.$sid`
construction, so the `sid` remains embedded in `$data->status` for
`accepted`/`definitive_rejection` outcomes precisely as it is today.

### 3.6 Rate dimension

Unchanged from the prior pass (singular scalar pilot tuple: Business,
country, `SendingServer` id, with the Twilio/TwilioCopilot capability
assertion).

### 3.7 Testability

Unchanged.

---

## 4. Contract status model

Unchanged.

---

## 5. Exact M5 scope

Unchanged (§5.1's six conditions, prior pass).

---

## 6. Reservation lifecycle

For a qualifying send (§5.1), inside `quickSend()`:

1. Guards (§5.1 items 4-6). Any failure → legacy.
2. `EntitlementManager::decide(...)`. Denial → existing error response.
3. `reserve()` with the business-namespaced key (§6.1) and, this pass,
   the corrected `ReservationResult` semantics (§3.4a).
4. Atomic claim check on `$reservation->createdByThisInvocation`
   (unchanged from the prior pass — still the sole authority to proceed,
   still not a timestamp).
5. **`$preparedData['m5_conversations_usage_tracking'] = true;`** set
   immediately before calling `$campaign->sendPlainSMS($preparedData)`
   (new this pass — the one line that activates §3.5's marker logic for
   this call only).
6. Provider send: `$campaign->sendPlainSMS($preparedData)`.
7. **Outcome classification — revised this pass, driven by
   `$data->m5_outcome`, not by any status-string heuristic:**
   - `$data->m5_outcome === 'accepted'` → `commit(...)`.
   - `$data->m5_outcome === 'definitive_rejection'` → `release(...)`;
     client regenerates token.
   - `$data->m5_outcome === 'ambiguous_exception'` → leave `Pending`; no
     `release()`; log the structured warning; client retains token.
   - **Defensive fallback, stated explicitly:** if
     `$data->m5_outcome` is unexpectedly absent (e.g. a future,
     currently-impossible-by-design caller path reached this branch
     without setting the flag) — treat identically to
     `ambiguous_exception`. This can only ever be reached by a
     programming defect elsewhere in this same guarded branch, since the
     flag is always set at step 5 immediately before the call; it is a
     fail-safe, not an expected outcome, and is asserted against directly
     in tests (§13).

No DB transaction remains open across the provider call — unchanged.

### 6.1 Idempotency key

Unchanged.

### 6.2 Manual ambiguous-reservation resolution

Unchanged (six checks, prior pass).

---

## 7. `quickSend()` discriminator

Unchanged, plus the one new line at §6 step 5.

---

## 8. Quantity

Unchanged.

---

## 9. Pricing activation

Unchanged (§9.1, §9.2).

---

## 10. Existing entitlement behavior

Unchanged.

---

## 11. Schema

**Still no new migration, table, or column.** §3.5's `m5_outcome` is a
transient, non-persisted Eloquent property, not a schema element —
confirmed by its attachment point being strictly after
`Reports::create()` returns.

---

## 12. Exact implementation allowlist — REVISED, 16 → 17

### Modify (9)

1. `app/Http/Controllers/Customer/ChatBoxController.php` — unchanged.
2. `app/Repositories/Eloquent/EloquentCampaignRepository.php` — gains,
   in addition to the prior pass's scope, the
   `m5_conversations_usage_tracking` flag set immediately before the
   provider call, and the `$data->m5_outcome`-driven classification
   (§6 step 7), replacing the withdrawn status-string heuristic.
3. `app/Http/Requests/ChatBox/SentRequest.php` — unchanged.
4. `resources/views/customer/ChatBox/new.blade.php` — unchanged.
5. `resources/views/customer/ChatBox/index.blade.php` — unchanged.
6. `config/usage_billing.php` — unchanged (scalar pilot tuple).
7. `app/Library/Usage/UsageWalletManager.php` — `reserve()`'s catch
   block is tightened this pass (§3.4a: re-fetch-then-rethrow-if-not-
   found), in addition to the prior pass's unique-constraint-catch
   addition.
8. `app/Library/Usage/ReservationResult.php` — default value corrected
   to `false` this pass (§3.4a); the field itself is unchanged from the
   prior pass.
9. **New this pass:** `app/Models/SendCampaignSMS.php` — the two
   Twilio/TwilioCopilot case blocks (lines 457-520) gain the
   flag-gated `$m5Outcome` assignment; the method's existing return
   point gains the one-line non-persisted attachment
   (`if (isset($m5Outcome)) { $status->m5_outcome = $m5Outcome; }`).
   No other line in this ~13,440-line method changes; every other
   gateway branch and every other caller is untouched.

### New (8)

10. `app/Console/Commands/ActivateUsageFeatureRate.php` — unchanged.
11. `app/Console/Commands/ResolveAmbiguousUsageReservation.php` —
    unchanged.
12. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`.
13. `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`
    — **scope widened this pass** to include the new
    `SendCampaignSMS.php` regression (§13).
14. `tests/Feature/Usage/ActivateUsageFeatureRateCommandTest.php`.
15. `tests/Feature/Usage/ResolveAmbiguousUsageReservationCommandTest.php`.
16. `tests/Feature/Usage/Support/concurrent_conversations_send_runner.php`.
17. `tests/Feature/Usage/ConversationsConcurrencyTest.php`.

### Read-only dependencies

Unchanged list from the prior pass, plus: `app/Models/Reports.php` (the
model `sendPlainSMS()` already returns — read only, to confirm setting
an unmapped dynamic property is safe and non-persisting; not modified).

**Total mechanically-authorized paths: 17. Stop threshold: 18th path.**
Reported explicitly — up from 16, driven entirely by
`app/Models/SendCampaignSMS.php`'s newly-authorized, narrowly-scoped
addition (§3.5); no other path count changed.

---

## 13. Required tests — reconciled

New/revised:

1. Twilio accepted/queued, M5 flag set → `m5_outcome === 'accepted'` →
   `commit()`.
2. **Revised:** Twilio caught `ConfigurationException`/`TwilioException`,
   M5 flag set → `m5_outcome === 'ambiguous_exception'` → reservation
   remains `Pending`; `release()` is **not** called.
3. Provider double is not invoked on a same-token retry against that
   still-`Pending` reservation (unchanged mechanism, §6 step 4).
4. Genuine non-throwing, non-accepted Twilio response, M5 flag set →
   `m5_outcome === 'definitive_rejection'` → `release()` **is** called.
5. **New this pass:** the identical Twilio caught-exception scenario
   **without** the M5 flag set (any non-qualifying call) → `Reports`'
   persisted `status`/`customer_status` are byte-for-byte identical to
   today's existing behavior, and no `m5_outcome` property exists on the
   returned object — proves §3.5's opt-in isolation directly, not by
   inference.
6. Real concurrent same-token race (two OS processes) → exactly one
   `createdByThisInvocation === true`, exactly one provider invocation
   (unchanged from the prior pass).
7. **New this pass:** an `UniqueConstraintViolationException` thrown for
   a reason *other than* this reservation's own `idempotency_key` (
   simulated by seeding a distinct unique-constraint collision inside a
   test double of the transaction) is **re-thrown**, not swallowed —
   proves §3.4a's re-fetch-then-verify correction.
8. `ReservationResult`'s corrected default: a denial path
   (`granted: false`) is asserted to carry
   `createdByThisInvocation === false`, not the withdrawn `true` default.
9. Every prior pass's own tests, unchanged in substance: `Committed`/
   `Released`/`Expired` retries never call the provider; the exact
   pilot tuple engages metering; a different `SendingServer` id (even
   Twilio-type) stays legacy; a Workspace with more than one Business
   stays legacy; legacy `sms_unit` and the wallet never both charge one
   send; Business isolation including token-namespacing; the five
   non-ChatBox callers unaffected; segment quantity correctness; the
   nine-key/registry regressions; both admin commands' full check sets.

Regression, unchanged: full `Usage`, full `Entitlement`/`Workspace`,
full suite.

---

## 14. Stop conditions

Unchanged from the prior pass, plus:

- An 18th path required beyond §12's seventeen.
- Any evidence that `$m5Outcome`'s post-creation attachment to `$status`
  has any persistence side effect whatsoever (would contradict §3.5's
  own "no schema change" proof and require immediate re-audit).
- Any evidence that a caller other than `quickSend()`'s own M5-guarded
  branch ever sets `m5_conversations_usage_tracking`.

---

## 15. Verification and publication (this document only)

- `git diff --check` clean.
- `git diff origin/main --name-only` shows exactly one path.
- Commit message: `docs: make RFC-005 M5 Twilio ambiguity evidence explicit`,
  a new commit, not an amend.
- Push `chore/rfc-005-m5-contract`. PR #107 remains Draft, unmerged. No
  implementation begins.
