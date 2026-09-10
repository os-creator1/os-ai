# CUSTOMER EXPERIENCE SLICE 3 — MANAGED MESSAGING PROVIDER IMPLEMENTATION

## 1. Status

**In progress.** This document is the lane's running record, not a completion
claim. §6 lists what is still outstanding and §7 lists every blocker and
contract defect found, including the four that are now resolved.

| Field | Value |
|---|---|
| Branch | `agent/customer-experience-slice-3-messaging-provider-implementation` |
| Original base | `origin/main` at `35219efd4fbcd7f5d7a4d346868f37f488a637a5` |
| Merged since | `origin/main` at `e5499df` (Chat D env isolation + DB-safety conversion), then `origin/main` at `b8bab0a` (PR #234), then Lane E at `28810ae` |
| Contract | `docs/automation/CUSTOMER-EXPERIENCE-SLICE-3-MESSAGING-PROVIDER-FOUNDATION.md` |
| Governance route | Human-authorized manual lane (AGENTS.md route 3) |
| Isolated database | `ultimatesms_testing_lane_a_msg`, validated through `Tests\Support\TestDatabaseSafety` |
| Baseline comparison database | `ultimatesms_testing_lane_a_base` (pristine `35219ef` worktree) |
| MySQL | 8.4.3 |

No real Telnyx call, account, number, brand, campaign or rate was created at
any point. Every provider interaction runs through `FakeMessagingAdapter` or
`Http::fake()`, and since the T-MSG-33 guard landed, a test that forgets to
fake now fails loudly instead of reaching the network.

## 2. What is implemented

### 2.1 Schema (§4.2)

Five additive migrations, `2026_09_12_10000{1..5}`, plus Lane E's
`2026_09_12_100006` (§2.8). Verified forward, rollback and forward-replay on
real MySQL:

| Table | Uniqueness mechanism |
|---|---|
| `business_messaging_identities` | STORED generated `active_or_pending_business_id` under `UNIQUE(provider, …)` — one active-or-pending identity per Business |
| `business_messaging_numbers` | STORED generated `active_or_pending_phone_number` (deliberately not provider-scoped) and `active_primary_identity_id` |
| `business_messaging_operations` | ordinary NULL-tolerant `UNIQUE(operation_key)` and `UNIQUE(provider, provider_message_id)` |
| `business_usage_measurements` | `UNIQUE(idempotency_key)`; RFC-005-owned |
| `messaging_webhook_rejections` | `UNIQUE(reason, provider, payload_hash)` as the idempotent-upsert key |

One deviation from the contract's literal text, required by MySQL: the
identity table's composite unique index is explicitly named
`bmi_provider_active_or_pending_business_unique`, because Laravel's
auto-generated name exceeds MySQL's 64-character identifier limit.

### 2.2 Provider-neutral boundary (§4.3)

Nine enums under `app/Enums/Messaging/**`; `MessagingProviderAdapter` with
exactly three methods; readonly DTOs that carry no raw provider body; three
exceptions that name a config key, never a value. `E164Normalizer` refuses a
number without an explicit calling code rather than guessing a region.

`TransportProviderIdentifier` (§7a) normalizes what is *persisted* in the
provider columns, which is a wider domain than the managed-adapter enum.

### 2.3 Adapters and the kill switch (§4.4)

`TelnyxMessagingAdapter` enforces both activation gates in its constructor.
`ManagedMessageDispatcher` **also** enforces the platform kill switch itself,
so it holds for any adapter rather than only the one that self-checks (§7h).
A timeout or an uncorrelatable 2xx is Rejected, never Accepted.

### 2.4 Outbound isolation (§4.5)

`BusinessMessagingIdentityResolver` returns null, never a guess, and fails
closed on zero or several active primary numbers with no "first number"
fallback. `ManagedMessageDispatcher` re-resolves identity and number from the
tenancy-verified Business — `dispatch()` exposes no identity or number
parameter at all — and writes the operation row and the RFC-005 measurement
before the adapter call, both idempotent on the operation key.

`ManagedDispatchDelegate` inserts that behaviour at the two contracted
convergence points, returns null for non-managed Businesses, and (per §7d) a
managed Business no longer needs a legacy `SendingServer` to reach it.
`recordLegacyReport()` returns a real `Reports` model so the calling flow's
existing persistence proceeds unchanged (§7i).

### 2.5 Inbound and DLR (§4.6)

One managed route added to `routes/public.php`; both dead duplicate Telnyx
lines removed from `routes/web.php`. `InboundWebhookAttributionResolver`
verifies the signature first, parses second, then branches by event kind;
`MESSAGE_RECEIVED` requires Profile ID and destination number to resolve
independently to the same identity; `DELIVERY_STATUS` decides replay solely
by the status-transition guard.

Four narrow `DLRController` edits; its other ~57 provider methods untouched.
The shared fail-open default-to-user-1 write is removed for all providers,
BYO Twilio gains real signature verification, and BYO Telnyx inbound is
disabled fail-closed. Each rejection row names the provider it actually came
from (§7a).

### 2.6 Relocated advanced-provider surface (§4.7)

The four blade views moved to `resources/views/customer/settings/advanced/**`
as tracked renames; the route URI prefix moved to
`{workspaceUid}/businesses/{businessUid}/settings/advanced`, so the old path
now 404s. Route **names** are deliberately unchanged because two files
outside this lane's allowlist bind to them (`CustomerMenuBuilder.php:132,222`
and `ViewAsProhibitedActions.php:71`); §4.7 requires the old PATH to stop
resolving and says nothing about internal identifiers. The guard is tightened
by exactly one clause — `canManage()` becomes `isOwner` — with
`manage_advanced_provider` stacked as an independent requirement.

