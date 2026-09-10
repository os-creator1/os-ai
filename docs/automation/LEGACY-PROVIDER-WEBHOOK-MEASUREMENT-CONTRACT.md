# LEGACY PROVIDER WEBHOOK SURFACE — S0 MEASUREMENT AND S1 HYGIENE CONTRACT

**Status:** Implementation contract. It authorizes exactly two slices, S0 and
S1, and nothing else. **It does not authorize provider retirement.**

**Base:** `origin/main` at `b87b669d55de97957d3583407ec2b69cd75d2eaa`.

**Concurrent lane:** `agent/customer-experience-slice-3-messaging-provider-implementation`.
Chat A owns that branch. Nothing here modifies it, duplicates it, or depends on
its internals.

**Chat A head is deliberately not pinned.** Correction Round 1 observed it at
`122f3301f235dad35c1e457afdfa8d5ca097bb95` ("docs(messaging): reconcile the
allowlist and record Security Correction 36"), advanced from the
`9dd47b3744d18f98f0c065ac2de91fae10790f9e` recorded when this contract was
first written, and Chat A is now entering Security Correction 37. Those SHAs
are historical evidence of what was inspected, **not a dependency**. S0/S1
implementation waits for the **final merged Slice 3 tree on `main`**, never for
any particular pre-merge Chat A SHA — see §7.

**Evidence:** the completed *Legacy Messaging Provider Surface Retention
Audit*, re-verified mechanically against `main` before this contract was
written and again at Correction Round 1 (§2).

---

## CORRECTION ROUND 1 — TERMINABLE-MIDDLEWARE PORTABILITY AND THE STALE-MIGRATION GATE

Two defects were found in the first revision. Both are recorded here rather
than silently folded in, because one retracts a claim and the other retracts
implementation guidance.

| # | What was wrong | What it now says | Where |
|---|---|---|---|
| **C1** | `terminate()` was described as a **structural guarantee** that the response "has already been sent" and that measurement therefore "cannot delay" the callback. That is not portable: whether bytes have reached the client before terminable work runs depends on the SAPI, web server and deployment, and under FastCGI the flush behaviour is a deployment characteristic, not a framework contract | Terminable middleware stays the **preferred** implementation, but the locked rule is now the honest one: telemetry runs only after the controller has produced its `Response`, never changes status/headers/body, swallows and reports every exception, and never changes callback semantics. Acceptance language is **"response semantics are invariant"**, never "measurement can never delay the provider under every runtime." Zero added client latency is explicitly **not** a portable correctness assumption, and is explicitly **not** an exit criterion. Because latency is not guaranteed away by the mechanism, the recorder's **bounded shape** is named as the substantive protection and is now separately testable (assertion 6a) | §3.6, §3.4 failure-isolation, §10 assertions 6 and 6a, §11 |
| **C2** | The migration section named `2026_09_11_120004_*` as "the latest migration on main", implying an implementation timestamp need only sort after it. But implementation is blocked until Chat A merges, and Slice 3 already carries six later migrations (`2026_09_12_100001`–`100006`), so that guidance would have produced an interleaved or colliding timestamp | The old value is retained **only as historical evidence of this contract's base** and is explicitly not implementation guidance. The timestamp is now chosen at implementation start by fetching and reading the actual latest merged migration from the merged tree. Renaming, re-timestamping or colliding with a Chat A migration is prohibited outright | §3.4, §7 |

Additionally: the observed Chat A head is updated and, more importantly,
**de-pinned** — the dependency is the final merged Slice 3 tree, not any
pre-merge SHA (§7) — and it is now stated explicitly that no S0/S1
implementation may begin from this contract branch.

**Everything else is unchanged**, and deliberately so: no retirement
authorization, the 30-day minimum observation window, the aggregate-only table,
the prohibition on payload/phone/header/hash storage, the refusal to infer a
tenant, the code-backed route registry, GatewayAPI-duplicate-only for S1,
managed Telnyx excluded by default, the operator-only safe report, and S2–S5
recorded but unauthorized.

---

## 1. WHAT THIS CONTRACT DOES AND DOES NOT AUTHORIZE

The retention audit concluded that the public webhook surface could safely
shrink to Twilio and Telnyx plus a small explicit supported set. **That
conclusion is not acted on here.** Shrinking the supported provider set is a
product decision with commercial and support consequences, and it belongs to
the owner.

This contract owns only the two predecessor steps that are worth doing
*regardless of how the owner eventually decides*:

| Slice | Scope |
|---|---|
| **S0** | Passive, bounded usage measurement of the legacy public inbound/DLR route family |
| **S1** | Deletion of exactly one proven shadowed duplicate route declaration |

**Explicitly not authorized:** deletion or disabling of any provider route
other than the S1 duplicate; any change to the provider catalog; any change to
outbound dispatch; any signature work; any change to `DLRController` handler
behaviour; any schema change beyond S0's single measurement table.

If, during implementation, it appears that S0 cannot be built without changing
handler behaviour, **stop and report** rather than widening scope. That
outcome is a legitimate result, not a failure.

---

## 2. EVIDENCE, RE-VERIFIED

Re-verified against `b87b669d` immediately before writing this contract. The
audit's figures are unchanged; PR #236 (the only advance since the audit)
touched theme assets, CSS, fonts and `package.json` — **zero provider or
webhook surface**.

| Fact | Value | How verified |
|---|---|---|
| Routes in `routes/public.php` | 99 | `grep -cE '^\s*Route::'` |
| `inbound*` / `dlr*` routes | 85 | filtered count |
| Named `inbound.*` / `dlr.*` routes | 80 | unique `->name()` extraction |
| Distinct provider slugs | 70 (69 provider-named + `webhook`) | unique slug extraction |
| `DLRController` public methods | 85 | `grep -c 'public function '` |
| Callback routes the platform itself hands to providers | **12 of 80** | `route('dlr.*')`/`route('inbound.*')` references in `SendCampaignSMS` and `EloquentSendingServerRepository` |
| Named routes never referenced by outbound dispatch | **68 of 80** | set difference of the two above |
| Customer-selectable providers | **2** (Twilio, Telnyx) | `ALLOWED_PROVIDERS` in `MessagingChannelsController` and `AgencyProspectingChannelController` |
| Tests on main exercising any legacy inbound/DLR route | **0** | repository-wide grep |

**The measurement gap this slice closes.** For 68 of 80 routes the callback URL
can only have been configured by hand in a provider's own dashboard. The
repository holds no record of whether any of them receives traffic, and
**cannot** determine it from source. S0 converts an unanswerable question into
measured evidence.

**Duplicate confirmed still present on current main:**

```
routes/public.php:50  Route::any('inbound/gatewayapi/{gateway?}', ...)->name('inbound.gatewayapi');
routes/public.php:52  Route::any('inbound/gatewayapi/{gateway?}', ...)->name('inbound.gatewayapi');
```

Identical URI, identical verb set, identical controller method, identical route
name. Laravel's later registration wins, so line 50 is unreachable. Chat A's
branch carries the same duplicate (at lines 66 and 68) and does not touch it.

---

## 3. S0 — MEASUREMENT

### 3.1 What is recorded

Exactly four facts per observed route:

* which route was hit (route **name**, authoritative)
* which provider slug that route represents (from a code-backed map)
* first seen
* last seen
* hit count

Nothing else.

### 3.2 What is prohibited, absolutely

This is **usage telemetry, not request surveillance.** The following must not
be stored, hashed, logged, or derived, in any column, log line, or exception
context:

* request body, in whole or in part
* message content of any kind
* any phone number — source, destination, or otherwise
* any address field
* IP address
* authorization headers, or any header
* signatures, tokens, credentials, API keys
* webhook body hashes, or any other digest of request content that could
  become a stable identifier for a person or a message

**On body hashes specifically.** The audit's Slice 0 rejection-recorder
precedent hashes payloads for de-duplication. That justification does not
transfer here: S0 aggregates by `(route_name, provider_slug)`, which needs no
payload discriminator at all. A hash would add a stable per-message identifier
for no measurement benefit, so it is prohibited outright rather than left to
implementer judgement.

### 3.3 Tenant correlation — contracted as OMITTED, with reasoning

The task permits optional `last_resolved_business_id` /
`last_resolved_sending_server_id` **only where the canonical handler has
already resolved them unambiguously, and only if mechanically safe.**

**Mechanical finding: neither condition holds today, so both fields are
omitted from S0.**

* The middleware cannot observe what a handler resolved. `DLRController`
  exposes no resolution to the request, and there is no shared seam carrying
  it. Making one would mean editing the ~85 handler methods this contract
  exists to avoid touching.
* The only tenant signals available at middleware level come from the request
  itself — `From`, `To`, a `{gateway}` segment — and every one of those is
  attacker-controlled on an unauthenticated endpoint. Recording them would be
  exactly the inference this contract forbids.

Adding nullable columns that S0 never writes would be speculative schema. **The
table therefore ships without them.** If tenant correlation is later wanted, it
requires its own additive migration *and* a deliberate handler change, and is
recorded in §9 as future work. The operator report (§6) supplies the tenant
dimension instead, from `SendingServer` rows the platform already owns —
which is authoritative, and needs no request-derived inference.

### 3.4 Persistence

One new table. Aggregate counters only — **no per-request event rows**, since
aggregates fully satisfy the measurement goal and per-request rows would
reintroduce a retention and privacy surface.

```
legacy_webhook_route_usage
  id                bigint unsigned, primary key
  route_name        string(191)   NOT NULL
  provider_slug     string(64)    NOT NULL
  hit_count         bigint unsigned NOT NULL default 0
  first_seen_at     timestamp     NOT NULL
  last_seen_at      timestamp     NOT NULL
  created_at        timestamp     nullable
  updated_at        timestamp     nullable

  UNIQUE (route_name, provider_slug)   -- name: lwru_route_provider_unique
  INDEX  (last_seen_at)                -- name: lwru_last_seen_index
```

**Deliberately absent:** any payload column, any headers JSON, any body hash,
any address column, any IP column, any free-form metadata column. The table has
no column capable of holding personal data. That is a **schema-level**
guarantee — a column that does not exist cannot be written — and it is the one
place in this contract where the word "structural" is used literally. It says
nothing about runtime timing; see §3.6's corrected position on what
`terminate()` does and does not promise.

**This is not a generic analytics or event table** and must not be presented as
one. It is single-purpose, and §9's retirement slice is expected to drop it
once the decision it exists to inform has been taken.

**Migration ordering — the timestamp is chosen at implementation, never from
this document.**

`2026_09_11_120004_*` was the latest migration on `main` when this contract was
written. **That value is historical evidence of this contract's base and is
explicitly not implementation guidance.** Implementation is blocked until Chat
A's Slice 3 merges (§7), and Slice 3 already carries six later migrations —
`2026_09_12_100001` through `2026_09_12_100006` — so a timestamp chosen merely
to sort after the old `main` value would interleave with, or collide against,
migrations that will already be merged by then.

Binding rule at implementation start:

1. `git fetch origin`.
2. Identify the **actual latest merged migration** on `main` at that moment, by
   listing `database/migrations/` on the merged tree — not from this document,
   and not from memory.
3. Choose a new unique timestamp strictly after it.

**Never rename, re-timestamp, move or collide with a Chat A migration**, merged
or unmerged. If the chosen timestamp turns out to collide, pick a later one;
do not adjust anyone else's file.

`down()` drops the table. Additive only; no existing table is altered.

### 3.5 Atomicity and concurrency

Increments must be atomic at the database, never a read-modify-write.

The precedent already in this repository is
`app/Library/GoogleBusinessProfile/GoogleBusinessProfileCallBudget.php:113`:
`DB::table(...)->where(...)->increment(...)`. Follow it.

Required shape:

1. Attempt an atomic conditional update: `UPDATE ... SET hit_count =
   hit_count + 1, last_seen_at = NOW() WHERE route_name = ? AND provider_slug = ?`.
2. If it affected zero rows, insert the row with `hit_count = 1` and both
   timestamps set.
3. If that insert loses a race, catch `UniqueConstraintViolationException` and
   retry step 1 exactly once.

`first_seen_at` is written on insert and **never updated afterwards**.

**No transaction may wrap handler execution.** The recorder's own write may use
a short transaction of its own if the implementer judges it necessary, but it
must not enclose, delay, or share a transaction with the provider callback's
processing.

### 3.6 Instrumentation — the narrowest integration

**Do not edit the ~85 controller methods. Do not restructure `routes/public.php`
into groups.** Both were considered and rejected: the first is an 85-file-region
change with 85 chances to alter behaviour, and the second moves every legacy
route line, which maximises conflict with Chat A.

**Contracted approach — one middleware, one registration line, one map.**

| Component | Path | Change |
|---|---|---|
| Recorder middleware | `app/Http/Middleware/RecordLegacyWebhookUsage.php` | **New.** Records usage in `terminate()` |
| Route → slug map | `app/Library/Messaging/LegacyWebhookRouteRegistry.php` | **New.** The single code-backed source of truth for which routes are measured and what slug each represents |
| Registration | `app/Providers/RouteServiceProvider.php` | **One line.** Append the middleware to the `routes/public.php` group: `Route::middleware(['web', RecordLegacyWebhookUsage::class])` |
| Model | `app/Models/LegacyWebhookRouteUsage.php` | **New** |
| Migration | `database/migrations/<ts>_create_legacy_webhook_route_usage_table.php` | **New** |

**Why `terminate()` and not `handle()` — and what it does and does not
guarantee.** Terminable middleware remains the **preferred implementation**,
because it runs after the controller has produced its `Response` and so cannot
participate in producing it.

**Corrected in Correction Round 1.** An earlier revision claimed the response
"has already been sent" and that measurement therefore "cannot delay" the
callback, calling this a structural guarantee. **That claim was too strong.**
Whether bytes have actually reached the client before `terminate()` runs
depends on the SAPI, the web server and the deployment — under FastCGI,
Laravel and PHP may flush the response before terminable work completes, but
that behaviour is a property of the deployment, not of the framework contract,
and it must not be relied on as portable.

The honest rule this contract locks instead:

* Telemetry executes **only after the controller has produced its Response**.
* Telemetry **never changes status, headers or body**.
* Every telemetry exception is swallowed and reported safely.
* **No telemetry failure changes provider callback semantics.**
* On FastCGI deployments the response may be flushed before terminable work
  completes — a deployment characteristic, not a guarantee this contract makes.
* **Zero added client latency is not a portable correctness assumption** and
  must not be written into any acceptance criterion.

The acceptance language is therefore **"response semantics are invariant"** —
never "measurement can never delay the provider under every runtime."

Because latency is not guaranteed away by the mechanism, the recorder must
stay cheap by construction. It must remain:

* one bounded aggregate update-or-insert;
* no payload parsing;
* no network calls;
* no long transaction;
* no intentionally introduced lock wait;
* no retry loop beyond the single unique-race retry contracted in §3.5.

This bound is the substantive protection. `terminate()` is the preferred
placement, not a substitute for it, and neither may be traded away for
convenience.

**Route name is authoritative.** The middleware reads
`$request->route()?->getName()` and looks it up in the registry. If the name is
absent, or not in the registry, the middleware returns immediately and records
nothing.

**Provider slug never comes from request input.** It comes only from the
registry's explicit `route name => provider slug` map. No `{gateway}` segment,
no query parameter, no body field, no header participates in the decision.

**Unrelated public routes.** `routes/public.php` also carries the contacts
subscribe/unsubscribe forms, the Stripe usage-billing webhook, the prospecting
webhooks, public website pages and the theme-font route. The middleware is
registered on the group, so those requests traverse it, but the registry
lookup fails for every one of them and **nothing is recorded**. That is the
contracted behaviour and a test asserts it. The alternative — attaching the
middleware to 85 individual route lines — was rejected as a far larger and
more conflict-prone edit for the same measured outcome. The distinction the
task draws is preserved where it matters: no unrelated route is *measured*.

**Failure isolation.** The entire recorder body is wrapped so that no
exception escapes. A failure is passed to Laravel's `report()` helper and
swallowed. A database outage, a missing table, a lock timeout — none may
surface to a provider or alter a supported callback's status, headers or body.
Because the work runs after the controller has produced its `Response`, a
telemetry failure cannot change what that response says; and because the
recorder is bounded (§3.6), a slow failure cannot become an unbounded one.

**No request body is read.** The middleware never calls `$request->all()`,
`getContent()`, `input()`, or `header()`.

### 3.7 The managed Telnyx route

`inbound.telnyx_managed` (added by Chat A, throttled, signature-verified) is a
**supported** route whose use is already known. It is **excluded from the
registry** and therefore not measured.

If the owner wants it as a control metric — a known-live baseline against
which "zero hits" on a legacy route can be read as genuinely zero rather than
as a broken recorder — it is added as one explicit registry entry with slug
`telnyx-managed`, and nothing else changes. **This contract's default is
excluded.** The test suite asserts whichever treatment is chosen, so the
decision is visible in code rather than implicit.

The legacy `inbound.telnyx` and `inbound.twilio` routes **are** measured. They
are part of the legacy family, Chat A's signature work does not remove them,
and their hit counts are directly useful to the retention decision.

---

## 4. S1 — IMMEDIATE HYGIENE

Delete **exactly one** of the two identical `inbound/gatewayapi/{gateway?}`
declarations in `routes/public.php`. Delete the earlier, shadowed one; keep the
later, effective one, so the surviving line is the one that was already
serving traffic.

**What S1 is not:** it is not a GatewayAPI retirement. The provider stays in the
catalog, stays in outbound dispatch, keeps its `dlr/gatewayapi` route, and
keeps `inboundGatewayApi()`. No handler is removed. No signature work is done.

**Behaviour after S1 must be byte-for-byte identical.** The URI, verb set,
controller, method and route name are all unchanged; only a duplicate
registration that Laravel was already discarding is removed.

---

## 5. OBSERVATION WINDOW

S0 evidence may be used to justify a retirement decision only after **one
complete production billing/usage cycle, and never less than 30 days** of
continuous measurement.

**No route is deleted automatically when the window closes.** There is no
timer, no threshold, and no automatic transition anywhere in S0. The window
closing produces a report; a human reads it; the owner decides. Any
implementation that couples elapsed time to a deletion is a contract
violation.

The window starts when the S0 deployment is confirmed recording in production,
not when the code merges.

---

## 6. OPERATOR REPORT

One console command, operator-only, read-only.

`php artisan messaging:legacy-provider-usage-report`

It combines three sources into one retain/deprecate view:

1. **S0 route-hit aggregates** — route name, provider slug, hit count, first
   seen, last seen.
2. **Never-observed routes** — every registry entry with no row, or a row with
   `hit_count = 0`. This is the column the retirement decision actually turns
   on, so it must be explicit rather than inferred from absence.
3. **Current active `SendingServer` provider types** — `DISTINCT settings`
   over active rows, with a count per type. This answers "is anyone configured
   for this provider" independently of whether traffic arrived.
4. **`CustomerBasedSendingServer` / Business bindings**, as counts per provider
   type, where safely queryable.
5. **Platform-issued callback providers as a separate risk class** — the twelve
   the platform's own code hands out (`dlr.arkesel`, `dlr.broadbased`,
   `dlr.d7networks`, `dlr.dotgo`, `dlr.fortytwo`, `dlr.gatewayapi`,
   `dlr.infobip`, `dlr.moceanapi`, `dlr.smsala`, `dlr.smsvas`, `dlr.textlocal`,
   `inbound.textbelt`) are flagged, because for these the platform actively
   published the URL and a zero hit count is weaker evidence of disuse than it
   is elsewhere.

