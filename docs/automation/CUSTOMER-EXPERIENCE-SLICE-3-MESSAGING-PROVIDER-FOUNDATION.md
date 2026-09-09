# CUSTOMER EXPERIENCE SLICE 3 — MANAGED MESSAGING PROVIDER FOUNDATION

## 1. STATUS AND AUTHORITY

This is a **preimplementation contract and executability audit**. It authorizes
nothing by itself beyond what `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`
§21/§21.2/§22 already authorizes for Slice 3. It does not revisit
`docs/automation/TELNYX-MANAGED-MESSAGING-ARCHITECTURE-DECISION.md`'s Candidate
B selection (one platform-owned, Pay-as-you-go Telnyx account; one dedicated
Messaging Profile and phone number per Business; provider-neutral
`BusinessMessagingIdentity`; no `provider_managed_account_id` or any dormant
Managed-Account column at launch; Managed Accounts remain a future,
owner-evaluated migration). That decision is treated here as locked and final.

This document was produced by a documentation-and-audit pass only:

* No Telnyx API call was made.
* No provider account, Messaging Profile, number, registration, webhook, rate
  or credential was created or altered.
* No production code, migration, configuration, or dependency file was
  changed. The only repository change accompanying this document is the
  narrow §22.1 amendment to the parent contract described in §3 below.
* No credential or secret **value** appears anywhere in this document — only
  configuration-key and column **names**.

Base verification: this branch (`agent/customer-experience-slice-3-messaging-provider-contract`)
was created from `origin/main` at `6c820c801da08ecfd6165d1d3a52ae6336606f0c`,
which is PR #219's merge commit
(`4e1c9e156d93d40a0140ea47033f522e4871eb61` confirmed as an ancestor). No Lane
A/Lane C branch was used or merged. `origin/main` had not advanced past that
SHA as of this document's authoring.

## 2. MECHANICAL REPOSITORY AUDIT — SUMMARY AND FILE:LINE EVIDENCE

Full trace performed against the merged tree at the base SHA above, personally
verified (not accepted from tool output alone) for every item below. All
paths are repository-root-relative.

**(1) Outbound SMS/MMS dispatch entry points.** Two independent families:

* Legacy/B1-B4 core, all funnelling into `Campaigns` (which `extends
  SendCampaignSMS`, `app/Models/Campaigns.php:52`):
  `EloquentCampaignRepository::quickSend()` (`app/Repositories/Eloquent/EloquentCampaignRepository.php:421,441,446` — switch on `sms_type` to `sendPlainSMS`/`sendVoiceSMS`/`sendMMS`), a second switch in the same file's bulk/scheduled `campaignBuilder()` path (`:1826,1830,1834`), `Campaigns`'s own internal dispatch switch (`app/Models/Campaigns.php:979,983,987`), and direct callers: `app/Library/Automation/Actions/SendMessageAction.php:139` (via `quickSend()`), `app/Console/Commands/SendScheduleAPIMessage.php:60,64,68`, `app/Console/Commands/CheckUserPreferences.php:103,129,179,205`, `app/Repositories/Eloquent/EloquentAnnouncementsRepository.php:105`, `app/Notifications/TwoFactorCode.php:82`, `app/Notifications/TopupNotification.php:75`.
* Agency Prospecting (entirely separate, never touches `SendCampaignSMS`):
  `app/Jobs/AgencyProspectingInitialSendJob.php:98` → `AgencyProspectingMessageSender` contract → `app/Library/AgencyProspecting/ProviderAgencyProspectingMessageSender.php:38-42` (`match($channel->provider) { TYPE_TWILIO, TYPE_TELNYX }`).

**(2) The legacy raw-cURL dispatcher.** `app/Models/SendCampaignSMS.php`,
18,188 lines. `sendPlainSMS()` at line 71, `sendVoiceSMS()` at line 13566,
`sendMMS()` at line 14452. Telnyx cases: `sendPlainSMS` — `TYPE_TELNYX` at
`:1366-1422` (raw `curl_init`, `Authorization: Bearer {api_key}`,
`messaging_profile_id` sourced from `c1` only when the sender ID is
alphanumeric), `TYPE_TELNYXNUMBERPOOL` at `:1424` (always sends `c1`);
`sendVoiceSMS` — `TYPE_TELNYX` at `:13923`; `sendMMS` — `TYPE_TELNYX` at
`:14781-14834`, `TYPE_TELNYXNUMBERPOOL` at `:14836`. Every caller is the list
in item (1).

**(3) Provider-selection switch/match logic.** Full `TYPE_TELNYX`/`TYPE_TWILIO`
site list: `app/Library/Tool.php:689`; `app/Repositories/Eloquent/EloquentSendingServerRepository.php:95-97,115-117,533-535,556-558,5933-5935` (the static provider-metadata catalog, not a dispatch switch — see item 9); `app/Repositories/Eloquent/EloquentCampaignRepository.php:720`; `app/Library/AgencyProspecting/ProviderAgencyProspectingMessageSender.php:38-41`; `app/Http/Requests/SendingServer/StoreSendingServerRequest.php:38-39`; `app/Models/SendCampaignSMS.php` (11 case sites across the three send methods, listed in item 2 plus `:15680`, `:16984` for WhatsApp variants); `app/Http/Controllers/Prospecting/AgencyProspectingWebhookController.php:49,72`; `app/Http/Controllers/Customer/Business/MessagingChannelsController.php:49,56,349-350` (`ALLOWED_PROVIDERS`); `app/Http/Controllers/Customer/DLRController.php:1007-1008,1116-1117,1427-1428` (default-gateway fallbacks); `app/Http/Controllers/Customer/Workspace/AgencyProspectingChannelController.php:41,48,298`.

**(4) `CustomerBasedSendingServer` and credential storage.**
`app/Models/CustomerBasedSendingServer.php` (60 lines): `$fillable =
['user_id','business_id','sending_server','status']`, `$casts =
['status'=>'boolean']` — it holds no credential field itself, only the FK
link to `SendingServer`. `app/Models/SendingServer.php` (590 lines):
`$fillable` (lines 307-367) includes every credential-shaped column
(`account_sid, auth_id, auth_token, access_key, access_token, secret_access,
api_key, api_secret, user_token, project_id, api_token, auth_key, username,
password, c1..c7`, etc.); `$casts` (lines 374-389) casts only
`schedule/custom/status/two_way/plain/mms/voice/whatsapp/viber/otp` to
`boolean` and `quota_value/quota_base/sms_per_request/port` to `integer` —
**none of the credential-shaped columns has any cast at all**. Confirmed:
zero encryption on any Telnyx or Twilio credential column today.

**(5) Credential read/decrypt/display/serialize sites.** The legacy
non-B2 SendingServer forms round-trip the raw stored secret back into the
browser on every page load: `resources/views/customer/SendingServer/create.blade.php`
(used for both create and edit) interpolates `value="{{ $server['account_sid'] }}"`
(`:219-220`), `value="{{ $server['auth_token'] }}"` (`:330-331`), `value="{{
$server['api_key'] }}"` (`:287-288`/`:362-363`), `value="{{ $server['c1'] }}"`
/ `value="{{ $server['c2'] }}"` (`:299`,`:314`); the admin twin
`resources/views/admin/SendingServer/create.blade.php` and the generic
`create_custom.blade.php`/`edit_custom.blade.php` pair (customer and admin)
do the same for `username_value`/`password_value`. **B2's
`MessagingChannelsController` does not do this**:
`resources/views/customer/business/MessagingChannels/show.blade.php:75` uses
`placeholder="Leave blank to keep current value"` and never echoes the
stored secret; `MessagingChannelsController::validateCredentials()`
(`:313-336`) never re-populates a blank field with the old value. No `$hidden`
redaction exists on `SendingServer` itself — a latent risk if that model is
ever JSON-serialized directly, flagged here, not fixed here (`SendingServer`
is out of Slice 3's allowlist and stays unmodified per the B2 docblock's own
"existing, unmodified SendingServer backend" rule).

**(6) Inbound message/DLR webhook routes.** `routes/public.php` (loaded under
`Route::middleware('web')` only, per `app/Providers/RouteServiceProvider.php:59-65`
— no `auth`): Telnyx — `Route::any('inbound/telnyx/{gateway?}',
'Customer\DLRController@inboundTelnyx')->name('inbound.telnyx')` at line 26;
Twilio — `dlr/twilio` (`:8`), `inbound/twilio/{gateway?}` (`:9`),
`inbound/twilio-copilot/{gateway?}` (`:11`); ~60 more provider `dlr/*`/`inbound/*`
routes at `:12-139`, all `Route::any`, all unauthenticated; Agency
Prospecting's own HMAC-token-protected pair at `:186-187`
(`webhooks/prospecting/{channelUid}/{token}/twilio|telnyx`). `routes/web.php`
(also `web`-only, no `auth`): a **second**, unnamed Telnyx route,
`Route::post('/inbound/telnyx', [DLRController::class,
'inboundTelnyx'])` (`:45`); a **third**, `Route::post('/telnyx/webhook',
[DLRController::class, 'inboundTelnyx'])` (`:73`) — this third route matches
none of `VerifyCsrfToken::$except`'s patterns (`app/Http/Middleware/VerifyCsrfToken.php:21-33`:
`inbound/*`, `dlr/*`, `webhooks/prospecting/*`, `stripe/webhook/usage-billing`,
`maintenance/notify`, payment callbacks), so as a bare `POST` under the `web`
group it is CSRF-rejected (419) in practice — dead/broken legacy code, not a
live third entry point. **All three still route to the exact same
fail-open handler** (item 7).

**(7) `DLRController` and its fail-open attribution.**
`app/Http/Controllers/Customer/DLRController.php`, 3,647 lines.
`inboundDLR()` signature, line 445: `public static function inboundDLR($to,
$message, $sending_server, $cost, $from = null, $media_url = null, int
$user_id = 1): JsonResponse|string` — the default parameter is
**literally `int $user_id = 1`**. Number resolution (`:500-511`):
`PhoneNumbers::where('number', $from)->where('status','assigned')->first()`,
falling back to a `LIKE "%$from%"` match; `$user_id` is reassigned **only**
inside `if ($phone_number)`. The `else` branch (`:917-934`, confirmed by full
read) unconditionally writes a `Reports::create([...'user_id' => $user_id,
'business_id' => app(LegacyBusinessResolver::class)->resolveForCustomer((int)
$user_id)?->id, ...])` row with the still-default `user_id = 1` when no
`PhoneNumbers` row matches — the fail-open default-to-user-1 defect, exactly
as flagged in the architecture-decision work this contract builds on.
`inboundTelnyx()` (`:1367-1461`) parses the Telnyx JSON payload, extracts
`to`/`from`/`text` from `data.payload`, resolves `$sendingServer` via
`getSendingServer(TYPE_TELNYX, ...)`, then calls `inboundDLR($to, $message,
$sendingServer, $cost, $from)` at `:1436` — **passing no `$user_id` argument
at all**, so the `= 1` default is live on every Telnyx inbound call, with
**no signature verification of any kind** before it (item 10).
`getSendingServer()` (`:968-975`) resolves the **first active** `SendingServer`
of the matching type/uid — no tenancy or Business scoping.

