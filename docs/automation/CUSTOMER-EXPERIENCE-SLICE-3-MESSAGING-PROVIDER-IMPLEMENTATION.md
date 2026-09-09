# CUSTOMER EXPERIENCE SLICE 3 — MANAGED MESSAGING PROVIDER IMPLEMENTATION

## 1. Status

**In progress, paused at a safe checkpoint.** This document is the running
record of the lane; it is not a completion claim. Section 6 lists exactly
what is still outstanding, and section 7 lists every blocker and contract
defect found so far.

| Field | Value |
|---|---|
| Branch | `agent/customer-experience-slice-3-messaging-provider-implementation` |
| Base | `origin/main` at `35219efd4fbcd7f5d7a4d346868f37f488a637a5` |
| Contract | `docs/automation/CUSTOMER-EXPERIENCE-SLICE-3-MESSAGING-PROVIDER-FOUNDATION.md` |
| Governance route | Human-authorized manual lane (AGENTS.md route 3) |
| Isolated database | `ultimatesms_testing_lane_a_msg`, validated through `Tests\Support\TestDatabaseSafety` |
| Baseline comparison database | `ultimatesms_testing_lane_a_base` (pristine `35219ef` worktree) |
| MySQL | 8.4.3 |

No real Telnyx call, account, number, brand, campaign or rate was created at
any point. Every provider interaction in the suite runs through
`FakeMessagingAdapter` or `Http::fake()`.

## 2. What is implemented

### 2.1 Schema (§4.2)

Five additive migrations, `2026_09_12_10000{1..5}`, verified forward,
`rollback --step=5` in reverse dependency order, and forward replay on real
MySQL:

| Table | Uniqueness mechanism |
|---|---|
| `business_messaging_identities` | STORED generated `active_or_pending_business_id` under `UNIQUE(provider, …)` — one active-or-pending identity per Business |
| `business_messaging_numbers` | STORED generated `active_or_pending_phone_number` (deliberately not provider-scoped) and `active_primary_identity_id` |
| `business_messaging_operations` | ordinary NULL-tolerant `UNIQUE(operation_key)` and `UNIQUE(provider, provider_message_id)` — no condition to encode, so no generated column |
| `business_usage_measurements` | `UNIQUE(idempotency_key)`; RFC-005-owned |
| `messaging_webhook_rejections` | `UNIQUE(reason, provider, payload_hash)` as the idempotent-upsert key |

One deviation from the contract's literal text, required by MySQL: the
identity table's composite unique index is explicitly named
`bmi_provider_active_or_pending_business_unique`, because Laravel's
auto-generated name exceeds MySQL's 64-character identifier limit. The
columns and semantics are exactly as contracted.

### 2.2 Provider-neutral boundary (§4.3)

Nine enums under `app/Enums/Messaging/**`; `MessagingProviderAdapter` with
exactly three methods and no Slice-4-shaped stub; readonly DTOs that carry no
raw provider body; three exceptions that name a config key, never a value.
`E164Normalizer` gives managed messaging one canonical representation and
refuses a number without an explicit calling code rather than guessing a
region.

### 2.3 Adapters and the kill switch (§4.4)

`TelnyxMessagingAdapter` enforces both activation gates in its constructor —
the platform kill switch AND every required `services.telnyx` key — so real
credentials alone cannot start traffic and no partially configured adapter
can exist. A timeout or an uncorrelatable 2xx is Rejected, never Accepted.
`FakeMessagingAdapter` is deterministic, records every call and performs no
I/O.

### 2.4 Outbound isolation (§4.5)

`BusinessMessagingIdentityResolver` returns null, never a guess, on anything
short of exactly one unambiguous active match, and fails closed on zero or
several active primary numbers with no "first number" fallback.
`ManagedMessageDispatcher` re-resolves identity and number from the
tenancy-verified Business — its `dispatch()` signature exposes no identity or
number parameter at all — writes the operation row and the RFC-005
measurement before the adapter call, both idempotent on the operation key,
and calls the provider outside any open transaction.

`ManagedDispatchDelegate` inserts that behaviour at the two contracted
convergence points: `EloquentCampaignRepository::quickSend()` and
`Campaigns::sendSMS()` (the point `campaignBuilder()`'s async chain converges
on). It returns null for non-managed Businesses so every legacy path is
unchanged.

### 2.5 Inbound and DLR (§4.6)