**The command must not print** credentials, tokens, API keys, webhook payloads,
message content, phone numbers, or customer contact details. It prints provider
types, route names, counts and timestamps. Business bindings appear as **counts
per provider type**, not as named customers, so the report is safe to paste
into a decision thread.

**Read-only.** The command must not mutate any `SendingServer`,
`CustomerBasedSendingServer`, or usage row. No customer-facing page is
required or authorized.

---

## 7. CHAT A INTERACTION

**Implementation of S0 and S1 starts only after Chat A's Slice 3 merges.** This
contract document may merge immediately; the code may not.

**The dependency is the final merged Slice 3 tree on `main`, not any Chat A
SHA.** Chat A has advanced repeatedly during this contract's own lifetime —
`9dd47b3` when first written, `122f330` at Correction Round 1, with Security
Correction 37 now beginning — and pinning to any of those would be both stale
and wrong. Implementation reads the merged tree at the moment it starts.

**No S0 or S1 implementation may begin from this contract branch.** This branch
carries documentation only; the implementation slice is a separate branch cut
from `main` after Slice 3 has merged.

**Why the wait.** Both S0 and S1 touch `routes/public.php`, and Chat A is
actively editing that file — one observed head adds a throttled
`inbound/telnyx-managed` registration. S0 additionally touches
`RouteServiceProvider`, which sits alongside the provider-routing surface Chat
A is correcting, and Slice 3 carries six migrations that must be merged before
S0's own timestamp can be chosen correctly (§3.4). The regions differ textually
today, but starting before the merge invites a conflict for no benefit: nothing
about S0 is urgent, and its 30-day window starts at deployment either way.