**(8) Number/Sender-ID-to-Business attribution today.**
`app/Models/PhoneNumbers.php` (215 lines): `business_id` is nullable,
added by `database/migrations/2026_09_05_120001_add_nullable_business_id_to_tenancy_tables.php`,
whose own docblock states it stays "nullable permanently for pooled/unassigned
numbers"; `number` (`database/migrations/2020_11_14_105312_create_phone_numbers_table.php`)
carries **no unique constraint**. `app/Models/Senderid.php` (190 lines): same
pattern — `business_id` nullable via the same 2026-09-05 migration,
`sender_id` (`database/migrations/2020_05_30_123429_create_senderid_table.php`)
also **no unique constraint**. Neither table can serve as an authoritative
number-to-Business mapping today.

**(9) Messaging Profile handling.** Grep-exhaustive: no dedicated
`messaging_profile_id` column, table, or model exists anywhere.
`EloquentSendingServerRepository.php:539,561` documents `'c1' =>
'messaging_profile_id'` as a human-readable label in a static metadata array
— not a real column. `SendCampaignSMS.php:1380,1429,14793,14840` and
`ProviderAgencyProspectingMessageSender.php:77` all read `$sending_server->c1`
into the outbound payload's `messaging_profile_id` field.
`MessagingChannelsController::ALLOWED_PROVIDERS[TYPE_TELNYX]` (`:56-63`)
surfaces this to the customer as `'c1' => ['label' => 'Message Profile ID',
'required' => true]`.

**(10) Existing webhook signature verification.** Twilio: real verification
exists, but only on the Agency Prospecting path —
`AgencyProspectingWebhookController::verifyTwilioSignature()` (`:110-127`)
uses `Twilio\Security\RequestValidator` against `X-Twilio-Signature`, called
before processing at `:55`. Telnyx: **no signature verification exists
anywhere in this repository.** `AgencyProspectingWebhookController::telnyx()`
(`:70-93`) only checks the URL-embedded `AgencyProspectingWebhookToken::isValid()`
HMAC token — a channel-identification scheme, not Telnyx's own Ed25519
payload-signing scheme (`telnyx-signature-ed25519`/`telnyx-timestamp`
headers, per Telnyx's own published webhook documentation). The legacy
`DLRController::inboundTelnyx()` performs no check of either kind.

**(11) Conversation/message persistence and provider-message identifiers.**
`app/Models/Reports.php` `$fillable` (`:34-52`) includes a single `status`
string column that packs `"{Status}|{provider_message_id}"` — e.g.
`SendCampaignSMS.php:1404`: `$get_sms_status = 'Delivered|' .
$get_response['data']['id'];`, later written via `Reports::create(['status'
=> $get_sms_status, ...])`; `DLRController::updateDLR()` (`:76,85`) reverses
this with `Reports::whereLike(['status'], $message_id)`. No queryable,
uniquely-constrained provider-message-ID column exists on `reports`. The
newer precedent, `agency_prospect_messages`
(`database/migrations/2026_09_06_120002_create_agency_prospect_messages_table.php`):
`provider_message_id` is `string()->nullable()` with a **dedicated
`unique()` constraint** (`:35,46`), documented in the migration as "the sole
idempotency key for inbound webhook delivery and outbound send results",
plus a separate nullable-unique `operation_key` for outbound-send
idempotency independent of the provider ID. This is the pattern Slice 3
follows (§4.2), not the legacy `reports.status` packing.

**(12) Usage-meter/reservation/commit/release seams.**
`app/Library/Usage/UsageWalletManager.php` (2,109 lines):
`reserve()` (`:285`), `commit()` (`:544`), `release()` (`:810`),
`setActiveRate()` (`:1083`), `activateMetering()` (`:1136`).
**`activateMetering(string $featureKey, ...)` composes `setActiveRate()` with
setting `is_metered = true` — it requires an active rate as a precondition.**
This is confirmed by full reading of RFC-005 §11's `setActiveRate()`
algorithm and §14's classification schema (`platform_feature_usage_classifications.is_metered`
+ nullable `active_rate_id`): there is no RFC-005 primitive for "metered but
priced at nothing." `EloquentCampaignRepository.php:770,824,830` shows the
one existing tie between a message send and reserve/commit/release — plain-SMS
conversation metering (RFC-005 Milestone 5), generic by `PlatformFeature`
key, not Telnyx-specific, gated by an `m5_conversations_usage_tracking` flag.
**Conclusion (unchanged from the pre-audit analysis): Slice 3's "measurement
without a rate" requirement cannot use `activateMetering()`/`setActiveRate()`
and needs its own lightweight, non-ledger, purely-operational log — this
document specifies it in §4.8.**

**(13) Existing encryption patterns.** `grep -rn "'encrypted'" app/Models/*.php`:
`app/Models/BusinessGoogleConnection.php:59` —
`'refresh_token_encrypted' => 'encrypted'` (column name carries the
`_encrypted` suffix by convention); `app/Models/PaymentProviderEvent.php:55`
— `'payload_encrypted' => 'encrypted'`. `SendCampaignSMS.php:13057`'s
`'encrypted' => false` is a payload-array literal, not an Eloquent cast (a
grep false positive, noted for completeness). **No credential column on
`SendingServer` or `CustomerBasedSendingServer` uses an `encrypted` cast
anywhere** — the `_encrypted`-suffixed-column + `'encrypted'` cast pattern
from `BusinessGoogleConnection`/`PaymentProviderEvent` is the only in-repo
precedent, and this document does not need it for the platform Telnyx
credential (§4.4 explains why: under Candidate B that credential is never
stored per-Business in a database row at all).

**(14) `config/services.php` and Telnyx env-key conventions.** Read in full
(125 lines). Relevant precedent shapes: `stripe` (`model/key/secret/webhook.secret/webhook.tolerance/mode/api_version`
— the closest structural precedent, with an explicit `mode` for test/live and
a `webhook` sub-array for the signing secret); `google_business_profile`
(`:98-102`), deliberately kept separate from the `google` Socialite block,
with a docblock (`:78-97`) explaining why — the precedent for keeping a
`telnyx` block structurally isolated from anything reused elsewhere.
**Confirmed: no `telnyx` key/block exists in `config/services.php`, and a
repository-wide grep for `env('TELNYX` / `TELNYX_` returns zero matches** —
no existing env-key convention for Telnyx exists; every current Telnyx
credential lives exclusively in `sending_servers`' generic DB columns.

