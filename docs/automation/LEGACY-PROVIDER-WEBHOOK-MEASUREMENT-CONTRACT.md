# LEGACY PROVIDER WEBHOOK SURFACE — S0 MEASUREMENT AND S1 HYGIENE CONTRACT

**Status:** Implementation contract. It authorizes exactly two slices, S0 and
S1, and nothing else. **It does not authorize provider retirement.**

**Base:** `origin/main` at `b87b669d55de97957d3583407ec2b69cd75d2eaa`.

**Concurrent lane:** `agent/customer-experience-slice-3-messaging-provider-implementation`
at `9dd47b3744d18f98f0c065ac2de91fae10790f9e` ("fix(messaging): close legacy
webhook P0 security gaps"). Chat A owns that branch. Nothing here modifies it,
duplicates it, or depends on its internals.

**Evidence:** the completed *Legacy Messaging Provider Surface Retention
Audit*, re-verified mechanically against both SHAs above before this contract
was written (§2).

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
no column capable of holding personal data. That is a structural guarantee, not
a convention.

**This is not a generic analytics or event table** and must not be presented as
one. It is single-purpose, and §9's retirement slice is expected to drop it
once the decision it exists to inform has been taken.

**Migration ordering.** Timestamp must sort after
`2026_09_11_120004_*`, the latest migration on main at the time of writing.
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

**Why `terminate()` and not `handle()`.** A terminable middleware runs *after*
the response has been sent to the provider. Measurement therefore **cannot**
alter the webhook response, cannot delay it, and cannot fail it — the guarantee
is structural rather than defensive. This is the single most important design
constraint in S0 and must not be traded away for convenience.

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
surface to a provider or affect a supported callback. Because the work happens
in `terminate()`, the response has already been sent regardless.

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

**Why.** Both S0 and S1 touch `routes/public.php`, and Chat A is actively
editing that file — its current head adds a throttled `inbound/telnyx-managed`
registration. S0 additionally touches `RouteServiceProvider`, which sits
alongside the provider-routing surface Chat A is correcting. The regions differ
textually today, but starting before the merge invites a conflict for no
benefit: nothing about S0 is urgent, and its 30-day window starts at deployment
either way.

**Do not duplicate, re-specify, or modify** Chat A's Twilio signature
verification, Telnyx Ed25519 verification, DLR message-ID resolver, refund and
credit logic, inbound media validation, or P0 regression tests. Retained
canonical provider routes remain governed by Chat A. Where this contract and
Chat A's work touch the same route, Chat A's security behaviour is
authoritative and S0 adds only a terminable observer that cannot affect it.

**On merge, re-verify before implementing:** the duplicate is still present and
still at the same URI/name; `RouteServiceProvider`'s public group is still one
line; and the registry's route names still all resolve.

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
| 6 | **Measurement failure cannot alter the webhook response** — force the recorder to throw (e.g. bind a failing repository), issue a legacy callback, and assert the HTTP status and body are byte-identical to the un-instrumented response |
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
contracted facts; the recorder runs in `terminate()` and provably cannot affect
a response; no prohibited field is storable by construction; the operator
report runs read-only and prints only safe fields; the GatewayAPI duplicate is
gone with identical behaviour; all eighteen assertions pass with a positive
assertion count; and the 30-day window has **started**, not finished.

Retirement remains un-authorized at that point, and stays that way until the
owner decides on the evidence.

---

`LEGACY PROVIDER S0/S1 — MEASUREMENT CONTRACT READY FOR CHATGPT REVIEW`