**Do not duplicate, re-specify, or modify** Chat A's Twilio signature
verification, Telnyx Ed25519 verification, DLR message-ID resolver, refund and
credit logic, inbound media validation, or P0 regression tests. Retained
canonical provider routes remain governed by Chat A. Where this contract and
Chat A's work touch the same route, Chat A's security behaviour is
authoritative and S0 adds only a terminable observer that leaves its response
semantics invariant.

**On merge, re-verify before implementing:** the duplicate is still present and
still at the same URI/name; `RouteServiceProvider`'s public group is still one
line; the registry's route names still all resolve; and the actual latest
merged migration timestamp is read fresh from the merged tree (§3.4).

---

## 8. HARD PATH ALLOWLIST FOR THE S0/S1 IMPLEMENTATION SLICE

The implementation slice, when authorized, is limited to exactly these paths:

```
app/Http/Middleware/RecordLegacyWebhookUsage.php                          (new)
app/Library/Messaging/LegacyWebhookRouteRegistry.php                      (new)
app/Models/LegacyWebhookRouteUsage.php                                    (new)
app/Console/Commands/LegacyProviderUsageReport.php                        (new)
database/migrations/<ts>_create_legacy_webhook_route_usage_table.php      (new)
app/Providers/RouteServiceProvider.php                                    (one line)
routes/public.php                                                         (one deletion, S1)
tests/Feature/Security/LegacyWebhookUsageMeasurementTest.php              (new)
tests/Feature/Security/LegacyGatewayApiDuplicateRouteTest.php             (new)
docs/automation/LEGACY-PROVIDER-WEBHOOK-MEASUREMENT-CONTRACT.md           (this file, status update only)
```