**(15) Existing fake-provider precedents and container bindings.**
`app/Library/GoogleBusinessProfile/FakeGoogleBusinessProfileReadClient.php`
(323 lines) implements `GoogleBusinessProfileReadClient`, records calls,
exposes deterministic builders and one-shot failure injection. Default
production binding: `app/Providers/AppServiceProvider.php:199` —
`GoogleBusinessProfileReadClient::class => HttpGoogleBusinessProfileReadClient::class`,
inside a `$bindings` array applied via `$this->app->bind($interface,
$implementation)` (`:206`), with the binding's own comment: "tests swap
`FakeGoogleBusinessProfileReadClient` in via `app()->instance()`, exactly as
the Usage and AgencyProspecting suites do." Test-side override:
`tests/Feature/GoogleBusinessProfile/Concerns/CreatesGoogleBusinessProfileFixtures.php:49-55`
— `$this->app->instance(GoogleBusinessProfileReadClient::class, $this->fakeGoogle)`.
The equivalent messaging-adjacent precedent:
`AgencyProspectingMessageSender::class => ProviderAgencyProspectingMessageSender::class`
(`AppServiceProvider.php:193`), overridden per-test with
`FakeAgencyProspectingMessageSender` (`app/Library/AgencyProspecting/FakeAgencyProspectingMessageSender.php`,
39 lines, whose own docblock states it is "bound only inside the automated
test suite via container override in each test's own `setUp()` — never
`AppServiceProvider`'s default binding"). **This exact pattern — contract
interface, real default binding, deterministic `Fake*` swapped via
`app()->instance()` in test `setUp()` — is what Slice 3 reuses (§4.3, §4.4).**

**(16) `MessagingChannelsController` and its authorization/tenancy.**
`app/Http/Controllers/Customer/Business/MessagingChannelsController.php`,
424 lines, read in full. Verbatim class docblock (`:22-39`):

> B2 — Business Messaging Channels: a small, simple Business-level
> connect/manage experience for exactly two launch providers (Twilio,
> Telnyx), built on top of the existing, unmodified SendingServer backend.
>
> A "connection" is represented entirely with existing schema: Business ->
> CustomerBasedSendingServer -> SendingServer. A B2-created connection
> always gets its OWN dedicated, non-shared SendingServer row (never
> silently shared across Businesses); a pre-existing legacy/admin
> assignment that IS shared (or whose SendingServer's legacy owner isn't
> this Business's owner) is surfaced read-only ("Managed").
>
> Every action resolves its Business via the exact RFC-003 §14.1 boundary
> (WorkspaceRepository::findByUid()/businessesForWorkspace() +
> WorkspaceManager::userCanAccessBusiness()), mirroring
> OutreachController::resolveAccessibleBusiness() verbatim — never
> business.customer_id === Auth::id().

`ALLOWED_PROVIDERS` (`:48-64`): Twilio (`account_sid`, `auth_token`, both
required), Telnyx (`api_key` required, `c1` "Message Profile ID" required,
`c2` "Message Connection ID" optional). `resolveAccessibleBusiness()`
(`:381-396`) and `resolveOwnedConnection()` (`:409-415`) are the tenancy
boundary this document's BYO-relocation section (§4.7) restates and preserves
verbatim.

**(17) Routes/middleware needing to change.**
`routes/customer.php:388` — `channels` (`entry()`, `customer.channels.index`);
`routes/customer.php:975-983`, inside
`Route::prefix('{workspaceUid}/businesses/{businessUid}/channels')` —
`channels()`/`connect()`/`storeConnect()`/`show()`/`update()`/`enable()`/`disable()`.
Middleware, per `app/Providers/RouteServiceProvider.php:77-80`: `['web',
'auth', 'can:access_backend', 'ValidProduct', 'twofactor']` — a stark
contrast with the unauthenticated inbound webhook routes in item 6. Per the
§3 executability table below, `routes/customer.php` needs one narrow
addition (new routes only) for the BYO-relocated advanced-settings surface,
and `routes/public.php` needs one narrow addition (one new route line) for
the managed inbound webhook.

**(18) Avoiding real provider HTTP calls in tests.**
`tests/Feature/Business/MessagingChannelsTest.php` needs no fake/mock at all
today because `storeConnect()`/`update()` only persist credentials — they
never call Telnyx/Twilio. `tests/Feature/AgencyProspecting/AgencyProspectingRuntimeTest.php:65-68`
is the actual send-triggering precedent:
`$this->app->instance(AgencyProspectingMessageSender::class, new
FakeAgencyProspectingMessageSender())`. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`
and `tests/Feature/Usage/Support/concurrent_conversations_send_runner.php`
stub the legacy, seam-less `SendCampaignSMS::sendPlainSMS()` via an
anonymous-class method override / Mockery, because that god-class has no
interface to swap. **No `Http::fake()` guards a Telnyx/Twilio call anywhere
in the suites inspected.** Slice 3 follows the interface/container-swap
pattern (item 15) for all new code; it never needs to touch or stub
`SendCampaignSMS` because its delegation point sits upstream of it (§3, §4.5).

## 3. EXECUTABILITY AUDIT OF THE §22.1 SLICE 3 ALLOWLIST

| Required behaviour | Current production entry point | Implementation path required | Was it allowlisted before this document? | Why it must change | Test proving delegation |
|---|---|---|---|---|---|
| Fail-closed inbound/DLR attribution for **managed** Telnyx traffic | `DLRController::inboundTelnyx()`/`inboundDLR()`, reached by `routes/public.php:26` (and the dead duplicates in `routes/web.php:45,73`) | New method on `DLRController` (fail-closed, signature-verified, Messaging-Profile-ID-keyed) + one new route | **No** — `DLRController.php` and `routes/public.php` were absent from the row | Building a new resolver in `app/Library/Messaging/**` that nothing routes to would prove nothing about production traffic; the exit criterion is "replacing fail-open DLR attribution," not adding an unused alternative | §4.12 T-MSG-14 (POST the real route with a valid signed managed payload and assert `BusinessMessagingIdentity`-scoped attribution; a legacy fail-open write to `Reports` with `user_id=1` never occurs for a managed identity) |
| Managed outbound dispatch actually used by real sends | `EloquentCampaignRepository::quickSend()` (`:421,441,446`, `:1826,1830,1834`), `Campaigns`'s own switch (`Campaigns.php:979,983,987`) | Pre-dispatch managed-identity resolution/delegation inserted before each switch | **No** — neither file was in the row | Without this, a Business with a managed `BusinessMessagingIdentity` would still be routed through the legacy raw-cURL Telnyx case at send time, and Slice 3's adapter/isolation code would only ever run inside its own tests | §4.12 T-MSG-9/T-MSG-10 (call `quickSend()`/`Campaigns`'s builder for a Business with an active managed identity and assert the fake adapter, not `SendCampaignSMS`'s Telnyx case, received the call) |
| Authoritative number-to-Business resolution | None — `PhoneNumbers`/`Senderid` (`business_id` nullable, no unique constraint on `number`/`sender_id`) | New `business_messaging_identities` table, owning `phone_number` and `messaging_profile_id` directly, each unique | **Yes** — `app/Models/BusinessMessagingIdentity.php` (new) and `database/migrations/**` were already allowlisted | No change needed beyond what was already permitted | §4.12 T-MSG-1/T-MSG-2 |
| Real Telnyx adapter wiring | None | `TelnyxMessagingAdapter` in `app/Library/Messaging/**` | **Yes** — already allowlisted | No change needed | §4.12 T-MSG-6/T-MSG-7 |
| Encrypted credential handling | N/A — the single platform credential is never a per-Business DB row under Candidate B (§4.4) | `config/services.php` (`telnyx` block) + `config/messaging.php` (new) | **Yes** — already allowlisted | No change needed | §4.12 T-MSG-15/T-MSG-16 |
| Usage measurement without an active rate | RFC-005's `activateMetering()` (requires a rate) — unusable as-is (item 12) | New `business_messaging_usage_events` operational log, outside `UsageWalletManager` | **Yes** — `database/migrations/**` already allowlisted for Slice 3; the log lives in `app/Library/Messaging/**` | No change needed | §4.12 T-MSG-19/T-MSG-20 |
| BYO relocation to Agency-only advanced settings | `MessagingChannelsController` + `routes/customer.php:388,975-983` | New routes under `resources/views/customer/settings/advanced/**`'s controller surface | **Partially** — the controller and the view directory were allowlisted; `routes/customer.php` was not | The new advanced-settings routes are unreachable without a route file change | §4.12 T-MSG-24 |
| Isolation tests against real production entry points, not an unused new service | (both entry points above) | `tests/Feature/Business/**` additions alongside `tests/Feature/Messaging/**` | **Partially** — `tests/Feature/Messaging/**` was allowlisted; `tests/Feature/Business/**` was not | `MessagingChannelsTest.php` already lives in `tests/Feature/Business/**`; the BYO-relocation and production-delegation tests are its natural extension | §4.12 T-MSG-9/T-MSG-10/T-MSG-14/T-MSG-24 |

**Conclusion: the §22.1 Slice 3 row as originally written was not
executable** against two of its own already-locked §21.1 exit criteria
(fail-closed inbound attribution; per-Business isolation "implemented and
verified," not merely designed against a fake). The narrow correction applied
to the parent contract (§22.1, dated 2026-09-09) adds exactly five
implementation paths and one test path, each scoped to a single insertion
point proven necessary above, and touches no other line of the two
god-classes (`DLRController.php`, `SendCampaignSMS.php`) that everything else
in this document deliberately leaves alone. No broad `app/Http/Controllers/**`,
`routes/**`, or `app/Repositories/**` glob was used anywhere in the
correction — every path is named exactly.

## 4.1 EXACT SCOPE

**Included:**

* Provider-neutral contracts/DTOs/enums/exceptions in `app/Library/Messaging/Contracts/**` and `app/Enums/Messaging/**` (§4.3).
* A deterministic fake adapter (`FakeMessagingAdapter`) and a production-shaped Telnyx adapter (`TelnyxMessagingAdapter`) implementing the same interface (§4.3).
* `BusinessMessagingIdentity` (new model + migration) — the provider-neutral, authoritative per-Business Messaging-Profile-and-number mapping (§4.2).
* Encrypted-nowhere-needed platform credential custody: the single shared Telnyx credential lives in env/config only, never a per-Business database row (§4.4).
* Fail-closed inbound/DLR attribution for managed Telnyx traffic, wired to the real `DLRController` entry point (§4.6).
* Provider error normalization (`ProviderErrorCategory`: retryable vs terminal vs configuration) (§4.3).
* Safe container binding of the adapter interface, following the `GoogleBusinessProfileReadClient`/`AgencyProspectingMessageSender` precedent (§4.4).
* BYO relocation of `MessagingChannelsController` to Agency-only advanced settings, restating the superseded B2 docblock rules verbatim (§4.7).
* Messaging-usage measurement outside RFC-005's wallet/rate/reservation machinery, with no active retail rate (§4.8).
* Migration/compatibility behaviour for every existing Twilio/Telnyx BYO connection, sending server, campaign, conversation, message, automation, sender ID and phone-number record (§4.10).
* Isolation and credential-non-disclosure tests against the real production entry points identified in §3, not only new helper classes (§4.12).

**Excluded (deferred or never in scope for Slice 3):**

* Production number purchasing, production 10DLC registration submission, production customer sending, retail rate activation, customer wallet-funded provisioning, phone-number lifecycle/renewal/grace — all Slice 4 (gated by §28.4, §28.1, Slice 5 per the parent contract, unchanged by this document).
* Default-sender automation resolution — Slice 6.
* New automation-trigger/outbox producers — Slice 8.
* Managed Accounts (the future, owner-evaluated migration target, §28.3) — not built, not interfaced, not referenced by any schema column.
* Customer-accessible platform credentials — never built, in any slice.
* Live Telnyx calls during development or automated tests — the fake adapter is the only one exercised by the test suite; the real adapter's own tests assert that missing/absent configuration produces zero HTTP calls (§4.12 T-MSG-16).
* **Slice-4-shaped interface methods (number search/order, registration submission) are not supplied at all in Slice 3 — not as inert stubs, not as dead code paths.** `MessagingProviderAdapter` (§4.3) defines exactly two methods: `send()` and `verifyInboundSignature()`/webhook parsing. Number and registration operations are Slice 4's own interface extension, authored and authorized by Slice 4's own contract when its gates clear. This is a narrower, safer choice than shipping inert methods that could later be miswired: there is no method to accidentally call.
* Fixing the pre-existing, unauthenticated, fail-open inbound behaviour for **BYO** Telnyx/Twilio traffic (the other ~59 providers' identical `dlr/*`/`inbound/*` pattern, and Telnyx/Twilio BYO's own use of the same legacy route) is **not** a Slice 3 exit criterion. BYO customers configure their own Telnyx/Twilio account's webhook URL against `inbound/telnyx/{gateway?}`/`inbound/twilio/{gateway?}`, which stays exactly as it is today. This is a known, disclosed, unresolved legacy condition — stated honestly per the parent contract's own §11.1 practice, not silently carried forward as if fixed. Only the new, additional, managed-only route (§4.6) is fail-closed.

## 4.2 EXACT SCHEMA

### `business_messaging_identities` (new table)

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint, PK | no | — | |
| `uid` | string(36) | no | — | `Str::uuid()`, **unique**. Never `uniqid()` — the repository's own `Business.uid` is already documented elsewhere (`routes/public.php:190-197`'s Website-generation comment) as an unsafe precedent because `uniqid()` is not a real UUID; this column does not repeat that mistake. |
| `business_id` | unsigned bigint, FK -> `businesses.id`, `restrictOnDelete()` | **no** | — | Unlike `phone_numbers.business_id`/`senderid.business_id`, this column is **never** nullable — every row is authoritatively owned by exactly one Business from creation. |
| `provider` | string, cast to `App\Enums\Messaging\MessagingProvider` | no | — | Exactly one case at launch: `TELNYX`. Enum, not a free string, so a future provider is an additive enum case, never a schema change. |
| `status` | string, cast to `App\Enums\Messaging\BusinessMessagingIdentityStatus` | no | `pending` | `pending` \| `active` \| `suspended` \| `archived`. |
| `messaging_profile_id` | string | no | — | The Telnyx Messaging Profile ID. **Unique.** Authoritative inbound-attribution key (§4.6). Admin-visible only (§11.3 of the parent contract) — never rendered to any customer-role view or serialization. |
| `phone_number` | string, E.164 | no | — | **Unique.** One provider phone number belongs to at most one Business, enforced at the database level, not only in application code. |
| `messaging_connection_id` | string | yes | `null` | Mirrors the legacy `c2` "Message Connection ID" optional field; opaque, admin-visible only. |
| `activated_at` | timestamp | yes | `null` | Set when `status` transitions to `active`. |
| `archived_at` | timestamp | yes | `null` | Set when `status` transitions to `archived`. |
| `created_at`, `updated_at` | timestamp | — | — | Standard Eloquent timestamps. |

**Indexes/constraints:** `UNIQUE(uid)`, `UNIQUE(messaging_profile_id)`,
`UNIQUE(phone_number)`, index on `business_id`, `UNIQUE(business_id) WHERE
status != 'archived'` expressed as a partial/application-enforced uniqueness
rule (see below) — **one active-or-pending managed identity per Business**.
Because not every supported database enforces partial unique indexes
identically, the migration adds a plain non-unique index on
`(business_id, status)` and the **authoritative** one-non-archived-identity-per-Business
rule is enforced inside `BusinessMessagingIdentityResolver::create()`
(§4.3) via a `DB::transaction()` + `lockForUpdate()` existence check before
insert, with the database's `UNIQUE(messaging_profile_id)`/`UNIQUE(phone_number)`
constraints as the hard backstop against a concurrent race producing two rows
for the same profile or number (§4.9).

**Deliberately absent, per the parent contract's provider-neutrality
discipline (§21.2) and the original task's explicit lock:** no
`provider_managed_account_id` column; no dormant/nullable
Managed-Account-shaped column of any kind; no customer-visible platform
credential column; no generic JSON "extra data" escape-hatch column. If a
future dedicated-account mode is ever authorized, it adds its own
provider-neutral column (e.g. `provider_account_reference`, never
Telnyx-product-named) through its own additive migration at that time — this
migration does not pre-build for it.

**No credential column exists on this table at all.** Under Candidate B
there is exactly one platform Telnyx credential, shared by every managed
Business; it is never stored per-Business (§4.4).

### `business_messaging_usage_events` (new table — operational measurement, not a ledger)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint, PK | no | |
| `business_id` | unsigned bigint, FK -> `businesses.id`, `restrictOnDelete()` | no | |
| `business_messaging_identity_id` | unsigned bigint, FK -> `business_messaging_identities.id`, nullable, `nullOnDelete()` | yes | `null` for BYO transport (BYO never has a managed identity row). |
| `transport_mode` | string, cast to `App\Enums\Messaging\MessagingTransportMode` | no | `managed` \| `byo`. |
| `provider` | string, cast to `MessagingProvider` | no | The transport's actual provider, for BYO too (e.g. a BYO Twilio send still records `TWILIO`). |
| `direction` | string | no | `outbound` \| `inbound`. |
| `message_type` | string | no | `sms` \| `mms`. |
| `segment_count` | unsigned int | no | |
| `provider_message_id` | string | yes | Nullable (a rejected/failed outbound send may never receive one). |
| `occurred_at` | timestamp | no | |
| `created_at` | timestamp | no | |

**Indexes/constraints:** `UNIQUE(provider, provider_message_id) WHERE
provider_message_id IS NOT NULL` (composite, so Telnyx's and Twilio's ID
spaces never collide); index on `(business_id, occurred_at)` for reporting
queries. **This table has no `rate_id`, no ledger `entry_type`, no wallet
reference of any kind** — it is intentionally outside RFC-005's
`business_usage_reservations`/`business_usage_ledger_entries` schema (§4.8).

### Uniqueness/conflict rules, stated exactly

* **One active-or-pending managed identity per Business.** Enforced by the
  transactional check in `BusinessMessagingIdentityResolver::create()` plus
  the two hard unique constraints above. A second creation attempt for a
  Business that already has a non-archived identity fails closed with
  `MessagingIdentityConflictException`, before any provider call.
* **One Messaging Profile mapping per managed Business.** The
  `UNIQUE(messaging_profile_id)` constraint makes this a database-level
  guarantee, not merely an application check.
* **One provider phone number belongs to at most one Business.** The
  `UNIQUE(phone_number)` constraint, same guarantee.
* **BYO identity cardinality** is unchanged from today:
  `CustomerBasedSendingServer` already permits at most one non-managed
  dedicated connection per Business per provider type, per
  `MessagingChannelsController::storeConnect()`'s existing behaviour (item 16)
  — Slice 3 does not alter this.
* **A Business can never have both a managed identity and an active BYO
  connection treated as ambiguously "the" sender for the same operation.**
  §4.5's resolver checks the managed identity first; if one exists and is
  `active`, BYO connections for that Business are inert for platform-initiated
  managed sends (BYO remains reachable only through its own relocated,
  explicitly-selected advanced-settings surface, §4.7) — there is no
  automatic "try BYO if managed fails" fallback, which would silently and
  unpredictably change which account originates a message.

### Forward migration/backfill/compatibility/`down()`

* Both new tables are created by ordinary additive migrations. Neither
  migration touches, backfills, or reads from `sending_servers`,
  `customer_based_sending_servers`, `phone_numbers`, or `senderid` — **no
  existing BYO credential is ever converted into a managed identity**, silently
  or otherwise. A managed `BusinessMessagingIdentity` row is created only
  through `BusinessMessagingIdentityResolver::create()`, called only by
  Slice 4's (future) provisioning flow or by a test's fixture factory — never
  by a migration.
* `down()` on both migrations is unconditional `Schema::dropIfExists()` — safe
  because nothing else in the schema has an FK pointing *into* either new
  table from outside `app/Library/Messaging/**`'s own code, and both tables
  are new in this slice (no pre-existing data to preserve on rollback).

## 4.3 PROVIDER-NEUTRAL INTERFACES

All under `app/Library/Messaging/` unless noted.

**`Contracts\MessagingProviderAdapter`** (interface):

```php
interface MessagingProviderAdapter
{
    public function send(OutboundMessageRequest $request): OutboundMessageResult;

    public function verifyInboundSignature(string $rawBody, array $headers): bool;

    public function parseInboundWebhook(string $rawBody): InboundWebhookEvent;
}
```

* `send()` — the only outbound operation this interface exposes. No
  number-search, number-order, or registration-submission method exists on
  this interface in Slice 3 (§4.1).
* `verifyInboundSignature()` — provider-specific cryptographic verification
  (Ed25519 for Telnyx, per Telnyx's own published `telnyx-signature-ed25519`/`telnyx-timestamp`
  scheme). Returns `false` — never throws — on any missing header, malformed
  signature, or timestamp outside an allowed skew window, so the caller's
  fail-closed branch is always the same code path (§4.6).
* `parseInboundWebhook()` — turns a verified raw body into an
  `InboundWebhookEvent` (below). Called only after `verifyInboundSignature()`
  returns `true`.

**DTOs** (`app/Library/Messaging/DTO/`, plain readonly value objects, no
Eloquent):

* `OutboundMessageRequest` — `businessMessagingIdentityId: int`, `toNumber:
  string`, `fromNumber: string`, `body: string`, `mediaUrls: array`,
  `idempotencyKey: string`.
* `OutboundMessageResult` — `accepted: bool`, `providerMessageId: ?string`,
  `status: MessageDispatchStatus`, `errorCategory: ?ProviderErrorCategory`.
  Carries no raw provider response body (never logged/persisted verbatim —
  §4.4's no-credential-in-logs rule extends to not persisting arbitrary
  provider payloads that could carry a credential-adjacent value).
* `InboundWebhookEvent` — a closed union expressed as a single DTO with a
  `kind` enum (`MESSAGE_RECEIVED` \| `DELIVERY_STATUS`), plus
  `messagingProfileId`, `fromNumber`, `toNumber`, `body`, `mediaUrls`,
  `providerMessageId`, `deliveryStatus` (nullable, populated only for
  `DELIVERY_STATUS`), `occurredAt`.

**Enums** (`app/Enums/Messaging/`):

* `MessagingProvider` — `TELNYX` (BYO's existing `TYPE_TWILIO`/`TYPE_TELNYX`
  constants on `SendingServer` are untouched and unrelated to this enum,
  which exists only for the new managed-identity/adapter code).
* `BusinessMessagingIdentityStatus` — `PENDING`, `ACTIVE`, `SUSPENDED`, `ARCHIVED`.
* `MessagingTransportMode` — `MANAGED`, `BYO`.
* `MessageDispatchStatus` — `ACCEPTED`, `REJECTED`.
* `ProviderErrorCategory` — `RETRYABLE`, `TERMINAL`, `CONFIGURATION`, `UNKNOWN`.
  `CONFIGURATION` is what a missing/incomplete platform credential produces
  (§4.4) — distinct from a Telnyx-side rejection, so callers and tests can
  distinguish "we are not configured" from "Telnyx said no."

**Exceptions** (`app/Library/Messaging/Exceptions/`):

* `MessagingProviderNotConfiguredException` — thrown before any HTTP call
  when required config is absent/incomplete (§4.4).
* `MessagingIdentityUnresolvedException` — no matching, unambiguous
  `BusinessMessagingIdentity` for a given resolution key.
* `MessagingIdentityConflictException` — two or more candidates found (a
  create-time race, or corrupted data) — always fails closed, never picks
  "the first match."

**Implementations:**

* `TelnyxMessagingAdapter implements MessagingProviderAdapter` — the
  production-shaped adapter. Constructed with the platform credential read
  from `config('services.telnyx')` (§4.4); throws
  `MessagingProviderNotConfiguredException` in its constructor if any
  required key is absent, so a misconfigured container can never produce a
  live HTTP call. Uses Laravel's `Http` facade (the
  `ProviderAgencyProspectingMessageSender` precedent, item 1), never raw
  `curl`.
* `FakeMessagingAdapter implements MessagingProviderAdapter` — deterministic,
  in-memory, records every call (`public array $sentMessages`, `public array
  $verifiedWebhooks`), exposes builder methods to script a rejection/error
  category per call, and a `queueInboundWebhook()` helper tests use to
  synthesize `InboundWebhookEvent`s without a real signature. Mirrors
  `FakeGoogleBusinessProfileReadClient`'s shape (item 15) and, like
  `FakeAgencyProspectingMessageSender`, is bound **only** inside test
  `setUp()` via `$this->app->instance(MessagingProviderAdapter::class, ...)`
  — never `AppServiceProvider`'s default binding (§4.4).

**Orchestration** (not adapters — application services):

* `BusinessMessagingIdentityResolver` — `resolveForBusiness(Business
  $business): ?BusinessMessagingIdentity` (active identity only),
  `resolveByMessagingProfileId(string $id): ?BusinessMessagingIdentity`
  (throws `MessagingIdentityConflictException` if the unique constraint is
  ever violated at the data layer, which should be structurally impossible
  but is checked anyway), `create(Business $business, ...): BusinessMessagingIdentity`
  (the transactional create-with-lock described in §4.2).
* `ManagedMessageDispatcher` — the single orchestration point called by both
  new delegation sites (§4.5).
* `InboundWebhookAttributionResolver` — the single orchestration point called
  by the new `DLRController` method (§4.6).
* `MessagingUsageRecorder` — appends to `business_messaging_usage_events`
  (§4.8), called by both `ManagedMessageDispatcher` (outbound) and
  `InboundWebhookAttributionResolver` (inbound), and separately by the BYO
  send path (§4.7) for BYO's own measurement-only entries.

**Provider acceptance vs local persistence.** `ManagedMessageDispatcher` never
writes a `Reports`/conversation row claiming "sent" before
`TelnyxMessagingAdapter::send()` returns. `OutboundMessageResult::$accepted`
is `true` only when the adapter's own HTTP response indicates Telnyx accepted
the message; a local database write is never itself treated as proof of
provider acceptance, and delivery state (`delivered`/`failed`) is written
only from an authenticated inbound `DELIVERY_STATUS` webhook event, never
inferred from the outbound response alone.

**Platform vocabulary.** Every DTO/enum/exception name above is
provider-neutral (`OutboundMessageRequest`, not `TelnyxSendRequest`;
`MessagingProviderAdapter`, not `TelnyxClient`). Telnyx-specific vocabulary
(`messaging_profile_id`, Ed25519 signature headers) exists only inside
`TelnyxMessagingAdapter`'s implementation and nowhere in the interface,
DTOs, or database column names visible to generic reporting.

## 4.4 CONFIGURATION AND CREDENTIAL CUSTODY

**`config/services.php` — new `telnyx` block** (keys only, no values):

```php
'telnyx' => [
    'active' => env('TELNYX_ACTIVE', false),
    'api_key' => env('TELNYX_API_KEY'),
    'webhook_public_key' => env('TELNYX_WEBHOOK_PUBLIC_KEY'),
    'mode' => env('TELNYX_MODE', 'sandbox'), // 'sandbox' | 'live' — mirrors the existing stripe.mode precedent
],
```

Kept as its own top-level key, structurally isolated from anything else
(the `google_business_profile`-vs-`google` precedent, item 14) — never
merged with any BYO-related config, since BYO never uses platform config at
all (its credentials live exclusively in `sending_servers` DB rows, §4.7).

**`config/messaging.php` (new)** — non-secret behavioural configuration only:
default managed provider (`telnyx`), whether managed messaging is enabled at
all (a platform-wide kill switch, distinct from any one Business's status),
and the operational-measurement table name if ever needed for a future
read-model — no credential-shaped key belongs in this file.

**Loading and fail-closed behaviour.**

* `TelnyxMessagingAdapter`'s constructor checks `config('services.telnyx.active')`
  and the presence of `api_key`/`webhook_public_key`; if either required key
  is empty, it throws `MessagingProviderNotConfiguredException`
  **immediately, before any HTTP call is possible** — there is no code path
  in this adapter that can reach an HTTP client with a missing credential.
* `ManagedMessageDispatcher` catches that exception and returns a
  `MessageDispatchStatus::REJECTED` with `ProviderErrorCategory::CONFIGURATION`
  to its caller — a clearly reported failure, never a false "sent"
  and never an uncaught exception that would crash the enclosing send flow.
* **No committed secret anywhere.** No credential value appears in this
  document, in `config/messaging.php`, or in any migration/seeder. Only
  `.env`-supplied values populate `services.telnyx.*`.
* **No credential in views/logs/events/exceptions/serialized jobs/test
  snapshots.** `TelnyxMessagingAdapter` never logs its own `api_key`;
  exception messages thrown by it name the missing config **key**, never its
  value (`"services.telnyx.api_key is not configured"`, never the key
  itself); no Job class serializes the adapter instance (adapters are
  resolved fresh from the container inside the job's `handle()`, the same
  pattern `AgencyProspectingInitialSendJob` already uses for its sender
  contract).
* **Tests cannot accidentally use real env credentials.** Every test that
  exercises `ManagedMessageDispatcher` binds `FakeMessagingAdapter` via
  `$this->app->instance(MessagingProviderAdapter::class, new
  FakeMessagingAdapter())` in `setUp()` — the real `TelnyxMessagingAdapter`
  is never resolved from the container during the test suite's run
  (`phpunit.xml`'s environment already unsets provider-shaped env vars for
  the test environment, consistent with how no `TELNYX_*` key exists in the
  repository's `.env.example` either). A dedicated test
  (`TelnyxAdapterConfigurationTest`, §4.12 T-MSG-16) additionally asserts
  that constructing `TelnyxMessagingAdapter` directly with empty config
  throws before any `Http::fake()`-recorded request is made, closing the
  gap even for a test that deliberately tries to exercise the real class.
* **Platform credentials are never stored per-Business under Candidate B.**
  There is exactly one Telnyx API key for the whole platform (Candidate B,
  locked); `business_messaging_identities` (§4.2) carries no credential
  column at all — only opaque, non-secret resource identifiers
  (`messaging_profile_id`, `phone_number`). This is a stronger guarantee than
  "encrypted at rest," because the value is structurally absent from every
  Business-scoped row and therefore cannot leak through any per-Business
  query, export, or serialization path.
* **BYO credentials stay isolated to the advanced path, with its existing
  storage mechanism unchanged.** `CustomerBasedSendingServer`/`SendingServer`
  remain exactly as they are (item 4/13) — **Slice 3 does not add an
  `encrypted` cast to `SendingServer`'s credential columns.** This is a
  deliberate, narrow scope decision, not an oversight: `SendingServer` is a
  ~250-provider-type shared legacy model outside Slice 3's allowlist (per
  the B2 docblock's own "existing, unmodified SendingServer backend" rule,
  item 16), and adding encryption there would be a schema change affecting
  every one of those ~250 provider types, not a Slice-3-scoped, narrowly
  evidenced change. **Stated honestly, per the parent contract's §11.1
  practice: BYO Telnyx/Twilio credentials remain stored in plaintext in the
  `sending_servers` table after Slice 3, exactly as they are today.** This is
  a disclosed, carried-forward legacy condition, not a claim of encryption
  that isn't true.
* **Config-cache behaviour.** `config/services.php` and `config/messaging.php`
  are ordinary Laravel config files — `php artisan config:cache` behaves
  identically to every other provider block already in `services.php`; no
  special-cased runtime config reload is introduced. A test
  (§4.12 T-MSG-17) asserts that `TelnyxMessagingAdapter` reads its
  configuration once at construction (not re-reading `env()` directly at
  call time, which config-caching would break) — the existing `stripe`/`google_business_profile`
  blocks already establish this as the repository's convention.

## 4.5 OUTBOUND ISOLATION

**Full flow:**

1. **Business resolution.** The caller (either new delegation point, §3) has
   an already-tenancy-resolved `Business` model (both `EloquentCampaignRepository::quickSend()`
   and `Campaigns`'s dispatch switch already receive a `Business`-scoped
   `Campaigns`/`Automation` context — no new tenancy resolution is invented
   here, the existing one is reused).
2. **Identity resolution.** `BusinessMessagingIdentityResolver::resolveForBusiness($business)`.
   Returns `null` if no managed identity exists, or if one exists but is not
   `active` (`pending`/`suspended`/`archived` all resolve to `null` here,
   never to a partially-usable identity). A `null` result means: fall through
   to the legacy/BYO path unchanged — **no managed-path code runs, no
   provider call of any kind occurs.**
3. **Authorization/status check.** Already folded into step 2 (`active`-only
   resolution) — there is no separate authorization step that a forged
   identifier could bypass, because the resolver never accepts a
   caller-supplied identity ID for the outbound path; it always re-resolves
   from the tenancy-verified `Business` model itself. **A forged
   `business_messaging_identity_id` cannot reach `send()` on the outbound
   side at all** — the only way `OutboundMessageRequest::$businessMessagingIdentityId`
   is populated is from the resolver's own return value, never from request
   input.
4. **Messaging Profile + number selection.** Read directly off the resolved
   `BusinessMessagingIdentity` row (`messaging_profile_id`, `phone_number`)
   — no separate lookup, no "first active number" fallback of any kind.
5. **Usage measurement/reservation.** `MessagingUsageRecorder::recordOutboundAttempt(...)`
   is called **before** the adapter call, recording the attempt; on
   `REJECTED` the record is updated (not deleted — the operational log keeps
   failed attempts for audit) rather than reversed like a wallet reservation,
   since this is a measurement log, not a financial reservation (§4.8). No
   RFC-005 `reserve()`/`commit()`/`release()` call is made for managed
   telecom transport in Slice 3 (no active rate exists to reserve against).
6. **Provider adapter.** `MessagingProviderAdapter::send($request)` —
   `TelnyxMessagingAdapter` in production, `FakeMessagingAdapter` in tests.
7. **Provider-confirmed acceptance.** Only `OutboundMessageResult::$accepted
   === true` is treated as a successful send. `$providerMessageId` (from the
   adapter's own response) is what gets persisted as the outbound message's
   provider identifier — never a locally-generated placeholder.
8. **Local persistence/settlement.** The existing conversation/message
   persistence for the calling flow (whatever `quickSend()`/`Campaigns`
   already writes for a successful send) proceeds using the confirmed
   `$providerMessageId`, following the `agency_prospect_messages.provider_message_id`
   unique-column precedent (item 11), not the legacy `reports.status`
   string-packing pattern, for any new managed-send persistence Slice 3
   introduces.

**Proofs:**

* **Business A can never send through Business B's number/profile.** Step 3's
  re-resolution from the tenancy-verified `Business` model, combined with
  `UNIQUE(messaging_profile_id)`/`UNIQUE(phone_number)` at the database
  level, makes this a structural guarantee, not a runtime check that could be
  skipped. T-MSG-11 (§4.12).
* **Forged IDs fail before provider invocation.** Covered in step 3 above —
  there is no request-input path into `send()`'s identity selection at all.
  T-MSG-12.
* **Inactive/suspended/archived/missing/conflicted identities make zero
  provider calls.** Step 2's `null`-on-non-active resolution, verified with
  `FakeMessagingAdapter::callCount() === 0` assertions per status value.
  T-MSG-13.
* **No fallback to an arbitrary first sending server or number.** There is no
  "first active `SendingServer`" call anywhere in the managed path — that
  pattern (`DLRController::getSendingServer()`, item 7) exists only in the
  legacy inbound flow this document does not touch for BYO, and is never
  reused for managed outbound resolution.
* **Provider request construction uses only the resolved Business identity.**
  `OutboundMessageRequest` is built exclusively from the `BusinessMessagingIdentity`
  row and the message content already validated by the calling flow — no
  other Business's data is read during construction.
* **Platform-wide credential failure is reported honestly, never becomes
  false per-Business success.** §4.4's `MessagingProviderNotConfiguredException`
  → `ProviderErrorCategory::CONFIGURATION` path guarantees this: a
  misconfigured platform credential makes every managed send fail the same
  way, visibly, never silently.
* **No retail rate activation.** Step 5 never calls `setActiveRate()`/`activateMetering()`.

## 4.6 INBOUND/DLR FAIL-CLOSED ROUTING

**Complete production route/controller/service path (new, managed-only):**

* New route, `routes/public.php` (one new line, under the already
  CSRF-exempt `inbound/*` prefix per `VerifyCsrfToken::$except`, item 6 —
  **no change to `app/Http/Middleware/VerifyCsrfToken.php` is needed**):
  `Route::post('inbound/telnyx-managed', 'Customer\DLRController@inboundTelnyxManaged')->name('inbound.telnyx_managed');`
* New method, `DLRController::inboundTelnyxManaged(Request $request)` — the
  **only** new method added to this 3,647-line controller. Every one of its
  other ~60 provider methods, including the existing `inboundTelnyx()`/`inboundDLR()`
  pair (left serving BYO Telnyx traffic exactly as today, §4.1), is
  untouched.
* `inboundTelnyxManaged()` delegates immediately to
  `InboundWebhookAttributionResolver::handle($request)` — the controller
  method itself contains no attribution logic.

**`InboundWebhookAttributionResolver::handle()` — resolution priority, exactly:**

1. **Signed webhook verification, first, always.** `MessagingProviderAdapter::verifyInboundSignature($rawBody, $headers)`
   against the platform's own known Telnyx Ed25519 public key
   (`config('services.telnyx.webhook_public_key')`). A missing, malformed, or
   invalid signature, or a timestamp outside the allowed skew window, is
   rejected **before any payload parsing** — no conversation/message update,
   no wallet debit, no automation trigger, and the response is a deliberately
   contracted `403` with no body identifying why (never a `200` with an
   internal error, which would discourage Telnyx's own retry logic without
   actually meaning success).
2. **Payload parse.** Only after verification succeeds,
   `MessagingProviderAdapter::parseInboundWebhook($rawBody)` produces an
   `InboundWebhookEvent`.
3. **Provider message ID replay check.** For a `MESSAGE_RECEIVED` event,
   `business_messaging_usage_events`'s `UNIQUE(provider, provider_message_id)`
   constraint (§4.2) is checked (via a `firstOrCreate`-style guarded insert
   inside a transaction) before any further processing — a duplicate
   delivery of the same webhook is a no-op past this point, not a second
   conversation write (§4.9).
4. **Messaging Profile ID resolution — the sole attribution key.**
   `BusinessMessagingIdentityResolver::resolveByMessagingProfileId($event->messagingProfileId)`.
   This is the **only** signal used to attribute the inbound event to a
   Business. It is never combined with, or overridden by, the destination
   phone number, the source phone number, a URL path segment, or any
   payload field a sender could influence beyond what Telnyx itself
   populates from the receiving Messaging Profile's own configuration.
5. **Conflict/ambiguity handling.** If `resolveByMessagingProfileId()` returns
   `null` (no matching, non-archived `BusinessMessagingIdentity`) or throws
   `MessagingIdentityConflictException` (which the `UNIQUE(messaging_profile_id)`
   constraint should make structurally impossible, but is still checked),
   the resolver fails closed exactly as in step 1: no conversation/message
   update, no wallet debit, no automation trigger, and a deliberately
   contracted response (`200` with an explicit "unattributed" marker is
   acceptable for this specific case only, since an unknown-but-signature-valid
   Telnyx event is not itself malicious and re-delivery would not help — this
   is the one place a `200` is correct despite no processing occurring,
   and it is recorded as such in the bounded audit log below, never silently).
6. **Bounded audit path.** Every rejected step (1, 3-duplicate, 5-unattributed)
   writes one row to `business_messaging_usage_events` (§4.2) tagged
   appropriately, or, if richer detail is needed later, a dedicated
   `messaging_webhook_rejections` log table scoped narrowly to this
   controller method — **never** a write to `Reports`, `ChatBox`, or any
   conversation table, and never a write attributable to a guessed
   `user_id`/Business.
7. **Never** a global-first-match, an optional Business fallback, a
   user-provided Business ID, or an unauthenticated payload field of any
   kind. There is no `getSendingServer()`-style "first active" call anywhere
   in this path.

**Delivery-status attribution.** A `DELIVERY_STATUS` event follows the same
steps 1-2, then resolves by `provider_message_id` against
`business_messaging_usage_events` (never against the legacy `reports.status`
string-packing pattern) — an unknown `provider_message_id` is treated
identically to step 5's unattributed case.

**Retry-storm avoidance.** Telnyx retries a webhook that does not receive a
`2xx` within its own timeout. Step 1's rejection returns `403` (a
Telnyx-side "do not retry, this endpoint refused you" signal, appropriate
since a bad signature will never become valid on retry) while step 5's
unattributed case returns `200` (correct because retrying an
already-fully-processed-but-unattributable event would only repeat the same
outcome, never resolve it) — this distinction is deliberate, not
inconsistent.

**Test against the real production entry point, not only the resolver
class.** §4.12 T-MSG-14 issues an actual `POST` to
`route('inbound.telnyx_managed')` with a fully-constructed, validly-signed
Telnyx-shaped payload and asserts the resulting `BusinessMessagingIdentity`-scoped
side effects — never calling `InboundWebhookAttributionResolver::handle()`
directly as a unit-test shortcut for this particular assertion.

## 4.7 BYO RELOCATION

Restating the superseded B2 docblock rules (`MessagingChannelsController.php:22-39`,
item 16) exactly, as required by the parent contract's §27 C-5 (no B2
contract document exists to correct in place):

> A "connection" is represented entirely with existing schema: Business ->
> CustomerBasedSendingServer -> SendingServer. A connection always gets its
> own dedicated, non-shared SendingServer row (never silently shared across
> Businesses); a pre-existing legacy/admin assignment that IS shared (or
> whose SendingServer's legacy owner isn't this Business's owner) is
> surfaced read-only ("Managed"). Every action resolves its Business via the
> exact RFC-003 §14.1 boundary — never `business.customer_id ===
> Auth::id()`.

These rules are **unchanged** by relocation — only the surface they're
reached from moves.

**Locked behaviour, restated:**

* **Managed messaging is the normal experience.** An ordinary Business user
  never sees a provider selector and never enters a credential — that
  experience is `MessagingChannelsController`'s **relocated** surface, not
  the default onboarding path (Slice 4 owns the default managed onboarding
  UI itself).
* **BYO is Agency-only, plus the existing owner-granted advanced flag**
  (`manage_advanced_provider`, per the parent contract §6/§11.4) — gated
  exactly as `resources/views/customer/settings/advanced/**` implies by its
  location, never linked from an empty state or a guided flow.
* **Existing BYO connections are retained, never auto-migrated.** No
  migration in §4.2 reads or writes `customer_based_sending_servers` or
  `sending_servers` (stated explicitly there). A Business with an existing
  BYO Telnyx/Twilio connection keeps it exactly as-is after this slice ships;
  it is not silently converted into a `BusinessMessagingIdentity` row, and it
  does not disappear from the (relocated) UI.
* **BYO transport gets zero platform transport rate or wallet debit** — the
  parent contract's §11.5 table is unchanged and unaffected by relocation;
  §4.8 below shows the exact operational-measurement seam that continues to
  record `MessagingTransportMode::BYO` sends without any wallet touch.
* **Non-transport services still meter/debit normally** on a BYO Business —
  Slice 3 does not touch any non-transport meter.
* **Managed and BYO identities cannot be ambiguously active for the same
  operation** — §4.2's rule (a Business with an `active`
  `BusinessMessagingIdentity` never falls back to BYO for a platform-initiated
  managed send) is the concrete mechanism.
* **Authorization enforced server-side.** `resolveAccessibleBusiness()` and
  `resolveOwnedConnection()` (item 16) are reused verbatim at the relocated
  routes — not reimplemented, not weakened. The relocation is a **routes and
  views** change (`routes/customer.php`'s narrow addition, §3;
  `resources/views/customer/settings/advanced/**`, already allowlisted) —
  `MessagingChannelsController`'s own authorization methods are not
  rewritten, only additionally reached from the new route prefix, with the
  old `businesses/{businessUid}/channels` prefix's routes removed once the
  new ones are live (a route rename, not a duplicate permanent surface).

## 4.8 USAGE MEASUREMENT WITHOUT RETAIL CHARGING

**Exact RFC-005 seam, traced.** `UsageWalletManager::activateMetering()`
(`app/Library/Usage/UsageWalletManager.php:1136`) composes
`setActiveRate()` (`:1083`) with setting
`platform_feature_usage_classifications.is_metered = true` — RFC-005 §11's
own algorithm requires an active `business_usage_rates` row as a
precondition of calling it. There is no RFC-005 code path that sets
`is_metered = true` without an active rate. Therefore:

* Slice 3 **does not call** `activateMetering()` or `setActiveRate()` for any
  telecom feature key.
* Slice 3 **does not invent** a retail price, a markup, or a zero-price
  "activated-looking" rate row in `business_usage_rates` — no row is ever
  inserted into that table by this slice for a telecom feature.
* Measurement lives entirely in the new `business_messaging_usage_events`
  table (§4.2) — a plain operational log, written by
  `MessagingUsageRecorder`, with **no** foreign key into
  `business_usage_reservations`, `business_usage_rates`,
  `business_usage_rate_activations`, or any `business_usage_ledger_entries`-shaped
  table. It is not itself a §25 (RFC-005) table, so `UsageWalletManager`
  being "the sole write authority for all usage-billing tables" is
  unaffected — `MessagingUsageRecorder` writes to a table RFC-005 does not
  own and never claims to.
* **Distinguishing measurement from debit, concretely:** every managed and
  BYO send writes exactly one `business_messaging_usage_events` row
  (measurement); zero telecom-transport wallet reservations, commits, or
  releases occur for either transport mode in Slice 3 (no debit exists to
  distinguish it from, since none is created).
* **Immutable provider-cost evidence, without turning it into a charge.**
  `TelnyxMessagingAdapter`'s response may include a provider-reported cost
  figure; if captured at all, it is stored as an opaque, admin-only
  informational field on the usage-event row (not a new column added in this
  document's schema — deferred to whichever future slice actually needs it,
  since Slice 3 has no consumer for it) — it is never used to compute or
  trigger a wallet debit.
* **Tests demonstrate measurement while no retail rate is active** by
  asserting directly against `business_messaging_usage_events` row counts
  and fields (§4.12 T-MSG-19/T-MSG-20), and by asserting
  `platform_feature_usage_classifications` for any telecom feature key
  remains `is_metered = false` / has no `active_rate_id` throughout the test
  run (T-MSG-21) — proving the RFC-005 machinery was never touched, not
  merely that it wasn't asserted on.

## 4.9 CONCURRENCY, IDEMPOTENCY AND TRANSACTION BOUNDARIES

* **Duplicate outbound submission.** `OutboundMessageRequest::$idempotencyKey`
  (caller-supplied, derived the same way `agency_prospect_messages.operation_key`
  is derived today) is checked against `business_messaging_usage_events`
  before calling `send()` — a duplicate submission with the same key is a
  no-op returning the previously recorded result, never a second provider
  call.
* **Duplicate inbound webhook.** §4.6 step 3's `UNIQUE(provider,
  provider_message_id)` guarded insert.
* **Duplicate DLR/delivery-status event.** Same constraint, keyed by the same
  `provider_message_id` — a second identical delivery-status webhook updates
  nothing further once the first has been recorded (checked via a status
  transition guard, not a blind re-write).
* **Provider-message-ID uniqueness.** Enforced at the database level
  (`UNIQUE(provider, provider_message_id)`), not only in application logic —
  a race between two webhook deliveries for the same event cannot both
  succeed in writing distinct rows.
* **Identity-creation races.** `BusinessMessagingIdentityResolver::create()`
  wraps its existence check and insert in one `DB::transaction()` with
  `lockForUpdate()` on any existing non-archived row for that Business
  (§4.2); the table's own `UNIQUE(messaging_profile_id)`/`UNIQUE(phone_number)`
  constraints are the backstop if two different Businesses' creation
  requests somehow raced on the same Telnyx-issued profile/number (which
  should not happen since Telnyx issues each on request, but the constraint
  makes the failure mode "one request errors cleanly" rather than "silent
  double-assignment").
* **Number-assignment races.** Same mechanism — the unique constraint on
  `phone_number` is the authoritative guard, not an application-level check
  that could be skipped under concurrent load.
* **Retries after uncertain provider outcomes.** If `TelnyxMessagingAdapter::send()`
  times out or returns an ambiguous response, `ManagedMessageDispatcher`
  records the attempt as `REJECTED`/`RETRYABLE` (never `ACCEPTED`) and
  returns that honestly to its caller; **this document does not claim
  exactly-once external delivery** — a caller-level retry after a timeout may
  produce two accepted sends at Telnyx's end in the worst case, which is a
  known, disclosed limitation of any HTTP-based send API, not something
  Slice 3 claims to solve.
* **DB transaction boundaries around provider calls.** `send()` is **never**
  called from inside an open `DB::transaction()` that also holds a
  broad/long-lived lock — the identity resolution's `lockForUpdate()`
  transaction (create-time only) completes and commits before any adapter
  call; the per-send `idempotencyKey` check is a short, separate transaction
  that also commits before `send()` is invoked. This avoids holding a
  database lock for the duration of an external HTTP call.
* **What's persisted before/after an external request.** Before: the
  `idempotencyKey`-guarded usage-event row (status "attempted"). After: the
  same row is updated to reflect `$result->accepted`/`$result->providerMessageId`.
  No conversation/message row is created before the adapter call returns.
* **Replay never double-debits/duplicates/double-triggers automations.**
  Since Slice 3 creates no wallet debit for telecom transport (§4.8), there
  is nothing to double-debit; the `UNIQUE(provider, provider_message_id)`
  guard is what prevents a replayed inbound webhook from creating a second
  conversation row or (in a later slice that wires automations to inbound
  messages) triggering a second automation execution for the same event.

## 4.10 COMPATIBILITY AND MIGRATION

* Existing Twilio/Telnyx BYO connections (`CustomerBasedSendingServer` +
  `SendingServer` rows) continue behaving exactly as today — no schema
  change to either model (§4.2, §4.4).
* Existing sending servers of every other provider type (~250 `TYPE_*`
  constants) are entirely unaffected — Slice 3 adds new code paths, it does
  not modify `SendingServer`'s schema or any of its existing behaviour.
* Existing campaigns, conversations, messages (`reports`), automations,
  sender IDs, and phone-number records all continue functioning unchanged —
  the two narrow delegation points (§3, `EloquentCampaignRepository::quickSend()`,
  `Campaigns`'s dispatch switch) only add a **preceding** check
  ("does this Business have an active managed identity?") that, for every
  Business without one, falls through to the exact existing code path with
  zero behavioural change. A regression suite run (§4.12) proves this for
  the pre-existing `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`
  and `tests/Feature/AgencyProspecting/AgencyProspectingRuntimeTest.php`
  suites, which must pass unmodified.
* **No automatic credential migration** — restated from §4.7/§4.2: no
  migration or resolver code ever reads a `SendingServer` credential and
  writes it into `business_messaging_identities` (which has no credential
  column to receive one anyway).
* **No destructive migration** — both new tables are pure additions; no
  existing table's column is dropped, renamed, or retyped.
* **No silent payer/wallet change** — Slice 3 creates no wallet reservation
  or debit for telecom transport at all (§4.8), so there is no payer/wallet
  behaviour to silently change.
* **No rewrite owned by Slice 4/6** — number provisioning UI/flow (Slice 4)
  and default-sender automation resolution (Slice 6) are not built,
  stubbed, or partially implemented here (§4.1's exclusions).

## 4.11 EXACT ALLOWLIST

This restates, verbatim in substance, the corrected parent-contract §22.1
Slice 3 row (§3 above records the evidence for the correction):

**Implementation paths:** `app/Library/Messaging/**` (new); `app/Library/Messaging/Contracts/**` (new); `app/Library/Messaging/DTO/**` (new); `app/Library/Messaging/Exceptions/**` (new); `app/Models/BusinessMessagingIdentity.php` (new); `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` (existing); `app/Enums/Messaging/**` (new); `resources/views/customer/business/MessagingChannels/**` (existing); `resources/views/customer/settings/advanced/**` (new); `config/services.php` (existing — new `telnyx` block only); `config/messaging.php` (new); `app/Providers/AppServiceProvider.php` (existing — binding addition only); `database/migrations/**` (new, additive-only — the two tables in §4.2); `app/Http/Controllers/Customer/DLRController.php` (existing — one new method only, `inboundTelnyxManaged()`); `routes/public.php` (existing — one new route line only); `routes/customer.php` (existing — new routes only, for the relocated advanced-settings surface); `app/Repositories/Eloquent/EloquentCampaignRepository.php` (existing — the pre-dispatch delegation insertion in `quickSend()` only); `app/Models/Campaigns.php` (existing — the pre-dispatch delegation insertion in its own dispatch switch only).

**Test paths:** `tests/Feature/Messaging/**` (new); `tests/Feature/Security/**` (existing); `tests/Feature/Usage/**` (existing); `tests/Feature/Business/**` (existing — narrowly, `MessagingChannelsTest.php` and new production-delegation-integration tests only).

**Documentation paths:** this document; the parent contract's §22.1 amendment (already applied, §3).

**Prohibited paths (explicit stop conditions — touching any of these in
Slice 3's implementation is out of contract and must stop for
`ai:needs-human`):**

* Any line in `app/Models/SendCampaignSMS.php` other than none — this file
  is never touched by Slice 3.
* Any of `DLRController.php`'s ~60 other provider methods, or its existing
  `inboundTelnyx()`/`inboundDLR()` bodies.
* Any column, cast, or method on `app/Models/SendingServer.php` or
  `app/Models/CustomerBasedSendingServer.php`.
* Any write to `phone_numbers` or `senderid` from Slice 3's new code (the
  new mapping lives exclusively in `business_messaging_identities`).
* Any `business_usage_rates`, `business_usage_rate_activations`, or
  `business_usage_reservations` row insertion for a telecom feature key.
* Any `provider_managed_account_id` column, or any other Managed-Account-shaped
  identifier, anywhere.
* Any real Telnyx API call, account, number, brand, campaign, or rate
  activation — this document is audit/contract-writing only, and the
  implementation slice it describes still may not make one until real
  credentials are deliberately supplied and Slice 4's own gates clear for
  anything beyond `send()`/webhook-verification.

## 4.12 TEST MATRIX

Inherited from the parent contract (§24), same IDs, owned by Slice 3
already: **T-PROV-1** (no customer-role response body contains any provider
credential field name or value), **T-PROV-2** (provider credentials are
readable by no customer role, in any serialization), **T-BYO-1** (a BYO
transport send takes no reservation and produces no wallet debit),
**T-BYO-2** (a BYO send is still recorded against the telecom usage meter
for measurement, at a zero rate, with a `byo` transport marker), **T-SCOPE-1**
(no customer-facing surface offers, prices, provisions, or meters a voice
call).

New tests, this document, `tests/Feature/Messaging/**` unless noted:

| ID | Assertion | Location |
|---|---|---|
| T-MSG-1 | A second `BusinessMessagingIdentity` creation attempt for a Business that already has a non-archived one fails closed with `MessagingIdentityConflictException`, before any provider call | `tests/Feature/Messaging/` |
| T-MSG-2 | `UNIQUE(messaging_profile_id)`/`UNIQUE(phone_number)` violations at the database layer raise a caught, reported exception, never a silent overwrite | `tests/Feature/Messaging/` |
| T-MSG-3 | A `pending`/`suspended`/`archived` identity resolves to `null` from `resolveForBusiness()` — never a partially-usable object | `tests/Feature/Messaging/` |
| T-MSG-4 | `BusinessMessagingIdentity` has no credential-shaped attribute, cast, or hidden field — its schema literally cannot carry a secret | `tests/Feature/Messaging/` |
| T-MSG-5 | Migration `up()`/`down()` round-trips cleanly on both new tables with no data loss warning | `tests/Feature/Messaging/` |
| T-MSG-6 | `FakeMessagingAdapter::send()` records the call and returns a scripted `OutboundMessageResult` deterministically | `tests/Feature/Messaging/` |
| T-MSG-7 | `TelnyxMessagingAdapter` is never resolved from the container during the full test suite run (a container-resolution assertion, not a network assertion) | `tests/Feature/Messaging/` |
| T-MSG-8 | `TelnyxMessagingAdapter::send()`, given valid fixture config but wrapped in `Http::fake()`, sends exactly the fields specified in `OutboundMessageRequest` and no others | `tests/Feature/Messaging/` |
| T-MSG-9 | `EloquentCampaignRepository::quickSend()` for a Business with an active managed identity delegates to `ManagedMessageDispatcher`/`FakeMessagingAdapter`, never reaching `SendCampaignSMS`'s Telnyx `case` block | `tests/Feature/Business/` |
| T-MSG-10 | `Campaigns`'s own dispatch switch shows the same delegation for its bulk/scheduled path | `tests/Feature/Business/` |
| T-MSG-11 | Business A's resolved identity can never be used to construct an `OutboundMessageRequest` for Business B's send, even when both share the same HTTP request lifecycle (e.g. a queued job processing both) | `tests/Feature/Messaging/` |
| T-MSG-12 | A forged `business_messaging_identity_id` submitted as request input is never read by the outbound resolution path (the path re-resolves from `Business`, ignoring any such input) | `tests/Feature/Messaging/` |
| T-MSG-13 | Each non-`active` status value produces zero `FakeMessagingAdapter` calls | `tests/Feature/Messaging/` |
| T-MSG-14 | A real `POST` to `route('inbound.telnyx_managed')` with a validly-signed, Messaging-Profile-ID-attributable payload produces the expected `BusinessMessagingIdentity`-scoped side effect; the legacy `Reports`-with-`user_id=1` fail-open write never occurs for this route | `tests/Feature/Messaging/` |
| T-MSG-15 | An invalid/missing Telnyx webhook signature on `inbound.telnyx_managed` is rejected with `403` and writes no conversation/message/audit-of-processing row beyond the bounded rejection log | `tests/Feature/Messaging/` |
| T-MSG-16 | Constructing `TelnyxMessagingAdapter` with empty `services.telnyx` config throws `MessagingProviderNotConfiguredException` before any `Http::fake()`-recorded request occurs | `tests/Feature/Messaging/` |
| T-MSG-17 | `TelnyxMessagingAdapter` reads configuration once at construction, not per-call, and behaves identically before/after `config:cache` | `tests/Feature/Messaging/` |
| T-MSG-18 | An unattributable (valid signature, unknown Messaging Profile ID) inbound webhook returns `200`, updates no conversation/message, debits no wallet, triggers no automation, and writes exactly one bounded audit/rejection row | `tests/Feature/Messaging/` |
| T-MSG-19 | A managed send writes exactly one `business_messaging_usage_events` row with `transport_mode = managed` | `tests/Feature/Messaging/` |
| T-MSG-20 | A BYO send (through the relocated advanced-settings path) writes exactly one `business_messaging_usage_events` row with `transport_mode = byo` and no wallet reservation/debit of any kind | `tests/Feature/Business/` |
| T-MSG-21 | Throughout the full Slice 3 suite run, no telecom `PlatformFeature` key's `platform_feature_usage_classifications.is_metered` becomes `true` and no `active_rate_id` is ever set | `tests/Feature/Usage/` |
| T-MSG-22 | Duplicate outbound submission with the same `idempotencyKey` produces exactly one provider call and one usage-event row | `tests/Feature/Messaging/` |
| T-MSG-23 | Duplicate delivery of the same inbound webhook (`provider_message_id` replay) produces exactly one attributed effect | `tests/Feature/Messaging/` |
| T-MSG-24 | The relocated advanced-settings BYO connect/manage flow enforces `resolveAccessibleBusiness()`/`resolveOwnedConnection()` identically to the pre-relocation routes, and is unreachable without `manage_advanced_provider` | `tests/Feature/Business/` |
| T-MSG-25 | An Agency actor without `manage_advanced_provider` cannot reach the relocated BYO surface by direct route, even with an otherwise-valid Business | `tests/Feature/Security/` |
| T-MSG-26 | `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` and `tests/Feature/AgencyProspecting/AgencyProspectingRuntimeTest.php` pass unmodified after the two delegation-point changes | `tests/Feature/Usage/`, `tests/Feature/AgencyProspecting/` (regression, not new files) |
| T-MSG-27 | Both new migrations' `down()` cleanly drop their tables with no dependent-FK error | `tests/Feature/Messaging/` |
| T-MSG-28 | A concurrent pair of `BusinessMessagingIdentityResolver::create()` calls for the same Business cannot both succeed (one wins, one gets `MessagingIdentityConflictException`) | `tests/Feature/Messaging/` |
| T-MSG-29 | `OutboundMessageResult` and any exception message thrown by `TelnyxMessagingAdapter` contain no credential-shaped substring (grep-style assertion over the serialized test failure output itself) | `tests/Feature/Messaging/` |
| T-MSG-30 | A provider timeout/ambiguous response never produces `MessageDispatchStatus::ACCEPTED` | `tests/Feature/Messaging/` |

**Ownership.** Every ID above is owned by Slice 3 alone; none is claimed by
any other slice's test list in the parent contract's §24.1. No ID is reused
across two rows of this table.

## 4.13 IMPLEMENTATION ORDER

1. Migrations, enums, and the `BusinessMessagingIdentity` model (§4.2) —
   nothing yet reads or writes them outside factories/tests.
2. Contracts, DTOs, exceptions (§4.3) — pure interfaces, no implementation.
3. `FakeMessagingAdapter` and its test-suite `setUp()` binding pattern (§4.3,
   §4.4) — establishes the testing seam before any real adapter exists.
4. Configuration (`config/services.php`'s `telnyx` block, `config/messaging.php`)
   and the default `AppServiceProvider` binding to `TelnyxMessagingAdapter`
   behind the fail-closed constructor check (§4.4) — safe to merge even
   before the adapter's HTTP logic is complete, since the constructor throws
   without configuration.
5. `TelnyxMessagingAdapter`'s real `send()`/`verifyInboundSignature()`/`parseInboundWebhook()`
   implementation, developed entirely against `Http::fake()` — zero-live-call
   test protection verified by T-MSG-7/T-MSG-16 before this step is
   considered done.
6. `BusinessMessagingIdentityResolver` and `ManagedMessageDispatcher`, wired
   into the two narrow production delegation points
   (`EloquentCampaignRepository::quickSend()`, `Campaigns`'s dispatch switch)
   identified in §3 — outbound isolation (§4.5) becomes provable against
   real production entry points at this step.
7. `DLRController::inboundTelnyxManaged()`, the new `routes/public.php` line,
   and `InboundWebhookAttributionResolver` (§4.6) — fail-closed inbound
   attribution becomes provable against the real production route at this
   step.
8. BYO relocation: new `routes/customer.php` routes, `resources/views/customer/settings/advanced/**`
   views, `MessagingChannelsController` reached from the new prefix, old
   prefix removed (§4.7).
9. `MessagingUsageRecorder` wired into both the managed dispatcher/attribution
   resolver and the (relocated) BYO send path (§4.8).
10. Full regression: `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`,
    `tests/Feature/AgencyProspecting/AgencyProspectingRuntimeTest.php`, and
    every existing `tests/Feature/Business/**`/`tests/Feature/Security/**`
    test, run alongside the full new §4.12 matrix (T-MSG-1..30 plus the
    inherited T-PROV-1..2/T-BYO-1..2/T-SCOPE-1).

This order is adjustable if implementation-time evidence proves a safer
sequence necessary — it is not itself authorization to implement (§1).

## 5. CONTRACT INTEGRITY SELF-CHECK

* **Already-existing behaviour** (never re-implemented, only traced): items
  1-18 of §2 in full; the parent contract's §11.1-§11.5, §21.1-§21.2, §27
  C-5.
* **Slice-3-will-implement:** every item in §4.1's inclusions, §4.2's two
  tables, §4.3's contracts/DTOs/adapters, the five narrow production
  delegation points in §3/§4.11.
* **Deferred to Slice 4/6/9/Managed-Accounts-migration:** every item in
  §4.1's exclusions, restated in §4.10's "no rewrite owned by Slice 4/6."
* **Assumptions:** none stated as fact without evidence — every claim in §2
  carries a file:line citation personally verified against the base SHA.
* **Mechanically-proven facts:** §2's 18 items; §3's executability table.
* **Already-locked human decisions, not re-litigated:** Candidate B
  (§28.3), the exclusion of any Managed-Account column (§21.2), no retail
  rate activation (§28.1/§28.1a), $5 funding floor and BYO billing semantics
  (§11.5) — all cited from the parent contract, none restated as if newly
  decided here.
* **No isolation control is described as "implemented"** anywhere in this
  document — §4.5/§4.6 describe what Slice 3 **will build**, in future
  tense/imperative mood throughout, consistent with §1's status statement
  that this document authorizes nothing by itself.
* **Every cited path/symbol** in §2-§4 was read against the merged tree at
  `6c820c801da08ecfd6165d1d3a52ae6336606f0c` (personally, and independently
  cross-checked against the background mechanical-audit agent's report,
  which returned consistent file:line evidence for every overlapping item).
* **Every internal `§` reference** in this document resolves to a section
  that exists either in this document (§1-§4.13) or, when prefixed
  "parent contract," in `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`'s
  current section numbering (§6, §11, §21, §22, §24, §27, §28), re-confirmed
  current as of this document's authoring.
* **Test-to-slice map has no duplicates or unowned tests:** T-MSG-1..30 are
  new IDs unique to this document, checked against the parent contract's full
  §24/§24.1 ID list for collisions (none found); the five inherited IDs
  (T-PROV-1..2, T-BYO-1..2, T-SCOPE-1) are already uniquely owned by Slice 3
  in the parent contract's own §24.1 table, not newly claimed here.
* **No live credential value or secret-shaped example** appears anywhere in
  this document — every configuration key is named, never given a value; the
  Telnyx webhook-signing example in §2 item (10)/§4.6 references only the
  public, published header/algorithm names, never a key or signature value.

## 6. VALIDATION

* Only two paths changed in this branch: this document (new) and the parent
  contract's §22.1/accompanying note (the mechanically-necessary Slice 3
  executability correction, §3). No source code, migration, configuration,
  dependency, or generated asset changed.
* `git diff --check`: clean (no whitespace errors) — verified below.
* Every cited path in §2-§4 exists in the merged tree, or is explicitly
  marked `(new)`.
* Every internal `§` reference resolves per §5 above.
* The §4.12 test-to-slice map carries no duplicate or unowned test ID.
* Stale-phrase sweep performed for: Managed Accounts described as launch
  (none — every mention is explicitly "future," "owner-evaluated," or
  "not built"); `provider_managed_account_id` (appears only inside §4.2's
  and this sweep's own explicit "deliberately absent"/"prohibited" listings,
  never as a column this document defines); live-calls-authorized language
  (none — §1, §4.1, §4.11 explicitly prohibit it); retail-rate-activation
  language (none — §4.8, §4.11 explicitly prohibit it); credentials-exposed-to-customers
  language (none — §4.4, §4.11 explicitly prohibit it); fail-open/fallback
  attribution (the only occurrences describe the **legacy** behaviour being
  replaced or the **BYO** behaviour explicitly and honestly left unfixed —
  never describing the new managed path); tests-of-unused-helpers-presented-as-production-integration-proof
  (§3's table and §4.12's T-MSG-9/10/14 specifically target the real
  production entry points, not standalone helpers); Slice-4-behaviour-silently-pulled-into-Slice-3
  (none — §4.1's exclusions list is explicit and §4.13's ten steps stop at
  Slice 3's own scope).
* Secret-shaped-string sweep over every line added in this branch: none
  found (no 20+ character alphanumeric tokens, no `sk_`/`pk_`-style
  prefixes, no PEM blocks).
* Confirmed: zero source code, migration, configuration, dependency, or
  generated asset changed by this branch.

---

**CUSTOMER EXPERIENCE SLICE 3 — MANAGED MESSAGING PROVIDER FOUNDATION CONTRACT READY FOR HUMAN/CHATGPT REVIEW**