One managed route added to `routes/public.php`; both dead duplicate Telnyx
lines removed from `routes/web.php`. `InboundWebhookAttributionResolver`
verifies the signature first, parses second, then branches by event kind.
`MESSAGE_RECEIVED` requires the Messaging Profile ID and the destination
number to resolve independently to the same identity. `DELIVERY_STATUS`
locates the existing operation and decides replay solely by the
status-transition guard, so the first legitimate callback is applied and a
regressive callback can never move a terminal row backward.

Four narrow `DLRController` edits; its other ~57 provider methods are
untouched. The shared fail-open default-to-user-1 write is removed for all
providers, BYO Twilio gains real signature verification, BYO Telnyx inbound
is disabled fail-closed, and the managed entry point delegates immediately.

### 2.6 Relocated advanced-provider surface (§4.7)

The four blade views moved from
`resources/views/customer/business/MessagingChannels/**` to
`resources/views/customer/settings/advanced/**` as tracked renames, and the
route block's URI prefix moved from
`{workspaceUid}/businesses/{businessUid}/channels` to
`{workspaceUid}/businesses/{businessUid}/settings/advanced`. The old path
now falls through to Laravel's ordinary 404 — a genuine rename, not a
permanent second surface.

The route **names** are deliberately unchanged. §4.7 requires the old PATH to
stop resolving and says nothing about internal identifiers, and two files
outside this slice's allowlist bind to those names —
`CustomerMenuBuilder.php:132,222` and `ViewAsProhibitedActions.php:71`.
Renaming would have forced edits to unauthorized files for no gain; keeping
them means the menu entry still points at the surface and the View-As
prohibition still covers it. The reasoning is recorded in the route block
itself.

The guard is tightened by exactly one clause — `canManage()` becomes
`isOwner` — with `manage_advanced_provider` stacked as an independent
requirement.

### 2.7 Measurement and retention (§4.8)

`UsageWalletManager::recordMeasurement()` delegates to
`BusinessUsageMeasurementRepository`, the only writer of
`business_usage_measurements`. No reservation, rate, activation or ledger row
is ever written for messaging transport.
`PurgeMessagingWebhookRejections` disposes of rejection rows past the
configured window, scheduled daily.

## 3. Test results

All runs on `ultimatesms_testing_lane_a_msg`.

| Suite | Result |
|---|---|
| `tests/Feature/Messaging` (excl. the new legacy file) | 64 passed (313 assertions) |
| `tests/Feature/Messaging/LegacyInboundFailClosedTest.php` (T-MSG-24..28) | 7 passed (32 assertions) |
| `tests/Feature/Security/RelocatedAdvancedProviderAuthorizationTest.php` (T-MSG-29, 55..62) | 10 passed (124 assertions) |
| `tests/Feature/Business/MessagingChannelsTest.php` + `Security/MessagingProviderAuthorizationTest.php` + `Workspace/ViewAsClientTest.php` (post-relocation) | 61 passed (242 assertions) |
| `tests/Feature/Security` | 172 passed (1302 assertions) |
| `tests/Feature/Usage` | 998 passed (5193 assertions) |
| `tests/Feature/Workspace` + `tests/Feature/Settings` | 828 passed, 3 failed — all three are the hardcoded-canonical-database runners (§7d) |
| `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` (T-MSG-38) | 32 passed (182 assertions) |
| `tests/Feature/AgencyProspecting/AgencyProspectingRuntimeTest.php` (T-MSG-38) | 124 passed (266 assertions) |
| `tests/Feature/Business` | 3 failed, 520 passed — all three reproduced on pristine `35219ef` (§7c) |
| `tests/Feature/Business/ManagedCampaignDelegationTest.php` (T-MSG-9/10/65/66) | **5 passed, 2 failing — in progress, see §6** |

## 4. Baseline reproductions

Pristine `35219efd4fbcd7f5d7a4d346868f37f488a637a5` worktree, isolated
database `ultimatesms_testing_lane_a_base`:

| Test | This branch | Pristine main | Verdict |
|---|---|---|---|
| `BusinessKnowledgeProfileControllerTest::test_missing_stale_and_present_fields_are_all_displayed` | failed | failed | pre-existing |
| `BusinessKnowledgeProfileHoursTest::test_no_change_row_for_a_true_hours_no_op` | failed | failed | pre-existing |
| `BusinessKnowledgeProfileSeamTest::test_only_the_manager_writes_the_tracked_tables` | failed | failed | pre-existing |