**Prohibited:** every other path. Specifically `app/Http/Controllers/Customer/DLRController.php`,
`app/Models/SendingServer.php`, `app/Models/SendCampaignSMS.php`,
`app/Repositories/Eloquent/EloquentSendingServerRepository.php`, any admin or
customer view, any other migration, any dependency file, `.env`, `.env.testing`,
`AGENTS.md`, `CLAUDE.md`, `docs/automation/AI-AUTONOMY-STATE.json`, and every
file Chat A is editing.

---

## 9. FUTURE SLICES — RECORDED, NOT AUTHORIZED

None of the following is authorized by this contract. Each requires its own
owner decision and its own contract.

| Slice | Scope | Depends on |
|---|---|---|
| **S2** | Generic `inbound/webhook/{user}` removal or signed rebuild | Owner decision |
| **S3** | Admin deprecation labelling; block *new* legacy `SendingServer` creation while leaving existing rows working | S0 data |
| **S4** | Callback retirement for providers with zero observed hits and zero active rows | S0 window closed, S3, owner decision |
| **S5** | Provider catalog and dispatch-switch reduction | S4 |
| **(unnumbered)** | Tenant correlation on measurement rows, if ever wanted — needs an additive migration *and* a deliberate handler seam (§3.3) | Owner decision |