### 2.7 Measurement, retention and the stray-request guard

`UsageWalletManager::recordMeasurement()` delegates to
`BusinessUsageMeasurementRepository`, the only writer of
`business_usage_measurements`. No reservation, rate, activation or ledger row
is written for messaging transport.
`PurgeMessagingWebhookRejections` runs daily.
`Http::preventStrayRequests()` is armed in `tests/TestCase::setUp()`,
alongside — never replacing — Chat D's environment-file isolation.

### 2.8 Lane E's legacy schema, integrated (addendum)

`agent/legacy-messaging-schema-completion` at `28810ae` is merged here rather
than shipped alone, because its migration and this lane's writer correction
are two halves of one change. It adds `chat_boxes.ai_replied`,
`chat_boxes.ai_stage` and `ai_box_campaign_map` — three schema pieces three
live writers already depended on. This lane did not reimplement it.

The other half: both `chat_boxes` writers in `EloquentCampaignRepository` now
mint `(string) Str::uuid()`. See §5.

## 3. Test results

All runs on `ultimatesms_testing_lane_a_msg`, after `migrate:fresh`
(256 migrations, 0 pending).

| Suite | Result |
|---|---|
| `tests/Feature/Outreach` + `tests/Feature/Messaging` + `ManagedCampaignDelegationTest` + `MessagingChannelsTest` | **184 passed (690 assertions)** |
| `tests/Feature/Security` + `tests/Feature/Usage` + `tests/Feature/Workspace` + `tests/Feature/Settings` | **2025 passed (9110 assertions)** |
| `OutreachCorrection1Test` + `LegacyAiMessagingSchemaTest` | 24 passed (92 assertions) |
| `tests/Feature/Usage/MessagingTransportMeasurementLayeringTest.php` (T-MSG-36, 48) | 5 passed (20 assertions) |
| `tests/Feature/Messaging/StrayRequestGuardTest.php` (T-MSG-33) | 7 passed (15 assertions) |
| `tests/Feature/Security/RelocatedAdvancedProviderAuthorizationTest.php` (T-MSG-29, 55..62) | 10 passed (124 assertions) |

Migration mechanics on the isolated database: forward, `rollback --step=1`,
and re-forward — Lane E's two columns and its mapping table appear, disappear
and reappear exactly, with nothing else moving.

The three `Workspace` failures reported at the overnight pause are **gone**,
and not because this lane changed anything: main's own conversion of
`TemporaryTestDatabase` onto `Tests\Support\TestDatabaseSafety` removed the
hardcoded canonical-database pin they tripped over.

## 4. Baseline reproductions

Pristine `35219ef` worktree, isolated database `ultimatesms_testing_lane_a_base`:

| Test | This branch | Pristine main | Verdict |
|---|---|---|---|
| `BusinessKnowledgeProfileControllerTest::test_missing_stale_and_present_fields_are_all_displayed` | failed | failed | pre-existing |
| `BusinessKnowledgeProfileHoursTest::test_no_change_row_for_a_true_hours_no_op` | failed | failed | pre-existing |
| `BusinessKnowledgeProfileSeamTest::test_only_the_manager_writes_the_tracked_tables` | failed | failed | pre-existing |

Three failures were traced to this branch and fixed rather than excused:
`FundingConfirmationConcurrencyCorrectionTest` (the additive thirteenth
constructor parameter), `MessagingProviderAuthorizationTest`'s owner case
(the newly introduced permission), and the whole messaging suite after the
dispatcher-level kill switch landed (fixed at the shared fixture).

## 5. `chat_boxes.uid` — the writer correction

Lane E proved that `campaignBuilder()`'s AI-prospecting hook inserts
`chat_boxes` rows without supplying `uid`, a NOT NULL `char(36)` with no
database default. Nothing failed only because this installation's MySQL
connection is non-strict, so MySQL coerced the missing value to the empty
string: every row that loop ever wrote shares a blank, non-unique uid.

**Both writers, not one.** `EloquentCampaignRepository` creates chat boxes in
two places and both had the identical defect — the raw
`DB::table('chat_boxes')->insertGetId()` in the AI-prospecting hook that Lane
E found, and the `ChatBox::firstOrNew(...)->save()` in the two-way quick-send
path. `ChatBox` has no `creating` hook, and a query-builder insert would
bypass one anyway, so the writers supply it. Fixing only the first would have
let blank uids reappear the moment a two-way send ran.

**Convention:** `(string) Str::uuid()` — what this repository uses wherever a
uid is minted at the write site rather than by a model hook
(`WorkspaceBackfillV1`, `ViewAsSession`, the `Business*` models). The older
`uniqid()` hook on `Contacts` is deliberately not followed: it does not
produce a valid UUID and is not what a `char(36)` column is shaped for.

The column was **not** made nullable, given a default, handed an empty
string, or otherwise loosened, and nothing relies on non-strict MySQL.

`OutreachCorrection1Test::test_legacy_campaign_builder_still_creates_ai_prospecting_rows_unchanged`
had asserted the *crash* — it proved the legacy hook is not gated by proving
it throws on the missing schema, so any correct schema made it fail. It is
rewritten to assert the positive behaviour, with two cross-tenant tests added
beside it.

## 6. Outstanding work

**Not yet written.** T-MSG-2 (resolver-layer conflict exception versus a raw
`QueryException` on the same insert); T-MSG-30 (old routes removed, asserted
in `tests/Feature/Business/` — the behaviour is verified by T-MSG-28 and by
the relocated-surface URL helper, the dedicated assertion is not yet in that
directory); T-MSG-40 (the three idempotency mechanisms independent, at the
outbound end); T-MSG-47 (the migration forward/rollback/replay cycle as a
test rather than as the manual run recorded in §3).

**T-MSG-35 and the inherited T-BYO-1/2 need implementation, not just a
test.** §4.7 states that a BYO send is still measured, via
`recordMeasurement()` with `transport_marker: 'byo'`. Only the
`MessagingTransportMode::Byo` enum case exists today; no BYO send path calls
`recordMeasurement()`. Writing the test first would just assert a feature
that is not there, so both are listed here as open.

**Inherited T-PROV-1/2 and T-SCOPE-1** are likewise not yet asserted in this
lane, though the credential-leak assertions in `OutboundIsolationTest` and
`TelnyxAdapterAndKillSwitchTest` already cover much of T-PROV-2's ground.

## 7. Blockers and contract defects

**(a) RESOLVED — a rejection row named the wrong provider.** The lane had
been recording Twilio signature rejections as `provider = 'telnyx'`, because
`MessagingProvider` has one case. That is a false security-audit record.
`MessagingProvider` stays the managed-adapter enum;
`App\Library\Messaging\TransportProviderIdentifier` normalizes the persisted
identifier, whose domain is the whole legacy fleet. The model's enum cast is
dropped, and all three `DLRController` sites derive the provider from fact.