Two failures were traced to this branch and fixed rather than excused:
`FundingConfirmationConcurrencyCorrectionTest` broke on the additive
thirteenth constructor parameter (positional Mockery argument list, extended
in step), and `MessagingProviderAuthorizationTest`'s owner case needed the
newly introduced permission. `ConcurrentTopUpConcurrencyTest` failed once
inside a full-suite run and passed both in isolation (4/4) and on a clean
re-run of the whole suite — a multi-process timing artifact, not a
regression.

## 5. A bug this lane found in its own earlier work

`twilioSignatureIsValid()` (checkpoint 8) selected candidate servers with
`where('type', SendingServer::TYPE_TWILIO)`. `type` is the transport enum
(`http`/`smpp`/`whatsapp`/`viber`/`otp`); the provider discriminator lives in
`settings`, which is the column `getSendingServer()` reads two methods above.
The query matched nothing, so every legacy Twilio inbound request was refused
regardless of its signature — fail-closed, and therefore invisible until
T-MSG-26 asserted the positive half. Fixed in checkpoint 15.

## 6. Outstanding work

**The Chat D integration point — unchanged and still the only cross-lane
collision.** The contract (§4.4 item 19, §4.13 step 3) requires one line,
`Http::preventStrayRequests();`, in `tests/TestCase.php`, and T-MSG-33
asserts it is active. Chat D owns that file for its environment-file
isolation work, so this lane has not touched it and T-MSG-33 is not
implemented. Nothing else depends on it: every test here binds
`FakeMessagingAdapter` explicitly or uses `Http::fake()` with matched
fixtures, so no test in this branch makes or needs a real HTTP call. The
outstanding item is the suite-wide guarantee, not this slice's own coverage.

**In progress at the pause boundary — T-MSG-9/10/65/66.**
`tests/Feature/Business/ManagedCampaignDelegationTest.php` is committed as
work in progress: 5 of its 7 tests pass, including T-MSG-9 (`quickSend()`
delegates) and T-MSG-10 (`Campaigns::sendSMS()` delegates). The two async
campaign-route tests (T-MSG-65/66) now drive the real
`RunCampaign` → `LoadCampaign` → `SendMessage` → `send()` → `sendSMS()`
chain and reach the managed adapter, but their operation-row assertion
fails: no `business_messaging_operations` row survives the request even
though `ManagedMessageDispatcher::recordAttempt()` writes one before the
adapter call. The leading hypothesis, not yet confirmed, is that the
campaign chain runs inside `Campaigns::execute()`'s outer `DB::transaction`,
so the nested savepoint the dispatcher uses is discarded when that
transaction unwinds. That is a real question about §4.9's transaction
boundaries and must be answered, not asserted around. The file also still
carries temporary diagnostic helpers (`flashDiagnostic()`,
`campaignDiagnostic()`) to be removed once the two tests are green.

**Not yet written.** T-MSG-2 (resolver-layer conflict exception versus a raw
`QueryException` on the same insert); T-MSG-30 (old routes removed, as a
test — the behaviour is verified, the assertion is not yet in
`tests/Feature/Business/`); T-MSG-35 (BYO send measurement with
`transport_marker = byo`); T-MSG-40 (the three idempotency mechanisms
independent, at the outbound end); T-MSG-47 (migration forward/rollback/
replay as a test rather than as a manual run); T-MSG-48 (repository-layering
spy); and the five inherited IDs T-PROV-1/2, T-BYO-1/2, T-SCOPE-1.

**Environment prerequisites discovered while running the async chain**, worth
recording because they cost real time: `storage/app/quota/` must exist or
every campaign send fails on the rate-limit tracker's file write, and a
fixture `SendingServer` needs `quota_value = RateLimit::UNLIMITED` because
the column defaults to `0`, which the limiter reads as "no sends allowed".

## 7. Blockers and contract defects discovered

Reported rather than improvised around, per the lane's stop-and-report rule.

**(a) `MessagingProvider` has one case but two tables need more.** §4.3
defines the enum as exactly `TELNYX`. But
`business_messaging_operations.provider` is documented as "the transport's
actual provider, **for BYO too**" (§4.2), and §4.6.5 requires
`messaging_webhook_rejections` rows written from `inboundTwilio()`
(`invalid_signature`) and from `inboundDLR()` for all ~60 legacy providers
("`provider` taken from the calling method"). A one-case enum cannot express
Twilio or the other ~58. **Current state:** the two legacy rejection sites
record `MessagingProvider::Telnyx` because that is the only case the contract
defines; the rejection rows remain correct and useful (reason, hash,
occurrence count), but the `provider` column does not distinguish the
originating provider for non-Telnyx traffic. Resolving this needs an explicit
decision — extend the enum, or make the column a plain string — which is an
architectural choice this lane declined to make unilaterally.