The audit's finding that the surface *could* shrink to Twilio and Telnyx is
recorded there as analysis. **It is not a decision, and S0 must not be
implemented in a way that presumes it.**

---

## 10. TEST CONTRACT

Two new files. Every assertion below is required; none may be weakened or
skipped to obtain a green result.

**`tests/Feature/Security/LegacyWebhookUsageMeasurementTest.php`**

| # | Assertion |
|---|---|
| 1 | Each legacy route, driven by a real request, increments the aggregate for its own `(route_name, provider_slug)` |
| 2 | A repeated hit on the same route increments `hit_count` to 2 and moves `last_seen_at`, while `first_seen_at` is **unchanged** |
| 3 | Two different routes, and two different providers, never share or cross-contaminate a counter |
| 4 | **No payload, message body, or phone number is stored** — assert the table's column list mechanically, and assert every stored value is a route name, slug, count or timestamp |
| 5 | **No request header, signature, token or credential is stored** — same mechanical column assertion, plus a request carrying an obvious fixture credential in a header and body leaves no trace of it anywhere in the table |
| 6 | **Response semantics are invariant under measurement failure** — force the recorder to throw (e.g. bind a failing repository), issue a legacy callback, and assert status, headers and body are byte-identical to the un-instrumented response. Assert the exception was reported, not surfaced. **Do not assert anything about elapsed time**: latency is deployment-dependent (§3.6) and is not a portable acceptance criterion |
| 6a | **The recorder is bounded as §3.6 requires** — assert mechanically that a single measured request performs exactly one aggregate update-or-insert (at most two statements including the contracted unique-race retry), opens no long transaction, introduces no lock wait, parses no payload, and makes no network call |
| 7 | A non-webhook public route (contacts subscribe, theme-font, a public website page) records **nothing** |
| 8 | The managed Telnyx route is treated exactly as §3.7 contracts — asserted explicitly, so the choice is visible in code |
| 9 | **Concurrent increments are not lost** — N parallel hits on one route yield exactly `hit_count = N`, exercised through real concurrent processes against the real database, not a mocked driver |
| 10 | The recorder reads no request body — assert via a request whose body would throw if parsed, or by asserting the middleware never calls the parsing methods |
| 11 | No provider network call occurs — `Http::preventStrayRequests()` is already armed by `tests/TestCase`; assert `Http::assertNothingSent()` |
| 12 | Active `SendingServer` rows are not mutated by measurement or by the report |
| 13 | The operator report prints only safe fields — assert the output contains no credential-shaped substring, no phone-number-shaped substring, and none of the fixture secrets seeded for the test |
| 14 | No customer-visible behaviour changes — an existing legacy callback's response, and any `Reports`/`ChatBox` row it writes, are identical with and without the middleware |