**(b) RESOLVED — T-MSG-36's "no classification row at all" was unreachable.**
The merged `2026_08_16_120008` backfill inserts one row per `PlatformFeature`
case and throws if any lacks one, and merged migrations are not edited. §4.8
and T-MSG-36 are corrected **in the contract** to the invariant that actually
protects the guarantee: the row exists and is inactive, unmetered and
unpriced, with zero rate and zero activation rows. That is strictly stronger
than an absent row, which proves nothing about whether a rate was activated
elsewhere.

**(c) RESOLVED by Lane E — the missing legacy AI messaging schema.**
`chat_boxes.ai_replied`, `chat_boxes.ai_stage` and `ai_box_campaign_map`
existed in no migration, so two live branches threw on any migration-built
database. Lane E's migration supplies them and this lane supplies the UUID
writer correction (§5). This lane added no competing migration.

**(d) RESOLVED — managed dispatch required a legacy gateway.** The delegation
sat downstream of quickSend()'s legacy sending-server resolution, so a
managed Business was refused unless it also kept a legacy gateway. The four
legacy-gateway guards are now skipped for a managed Business. RFC-005
accounting is preserved by the existing code:
`qualifyConversationsMeterReservation()` already declares `?SendingServer`,
already treats null as non-qualifying, and only ever qualifies for one
configured pilot server.

**(e) OPEN — no model path for `business_messaging_operations`.** §4.11
allowlists models for four tables but not that one, while naming its two
writers. The table is reached through the query builder from inside those two
contract-named classes, so no unlisted path was created. If a model is
wanted, the allowlist needs one line.

**(f) RESOLVED upstream — the hardcoded canonical-database runners.** Main's
own conversion of eight files onto `TestDatabaseSafety` removed the pin.

**(g) OPEN, REPORTED — `campaignBuilder()` trusts request input for
tenancy.** It reads `$input['business_id']` and `$input['user_id']` directly,
and `CampaignController@storeCampaign` forwards `$request->except('_token')`
verbatim. Partially mitigated and now asserted: the merged Correction-1 guard
already rejects a **contact group** belonging to another tenant, and
`test_a_foreign_contact_group_is_refused_and_writes_nothing` pins that a
cross-tenant attempt writes no campaign, no chat box and no mapping row. The
deeper fix — deriving actor identity from trusted application context instead
of the request body — cannot be made inside `campaignBuilder()` alone,
because the Outreach controller legitimately passes both keys after doing its
own tenancy resolution. Changing that contract means editing
`app/Http/Controllers/Customer/OutreachController.php` and
`app/Http/Controllers/Customer/CampaignController.php`, **neither of which is
in this lane's allowlist**. Reported here rather than edited.

**(h) RESOLVED — the kill switch only worked for one adapter.**
`messaging.managed_messaging_enabled` was enforced solely in the real
adapter's constructor, so any adapter that does not self-check walked past
it. `ManagedMessageDispatcher` now checks it first, and a disabled platform
writes no operation row and no measurement row.

**(i) RESOLVED — the delegation returned a shape the caller could not use.**
`track_message()` reads the send result with BOTH property and array access,
which a plain `stdClass` cannot satisfy; the legacy path returns a `Reports`
model. Under the sync queue driver `Batch::add()` executes the job inside its
own bookkeeping transaction, so the resulting throw rolled that transaction
back and took the operation and measurement rows with it. The rows were never
wrong; the return shape was.

**(j) OPEN, RECORDED — provider call inside an open transaction.** Under the
sync queue driver the campaign chain runs inside `Batch::add()`'s transaction,
contrary to §4.9's "no provider request inside an open transaction". With a
real queue worker `SendMessage` runs in its own process and the property
holds. The idempotent operation key is the protection either way.

## 8. Deferred, exactly as the contract defers them

`EloquentCampaignRepository::sendApi()`;
`app/Console/Commands/SendScheduleAPIMessage.php` delegation;
`EloquentCampaignRepository::apiCampaignBuilder()`; Managed Accounts; BYO
Telnyx inbound upgrade (Ed25519 material storage — Slice 9); number lifecycle
automation (Slice 4); retail usage-rate activation; live provider activation.
None is touched, tested or delegated here.
