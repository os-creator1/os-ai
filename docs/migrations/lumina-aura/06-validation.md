# 06 · Validation — exactly what was run

Databases: disposable local MariaDB 10.11 siblings (`ultimatesms_testing_lane_ghl`, `_lane_port`; staging import on `_lane_lumina`), all migrations applied (`RefreshDatabase`). No external service was contacted; provider edges are `Http::fake`/recorders, webhooks are locally Ed25519-signed.

## New tests (all pass)

| Suite | Tests | Covers |
|---|---|---|
| `tests/Unit/Migration` | 8 | planner (E.164, consent, dedupe, stage/money mapping, historical-only, determinism), exporter (pagination, UNVERIFIED reporting, GET-only, 429 retry, missing token) |
| `tests/Unit/Automation/BusinessSendWindowTest` | 5 | quiet hours in America/Denver, UTC≠local, both DST transitions, business hours only when asked and recorded |
| `tests/Feature/NicheBlueprint/PhotoBoothBusinessPackV3{,Install}Test` | 6 + 3 | every component passes its adapter; exact stage lists; 10 required workflows; no private data/prices/links; pack **installs** (fields, form, pipelines, 11 draft workflows) with correct stage triggers and branching guards; re-install adds nothing |
| `tests/Feature/Migration/LuminaImportTest` | 4 | provisioning safety audit; dry-run == applied; contacts/opportunities/ledger/original timestamps; opt-out; inertness (no enrollments/operations/reports/queue/mail/http); idempotent re-run; timeline display; **tenant separation** (same snapshot into two Businesses stays independent) |
| `tests/Feature/Migration/LuminaWebsiteTest` | 2 | 9 pages render 200 with the source phone, no GHL hot-links, titles, no prices/testimonials, brand colour; **real public form submission** → contact + Inquiries/New Lead opportunity, consent answers stored, nothing sent |
| `tests/Feature/Migration/PhotoBoothAutomationsRuntimeTest` | 8 | real publish + advancer + wake sweep: tag, call task, delay, single text, reply cancels, opt-out never messaged (6 workflows), quiet hours hold to 08:00 Denver, closed deal stops, review/questionnaire links only when set, retry safety/step logging, real stage move enrolls |
| `tests/Feature/Conversations/ImportedHistoryScreenTest` | 1 | authenticated Conversations screen: inbound/outbound, original order, "Imported from GoHighLevel", failed message labelled, no retry control, nothing transmitted |
| `tests/Feature/Messaging/PortInOrderTest` | 5 | cross-tenant number protection, signed `ported` event attaches to the ordering Business + Location once, replay/late-event safe, bad/missing signature and malformed bodies change nothing, no profile → waits for a human, read-only portability check refuses without a key |

## Regression against the base branch (`26a40ec`)

* Final HEAD run: `NicheBlueprint`, `Automations`, `Conversations`, `Forms`, `Website`, `Migration`, `Unit` — **2,464 tests, 19,641 assertions; 56 failures/errors + 1 skipped**.
* A clean worktree of `26a40ec` fails the **same tests** (re-run of every failing file there: 58 earlier / 4 Forms+Website). **New failures introduced by this work: 0.** Separately, `tests/Feature/Messaging` (428 tests): 6 failures, identical to base.
* The 56 pre-existing failures were not investigated and do not block the migration. They cluster in builder/HTTP pages, blueprint-installer fixtures, migrations replay and Growth enums — consistent with running a MySQL-targeted suite on MariaDB in a stripped environment. Reported separately; unrelated to this work.

## Defects found by running real flows, and fixed

1. Pack questionnaire form had two phone questions (MotionGrove allows one) — found only by actually installing it.
2. Pack follow-ups would have exceeded the engine's branch-depth limit — guards now collapse into one If/Else.
3. A missing mailbox halted the whole journey before the SMS — added `FailurePolicy::Continue`.
4. SMS that GHL itself failed to deliver would have shown as normal messages — now read-only, labelled "Originally: failed".
5. Website SEO fields over the generator's length limits; Docs hero text/brand-colour assertions.

## Not covered
Live provider behaviour (no key, no sending path), interactive-browser form iframe height, GHL UI workflow definitions, any production environment.