**`tests/Feature/Security/LegacyGatewayApiDuplicateRouteTest.php`**

| # | Assertion |
|---|---|
| 15 | `inbound/gatewayapi/{gateway?}` is registered **exactly once** in the route collection |
| 16 | The surviving route resolves to the same controller and method (`DLRController@inboundGatewayApi`) and keeps the name `inbound.gatewayapi` |
| 17 | A request to that URI behaves identically before and after S1 — same status, same effects |
| 18 | `dlr/gatewayapi` is untouched and still registered |

**Verification requirements.** Run the two new files, then
`tests/Feature/Security/**` and `tests/Feature/Messaging/**` in full as
regression. Use a `TestDatabaseSafety`-validated disposable sibling, distinct
from any concurrently running lane's, and state the exact database name in the
report. A command that exits zero but discovers zero tests is a failure.
Restore runtime-generated tracked files path-scoped so the final
`git status --short` is empty.

---

## 11. EXIT CRITERIA

S0 and S1 are complete when: the measurement table exists and records the four
contracted facts; the recorder runs in `terminate()` and **response semantics
are invariant** — status, headers and body identical with and without it, and
unchanged when it fails; the recorder is bounded as §3.6 requires; no
prohibited field is storable by construction; the migration timestamp was
chosen from the actual merged tree (§3.4); the operator report runs read-only
and prints only safe fields; the GatewayAPI duplicate is gone with identical
behaviour; all nineteen assertions pass with a positive assertion count; and
the 30-day window has **started**, not finished.

**Not an exit criterion:** any claim about added client latency. Latency is
deployment-dependent (§3.6) and is managed by the recorder's bounded shape, not
asserted as a portable property.

Retirement remains un-authorized at that point, and stays that way until the
owner decides on the evidence.

---

`LEGACY PROVIDER S0/S1 — MEASUREMENT CONTRACT READY FOR CHATGPT REVIEW`