**(b) T-MSG-36's "no classification row at all" is unreachable.** §4.8 and
T-MSG-36 require `platform_feature_usage_classifications` to carry no row for
`PlatformFeature::MessagingTransport`. The already-merged migration
`2026_08_16_120008_backfill_platform_feature_usage_classifications` inserts
one row per `PlatformFeature` case and **throws** if any case lacks one, so
adding the contracted enum case necessarily creates the row on any fresh
migrate; editing merged migration history is forbidden. **Current state:** the
row exists with `is_metered = 0` and `active_rate_id = NULL`, identical to
every other unpriced feature, and the test asserts exactly what the
requirement protects — no metering, no rate, no activation, no reservation.

**(c) `chat_boxes.ai_replied` and `chat_boxes.ai_stage` exist in no
migration.** `inboundDLR()`'s *attributed* branch runs
`UPDATE chat_boxes SET reply_by_customer = 1, ai_replied = 0`
(`DLRController.php:620-625`), and `campaignBuilder()`'s legacy
AI-prospecting branch inserts `chat_boxes.ai_stage` and into
`ai_box_campaign_map`. None of those three schema pieces is defined by any
migration in the repository, so both branches throw on any migration-built
database. The statements are byte-identical on pristine `origin/main` and the
columns are absent from the pristine baseline database, so this is
pre-existing and outside Slice 3's allowlist. Consequence for this lane:
T-MSG-26's positive half cannot be asserted end-to-end, and is instead
asserted at the gate's own boundary by *which* refusal each request produces
(see §5 and the test's own docblock); and the legacy campaign-builder test
must supply `business_id` to skip the AI-prospecting branch, which its
comment states plainly.

**(d) Slice 3's delegation point is downstream of the legacy
sending-server requirement.** §4.11 places the `quickSend()` insertion at the
pre-dispatch point, which is only reached after the legacy plan-coverage and
sending-server resolution above it succeeds. A managed Business therefore
still needs legacy plan coverage and a legacy `SendingServer` row to reach
the managed path at all — which contradicts §4.7's "managed messaging is the
normal experience". This lane did **not** move the insertion earlier:
doing so would also bypass RFC-005's Conversations reservation seam, which
Slice 3 is not authorized to redesign. The tests supply the legacy
prerequisite and the fixture says why. This needs an explicit decision.

**(e) No model path for `business_messaging_operations`.** §4.11 allowlists
models for the identity, number, measurement and rejection tables but not for
the operations table, while naming `ManagedMessageDispatcher` and
`InboundWebhookAttributionResolver` as its only writers. **Current state:**
that table is accessed through the query builder from inside those two
contract-named classes, so no unlisted path was created. If a model is
wanted, the allowlist needs one line.

**(f) Three tests fail only because this lane runs on a non-canonical
database.** `WorkspaceManagerConcurrencyTest`, `WorkspaceManagerTest` and
`WorkspaceTransitionsMigrationSchemaTest` use
`Tests\Feature\Workspace\Support\TemporaryTestDatabase`, whose
`BASE_DATABASE` constant is hardcoded to `ultimatesms_testing`; it refuses to
run against `ultimatesms_testing_lane_a_msg`. These are infrastructure
refusals, not regressions, and this lane did not modify those runners.

**(g) `campaignBuilder()` trusts request input for tenancy.**
`EloquentCampaignRepository::campaignBuilder()` reads `$input['business_id']`
and `$input['user_id']` directly, and `CampaignController@storeCampaign`
forwards `$request->except('_token')` verbatim. A caller can therefore name a
Business and a user in the request body on the legacy route. This is
pre-existing, is not introduced or widened by Slice 3, and
`campaignBuilder()` is outside this slice's allowlist except for the
already-merged `quickSend()` insertion — so it is reported here rather than
changed.

## 8. Deferred, exactly as the contract defers them

`EloquentCampaignRepository::sendApi()`;
`app/Console/Commands/SendScheduleAPIMessage.php` delegation;
`EloquentCampaignRepository::apiCampaignBuilder()`; Managed Accounts; BYO
Telnyx inbound upgrade (Ed25519 material storage — Slice 9); number lifecycle
automation (Slice 4); retail usage-rate activation; live provider activation.
None is touched, tested or delegated here.
