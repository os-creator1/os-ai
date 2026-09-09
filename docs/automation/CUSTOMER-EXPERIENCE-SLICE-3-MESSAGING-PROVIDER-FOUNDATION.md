# CUSTOMER EXPERIENCE SLICE 3 — MANAGED MESSAGING PROVIDER FOUNDATION

**Correction Round 1 (2026-09-09).** This revision replaces the parallel
messaging-owned usage ledger, corrects phone-number cardinality, adds a
genuine Profile+destination-number cross-check to inbound attribution,
removes the fail-open default-to-user-1 behaviour from the shared legacy
webhook boundary instead of merely leaving it beside a new safe route,
corrects an unproven webhook-retry claim, verifies `manage_advanced_provider`
mechanically rather than assuming it exists, replaces a fragile
never-resolved test claim with a real network-safety design, and separates
operational state, security auditing, and usage measurement into three
distinct, narrowly scoped stores. Every change below is evidence-driven;
§2 records the additional mechanical verification this round required.
Still a documentation-and-audit pass only — no Telnyx API call, no
provider account/profile/number/registration/webhook/rate/credential
change, no production code, no PR.

## 1. STATUS AND AUTHORITY

This is a **preimplementation contract and executability audit**. It authorizes
nothing by itself beyond what `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`
§21/§21.2/§22 already authorizes for Slice 3. It does not revisit
`docs/automation/TELNYX-MANAGED-MESSAGING-ARCHITECTURE-DECISION.md`'s Candidate
B selection (one platform-owned, Pay-as-you-go Telnyx account; one dedicated
Messaging Profile per Business, with dedicated phone number(s); provider-neutral
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
SHA as of this document's authoring. This correction round continued on the
same branch from head `946d34ed131d540c88f2aed04ae82895db2473e8` — no new
branch, no merge, no rebase.

## 2. MECHANICAL REPOSITORY AUDIT — SUMMARY AND FILE:LINE EVIDENCE

Items (1)-(18) are carried forward from the initial pass, unchanged, and
remain the evidentiary basis for everything in §3-§4 that they support. Item
(19) is new evidence gathered specifically for this correction round.

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
**Critically for this correction round: `SendingServer.auth_token` (Twilio)
already exists and is already sufficient, on its own, to run
`Twilio\Security\RequestValidator` — the exact mechanism the Agency
Prospecting webhook controller already uses (item 10). No comparable
per-connection field exists for Telnyx's Ed25519 webhook-signing scheme —
`SendingServer` has no `webhook_public_key`-shaped column for any provider.**
This asymmetry is the mechanical basis for §4.6's differing BYO treatment of
Twilio vs Telnyx.

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
fail-open handler** (item 7). **This correction round removes both
`routes/web.php` duplicates (§4.6/§4.11) rather than leaving them beside a
new safe route** — a live duplicate and a dead one are both a source of
ambiguity about which route is canonical, and neither earns its keep once
`routes/public.php:26` is the sole legacy Telnyx entry point.

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
`PhoneNumbers` row matches — the fail-open default-to-user-1 defect. No
caller anywhere in the repository passes an explicit `$user_id` to
`inboundDLR()` — confirmed by a full-repository grep for `inboundDLR(` — so
there is no legitimate caller relying on the default ever producing a real
write; removing the unconditional write for the unresolved case changes no
correct behaviour (§4.6). `inboundTelnyx()` (`:1367-1461`) parses the Telnyx
JSON payload, extracts `to`/`from`/`text` from `data.payload`, resolves
`$sendingServer` via `getSendingServer(TYPE_TELNYX, ...)`, then calls
`inboundDLR($to, $message, $sendingServer, $cost, $from)` at `:1436` — with
**no signature verification of any kind** before it (item 10).
`getSendingServer()` (`:968-975`) resolves the **first active**
`SendingServer` of the matching type/uid — no tenancy or Business scoping.
`inboundTwilio()` (grep-located, not previously quoted in full) follows the
identical `getSendingServer(TYPE_TWILIO, ...)` → `inboundDLR(...)` shape —
same fail-open defect, same absence of signature verification, for Twilio.

**(8) Number/Sender-ID-to-Business attribution today.**
`app/Models/PhoneNumbers.php` (215 lines): `business_id` is nullable,
added by `database/migrations/2026_09_05_120001_add_nullable_business_id_to_tenancy_tables.php`,
whose own docblock states it stays "nullable permanently for pooled/unassigned
numbers"; `number` (`database/migrations/2020_11_14_105312_create_phone_numbers_table.php`)
carries **no unique constraint**. `app/Models/Senderid.php` (190 lines): same
pattern — `business_id` nullable via the same 2026-09-05 migration,
`sender_id` (`database/migrations/2020_05_30_123429_create_senderid_table.php`)
also **no unique constraint**. Neither table can serve as an authoritative
number-to-Business mapping today, and neither is reused by the new schema
(§4.2) — a fresh, dedicated, normalized table is used instead precisely
because these two cannot honestly represent one-Business-to-many-numbers with
an enforced unique-active-number rule.

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
uses `Twilio\Security\RequestValidator` against `X-Twilio-Signature`, reading
`$channel->sendingServer?->auth_token`, called before processing at `:55`.
This is direct, mechanical proof that `auth_token` alone is sufficient input
to that validator — the same field already stored on every Twilio
`SendingServer` row, BYO included. Telnyx: **no signature verification
exists anywhere in this repository.** `AgencyProspectingWebhookController::telnyx()`
(`:70-93`) only checks the URL-embedded `AgencyProspectingWebhookToken::isValid()`
HMAC token — a channel-identification scheme, not Telnyx's own Ed25519
payload-signing scheme (`telnyx-signature-ed25519`/`telnyx-timestamp`
headers, per Telnyx's own published webhook documentation, which also states
only that a webhook not acknowledged within 2000ms is retried and that any
non-2xx response "indicates to Telnyx that you did not receive the
webhook" — it documents no per-status-code retry differentiation and no
fixed retry count/backoff for message-delivery webhooks; a separately
documented 1-minute/10-minute/1-hour retry cadence exists only for an
unrelated subsystem, 10DLC campaign-reactivation processing, and is not
conflated with webhook delivery retries anywhere in this document, per this
round's correction, §4.6). The legacy `DLRController::inboundTelnyx()`/`inboundTwilio()`
perform no check of either kind today.

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
follows (§4.2's `business_messaging_operations`), not the legacy
`reports.status` packing. The same webhook example payloads referenced in
item 10 (Telnyx's own published documentation) show both an outbound
`message.finalized` event and an inbound `message.received` event each
carrying a distinct `id` field shaped as a UUID (`"id":"4ee8c3a6-..."` for
the outbound example, `"id":"84cca175-..."` for the inbound one) — evidence
that Telnyx assigns one shared, UUID-shaped message-identity namespace
regardless of direction, which is why §4.2's `business_messaging_operations`
table can safely use a single `UNIQUE(provider, provider_message_id)`
constraint across both outbound and inbound rows for the same provider.

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
key (`Conversations`, per `app/Enums/Entitlement/PlatformFeature.php:15`),
not Telnyx-specific, gated by an `m5_conversations_usage_tracking` flag.
**Conclusion, corrected this round: rather than building a Messaging-owned
table "outside" RFC-005 by name only, the narrowest correct seam is one new,
generic, additive method on `UsageWalletManager` itself
(`recordMeasurement()`) writing to one new, generic, RFC-005-owned table
(`business_usage_measurements`) — keeping `UsageWalletManager` the sole
write authority for usage-billing-adjacent state, exactly as RFC-005 already
requires for every other table in its §25 scope (§4.8).**

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
`app()->instance()` in test `setUp()` — is what Slice 3 reuses (§4.3, §4.4),
alongside a real network-safety mechanism verified this round (item 19).**

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
verbatim. `isManagedConnection()` (`:338-344`, shared-count > 1 or
legacy-owner-mismatch) is the exact existing test this round reuses (§4.6) to
decide whether a given Telnyx `SendingServer` is a Business-facing BYO
connection reachable from this controller's own surface, versus an
admin-only/legacy connection outside it — a plain, read-only, pre-existing
check requiring no schema change.

**(17) Routes/middleware needing to change.**
`routes/customer.php:388` — `channels` (`entry()`, `customer.channels.index`);
`routes/customer.php:975-983`, inside
`Route::prefix('{workspaceUid}/businesses/{businessUid}/channels')` —
`channels()`/`connect()`/`storeConnect()`/`show()`/`update()`/`enable()`/`disable()`.
Middleware, per `app/Providers/RouteServiceProvider.php:77-80`: `['web',
'auth', 'can:access_backend', 'ValidProduct', 'twofactor']` — a stark
contrast with the unauthenticated inbound webhook routes in item 6.

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
in the suites inspected, and no test in the repository currently calls
`Http::preventStrayRequests()` anywhere** (repository-wide grep, zero
matches) — see item 19.

**(19) NEW THIS ROUND — `manage_advanced_provider` and stray-HTTP test
safety, mechanically verified.**

* A repository-wide grep for `manage_advanced_provider` returns matches only
  in the three documentation files (this document, the parent contract, the
  architecture-decision document) — **it does not exist as a column,
  entitlement, permission, enum, policy, or feature flag anywhere in
  application code today.** The parent contract's own §6 table already
  labels it `(new)` — this document's earlier revision failed to carry that
  qualifier through to §4.7's usage of it, which this correction fixes.
* The repository's real, existing, generic customer-permission mechanism is
  `config/customer-permissions.php` — a flat array of `key => ['display_name',
  'category', 'default']` entries (e.g. `manage_google_business_profile` at
  `:49-53`, `default => false`, with a docblock at `:36-43` explaining the
  conservative-default precedent this document follows). Every key in that
  array is automatically registered as a Laravel `Gate` by
  `app/Providers/AuthServiceProvider.php:55-56`:
  `foreach (config('customer-permissions') as $key => $permissions) { Gate::define($key, ...); }`
  — **adding a new permission requires no change to `AuthServiceProvider.php`
  itself**, only a new array entry. Controllers check it the same way
  everywhere it is used, e.g. `GoogleBusinessProfileController.php:178,343,398,461,486,522`:
  `$this->authorize('manage_google_business_profile')`.
* The actual grant/revoke mechanism for these keys is
  `app/Http/Controllers/Customer/SubAccountController.php` — a sub-account's
  granted keys are stored as a flat JSON map on `Customer.permissions`
  (`json_decode(optional($user->customer)->permissions, true)`, `:161,214-215`),
  edited through that controller's existing permission-management screen,
  which already iterates `config('customer-permissions')` generically
  (`:165,217`). This mechanism is **not** role-hierarchy-aware by itself — it
  lets whoever can edit a sub-account's permissions grant any catalog key to
  that sub-account, which is one level less restrictive than the parent
  contract's own §6 table (Platform owner/Agency owner only, excluding
  Agency admin/staff/Business entirely). §4.7 specifies the additional,
  Slice-3-owned guard this requires.
* Repository-wide grep for `preventStrayRequests` returns zero matches —
  no test currently uses Laravel's stray-HTTP-request guard. `composer.json:45`
  confirms `laravel/framework: ^12.0`, in which `Http::preventStrayRequests()`
  (available since Laravel 10.1) is present and usable. `tests/TestCase.php`
  exists and is the base class every feature test already extends.
* `app/Console/Kernel.php:69` (`protected function schedule(Schedule $schedule)`)
  is the existing, single scheduling entry point already used for this
  repository's other scheduled jobs — the correct, narrow place to register
  the new bounded-retention purge command (§4.8/§4.11), not a new scheduler
  mechanism.

## 3. EXECUTABILITY AUDIT OF THE §22.1 SLICE 3 ALLOWLIST

| Required behaviour | Current production entry point | Implementation path required | Was it allowlisted before this document? | Why it must change | Test proving delegation |
|---|---|---|---|---|---|
| Fail-closed inbound/DLR attribution for **managed** Telnyx traffic, cross-checked on two independent signals | `DLRController::inboundTelnyx()`/`inboundDLR()`, reached by `routes/public.php:26` (and the dead/duplicate `routes/web.php:45,73`) | New method on `DLRController` (fail-closed, signature-verified, Profile+number-cross-checked) + one new route; removal of the two dead/duplicate routes | **No** — `DLRController.php`, `routes/public.php`, `routes/web.php` were absent from the row | A resolver nothing routes to proves nothing about production traffic; two extra routes to the same unsafe handler leave the "fix" ambiguous | §4.12 T-MSG-16/T-MSG-19..25 |
| Removal of the shared fail-open default-to-user-1 write for **every** provider reached by `inboundDLR()`, without claiming signature verification for providers that do not have it | `DLRController::inboundDLR()`'s unconditional `else` branch (`:917-934`) | A narrow edit to the **existing** `inboundDLR()` method: no `Reports`/`ChatBox` write and no STOP/blacklist processing when `$phone_number` cannot be resolved | **No** | This is the exact behaviour named unacceptable in this correction round; it cannot be fixed by adding a new route beside the old one | §4.12 T-MSG-20/T-MSG-21 |
| Secure BYO **Twilio** inbound, using data already on the connection | `DLRController::inboundTwilio()` | A narrow edit to the **existing** `inboundTwilio()` method: `Twilio\Security\RequestValidator` verification using the resolved `SendingServer.auth_token`, mirroring the Agency Prospecting precedent (item 10) | **No** | `auth_token` is already stored and already proven sufficient (item 10) — Option A is mechanically achievable today | §4.12 T-MSG-22 |
| Fail-closed (not fail-open) BYO **Telnyx** inbound, honestly, given no per-connection Ed25519 material exists | `DLRController::inboundTelnyx()` | A narrow edit to the **existing** `inboundTelnyx()` method: Business-facing BYO Telnyx connections (identified via the existing `isManagedConnection()`-adjacent, read-only `CustomerBasedSendingServer` existence check, item 16) are disabled for inbound processing, not silently left fail-open | **No** | No schema exists to verify Telnyx BYO inbound (item 4); Option B is the only honest choice absent that material | §4.12 T-MSG-23 |
| Managed outbound dispatch actually used by real sends | `EloquentCampaignRepository::quickSend()` (`:421,441,446`, `:1826,1830,1834`), `Campaigns`'s own switch (`Campaigns.php:979,983,987`) | Pre-dispatch managed-identity resolution/delegation inserted before each switch | **No** — neither file was in the row | Without this, a managed `BusinessMessagingIdentity` never leaves this document's tests | §4.12 T-MSG-9/T-MSG-10 |
| Authoritative, honestly-cardinalitied number-to-Business resolution | None — `PhoneNumbers`/`Senderid` (`business_id` nullable, no unique constraint) | New `business_messaging_identities` (profile-level) + new `business_messaging_numbers` (one-to-many number mapping) | **Partially** — `BusinessMessagingIdentity.php` and `database/migrations/**` were allowlisted; a single `phone_number` column on the identity could not honestly model "dedicated phone number(s)" | Candidate B permits multiple numbers per Business; a single column cannot represent that | §4.12 T-MSG-1..8 |
| Real Telnyx adapter wiring | None | `TelnyxMessagingAdapter` in `app/Library/Messaging/**` | **Yes** — already allowlisted | No change needed | §4.12 T-MSG-13/T-MSG-14 |
| Real network safety for the real adapter | None | `config('messaging.managed_messaging_enabled')` gate + `Http::preventStrayRequests()` in `tests/TestCase.php` | **No** — `tests/TestCase.php` was absent; the enable-switch design did not previously exist | Container resolution is not a security boundary; an explicit switch plus test-wide stray-request prevention is | §4.12 T-MSG-27..30 |
| Usage measurement without an active rate, owned by RFC-005, not shadowed by Messaging | RFC-005's `activateMetering()` (requires a rate) — unusable as-is (item 12) | One additive method on `UsageWalletManager` (`recordMeasurement()`) writing to one new, generic, RFC-005-owned table (`business_usage_measurements`); one additive `PlatformFeature` enum case | **No** — `app/Library/Usage/UsageWalletManager.php` and `app/Enums/Entitlement/PlatformFeature.php` were absent from the row | The original design named a Messaging-owned table "outside RFC-005" by declaration only, which does not change what the table actually is | §4.12 T-MSG-31..33 |
| Bounded, minimized security/rejection auditing, separate from usage measurement | None | New `messaging_webhook_rejections` table + narrow retention/purge command | **No** | The original design reused the same table for rejection logging as for measurement, mixing an audit concern with a billing-adjacent one | §4.12 T-MSG-34 |
| `manage_advanced_provider` actually representable and correctly scoped | Nothing — the key does not exist anywhere in code | One additive entry in `config/customer-permissions.php`; one narrow role-tier guard inside the relocated advanced-settings surface | **No** — the flag was referenced as if existing, without a representation | A contract cannot gate behaviour on a flag that is not real | §4.12 T-MSG-25/T-MSG-26 |
| BYO relocation to Agency-only advanced settings, with the old surface actually gone | `MessagingChannelsController` + `routes/customer.php:388,975-983` | New routes under `resources/views/customer/settings/advanced/**`'s controller surface; old `businesses/{businessUid}/channels` routes removed | **Partially** — the controller and view directory were allowlisted; `routes/customer.php` was not, and the original design left the old routes standing | Relocation that leaves the old surface reachable is not relocation | §4.12 T-MSG-24/T-MSG-26 |
| Isolation tests against real production entry points, not only new helper classes | (all entry points above) | `tests/Feature/Business/**` additions alongside `tests/Feature/Messaging/**` | **Partially** — `tests/Feature/Messaging/**` was allowlisted; `tests/Feature/Business/**` was not | `MessagingChannelsTest.php` already lives there; the delegation/relocation tests are its natural extension | §4.12, throughout |

**Conclusion, corrected this round: the §22.1 Slice 3 row remains not
executable** against several of its own already-locked §21.1 exit criteria,
for a wider set of reasons than the initial pass identified — not only was
the inbound/outbound production wiring missing, but the original design's
own usage-ledger, phone-number schema, inbound-attribution rule,
BYO-security posture, and `manage_advanced_provider` gate were each, on
mechanical re-examination, unable to deliver what they claimed. §4.11 records
the corrected allowlist. Every added path is named exactly; no broad
`app/Http/Controllers/**`, `routes/**`, `app/Repositories/**`, or
`app/Library/Usage/**` glob is used anywhere in the correction.

## 4.1 EXACT SCOPE

**Included:**

* Provider-neutral contracts/DTOs/enums/exceptions in `app/Library/Messaging/Contracts/**` and `app/Enums/Messaging/**` (§4.3).
* A deterministic fake adapter (`FakeMessagingAdapter`) and a production-shaped Telnyx adapter (`TelnyxMessagingAdapter`) implementing the same interface, gated by a platform-wide enable switch and real stray-HTTP test prevention, not by a "never resolved" claim (§4.3, §4.4).
* `BusinessMessagingIdentity` (new model + migration) — the provider-neutral, authoritative per-Business Messaging-Profile mapping — **plus `BusinessMessagingNumber` (new model + migration)** — the one-to-many phone-number mapping a single column could not honestly represent (§4.2).
* Platform credential custody: the single shared Telnyx credential lives in env/config only, never a per-Business database row (§4.4).
* Fail-closed inbound/DLR attribution for managed Telnyx traffic, cross-checked on **both** Messaging Profile ID and destination number, wired to the real `DLRController` entry point (§4.6).
* **Removal of the shared fail-open default-to-user-1 write from the existing `inboundDLR()` method** (all providers), **real Twilio BYO signature verification** on the existing `inboundTwilio()` method, and **disabled Telnyx BYO inbound processing** (with an honest UI explanation) on the existing `inboundTelnyx()` method (§4.6).
* Provider error normalization (`ProviderErrorCategory`: retryable vs terminal vs configuration) (§4.3).
* Safe container binding of the adapter interface, following the `GoogleBusinessProfileReadClient`/`AgencyProspectingMessageSender` precedent, plus a platform-wide `managed_messaging_enabled` switch and `Http::preventStrayRequests()` test-wide protection (§4.4).
* Genuine BYO relocation of `MessagingChannelsController` to Agency-only advanced settings — old routes removed, a real (newly represented) `manage_advanced_provider` permission, restating the superseded B2 docblock rules verbatim (§4.7).
* Messaging-usage measurement through one additive, RFC-005-owned `UsageWalletManager::recordMeasurement()` method and one additive, generic, RFC-005-owned table, with no active retail rate (§4.8).
* A separate, bounded, minimized, retention-governed webhook-rejection audit path, distinct from both usage measurement and operational transport state (§4.6, §4.8).
* Migration/compatibility behaviour for every existing Twilio/Telnyx BYO connection, sending server, campaign, conversation, message, automation, sender ID and phone-number record (§4.10).
* Isolation and credential-non-disclosure tests against the real production entry points identified in §3, not only new helper classes (§4.12).

**Excluded (deferred or never in scope for Slice 3):**

* Production number purchasing, production 10DLC registration submission, production customer sending, retail rate activation, customer wallet-funded provisioning, phone-number **lifecycle**/renewal/grace/release **workflow** — all Slice 4 (gated by §28.4, §28.1, Slice 5 per the parent contract, unchanged by this document). Slice 3 defines the `business_messaging_numbers` schema and its `pending`/`active`/`suspended`/`released` states and reads against them; it does not build the workflow that transitions a number to `released` or that talks to Telnyx to acquire/release one.
* Default-sender automation resolution — Slice 6.
* New automation-trigger/outbox producers — Slice 8.
* Managed Accounts (the future, owner-evaluated migration target, §28.3) — not built, not interfaced, not referenced by any schema column.
* Customer-accessible platform credentials — never built, in any slice.
* Live Telnyx calls during development or automated tests — the fake adapter is the only one exercised by the test suite; `Http::preventStrayRequests()` (§4.4, item 19) makes any unmatched real HTTP call in the test suite an immediate test failure, for any adapter, not only the messaging one.
* **Slice-4-shaped interface methods (number search/order, registration submission) are not supplied at all in Slice 3 — not as inert stubs, not as dead code paths.** `MessagingProviderAdapter` (§4.3) defines exactly three methods: `send()`, `verifyInboundSignature()`, `parseInboundWebhook()`. Number and registration operations are Slice 4's own interface extension.
* **Building Ed25519 webhook-verification material storage for BYO Telnyx connections** — the schema and UI to let a BYO Telnyx customer supply their own account's public key (which would let a future slice apply Option A to Telnyx too) is explicitly deferred to Slice 9 (Advanced BYO provider migration, which already owns §11.4/§11.5). Slice 3 only builds the fail-closed disablement, not the upgrade path out of it.
* **Full signature verification for the other ~58 legacy providers** sharing `inboundDLR()`'s boundary remains out of scope and is not claimed as fixed — only the shared default-to-user-1 write is removed for all of them (§4.6); this is stated honestly, not silently carried forward as if it were a complete fix, consistent with the parent contract's own §11.1 practice.
* **Retail pricing of any measured quantity.** `business_usage_measurements` (§4.8) records quantity only; no future-slice pricing/reconciliation mechanism is built, promised, or interfaced by Slice 3 beyond the schema's own generic shape remaining usable for one later.

## 4.2 EXACT SCHEMA

### `business_messaging_identities` (new table — one per managed Business, Messaging-Profile-level only)

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint, PK | no | — | |
| `uid` | string(36) | no | — | `Str::uuid()`, **unique**. Never `uniqid()`. |
| `business_id` | unsigned bigint, FK -> `businesses.id`, `restrictOnDelete()` | **no** | — | Never nullable — every row is authoritatively owned by exactly one Business from creation. |
| `provider` | string, cast to `App\Enums\Messaging\MessagingProvider` | no | — | Exactly one case at launch: `TELNYX`. |
| `status` | string, cast to `App\Enums\Messaging\BusinessMessagingIdentityStatus` | no | `pending` | `pending` \| `active` \| `suspended` \| `archived`. |
| `messaging_profile_id` | string | no | — | The Telnyx Messaging Profile ID. **Unique.** One of two independent inbound-attribution signals (§4.6). Admin-visible only. |
| `messaging_connection_id` | string | yes | `null` | Mirrors the legacy `c2` "Message Connection ID" optional field — a profile/connection-level setting, not a number-level one, so it stays here rather than moving to `business_messaging_numbers`. |
| `activated_at` | timestamp | yes | `null` | |
| `archived_at` | timestamp | yes | `null` | |
| `created_at`, `updated_at` | timestamp | — | — | |

**No `phone_number` column on this table — corrected this round.** A managed
Business may have dedicated phone **number(s)**, plural, per the already-locked
architecture; a single column here could not honestly represent that. Number
mapping moves entirely to `business_messaging_numbers` below.

**Indexes/constraints:** `UNIQUE(uid)`, `UNIQUE(messaging_profile_id)`, index
on `business_id`; one-active-or-pending-identity-per-Business is enforced
exactly as before — a transactional existence check in
`BusinessMessagingIdentityResolver::create()` plus the hard
`UNIQUE(messaging_profile_id)` backstop.

**Deliberately absent, per the parent contract's provider-neutrality
discipline (§21.2):** no `provider_managed_account_id` column; no
dormant/nullable Managed-Account-shaped column of any kind; no
customer-visible platform credential column; no generic JSON "extra data"
escape-hatch column; **no credential column of any kind** — under Candidate B
there is exactly one platform Telnyx credential, shared by every managed
Business, never stored per-Business (§4.4).

### `business_messaging_numbers` (new table — one-to-many, corrected this round)

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint, PK | no | — | |
| `business_messaging_identity_id` | unsigned bigint, FK -> `business_messaging_identities.id`, `restrictOnDelete()` | no | — | Every number belongs to exactly one identity; a Business's identity can own many. No redundant `business_id` column — every query resolves the owning Business by joining through this identity, so there is exactly one source of truth for ownership, never two that could drift apart. |
| `phone_number` | string, E.164 | no | — | Normalized to E.164 at write time (§4.9); the sole authoritative representation, never a second, differently-formatted copy. |
| `provider_number_reference` | string | yes | `null` | Telnyx's own number-resource identifier, when distinct from the E.164 string itself. Admin-visible only. |
| `status` | string, cast to `App\Enums\Messaging\BusinessMessagingNumberStatus` | no | `pending` | `pending` \| `active` \| `suspended` \| `released`. `released` exists as a queryable end-state; the workflow that transitions a number into it is Slice 4's (§4.1). |
| `is_primary` | boolean | no | `false` | The default-sending-number indicator (below). |
| `activated_at` | timestamp | yes | `null` | |
| `released_at` | timestamp | yes | `null` | |
| `created_at`, `updated_at` | timestamp | — | — | |

**Indexes/constraints:**

* `UNIQUE(phone_number) WHERE status IN ('pending','active')` (partial/
  application-enforced, mirroring the identity table's pattern) — **no
  provider phone number belongs to two Businesses** at the same time. A
  `released` number's row is retained (never deleted) but no longer
  participates in that uniqueness window, honestly modelling that Telnyx may
  reassign a released number later without this schema claiming an
  impossible eternal reservation.
* `UNIQUE(business_messaging_identity_id) WHERE is_primary = true AND status
  = 'active'` (partial/application-enforced) — **at most one active primary
  number per identity**, checked transactionally in
  `BusinessMessagingIdentityResolver::attachNumber()` the same way identity
  creation is guarded (§4.9).
* Index on `business_messaging_identity_id`; index on `status`.

**Resolution rule, stated exactly (no fallback to "the first number"):**
outbound sending resolves the identity's single **active, primary** number
deterministically; if none is marked primary, or more than one is (which the
constraint above should make structurally impossible but is still checked),
resolution fails closed with `MessagingIdentityConflictException` and makes
zero provider calls — **never** "pick whichever row sorts first." Explicit
per-message number selection among several active numbers is a Slice-4-or-later
capability, not built here.

### `business_messaging_operations` (new table — operational transport state only, replaces the withdrawn `business_messaging_usage_events`)

**Corrected this round.** This table is **explicitly non-financial** and
holds only what a send/receive operation needs to be tracked, correlated,
and de-duplicated — never a usage/accounting concern.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint, PK | no | |
| `business_id` | unsigned bigint, FK -> `businesses.id`, `restrictOnDelete()` | no | |
| `business_messaging_identity_id` | unsigned bigint, FK -> `business_messaging_identities.id`, nullable, `nullOnDelete()` | yes | `null` for BYO transport. |
| `transport_mode` | string, cast to `App\Enums\Messaging\MessagingTransportMode` | no | `managed` \| `byo`. |
| `provider` | string, cast to `MessagingProvider` | no | The transport's actual provider, for BYO too. |
| `direction` | string | no | `outbound` \| `inbound`. |
| `message_type` | string | no | `sms` \| `mms`. |
| `operation_key` | string | yes | **Unique.** Outbound-send idempotency key, caller-supplied, present on outbound rows only (mirrors `agency_prospect_messages.operation_key`, item 11). |
| `provider_message_id` | string | yes | Present once the provider has confirmed receipt/acceptance, or immediately on an inbound row. |
| `status` | string, cast to `App\Enums\Messaging\MessagingOperationStatus` | no | `attempted` \| `accepted` \| `rejected` \| `delivered` \| `failed`. |
| `error_category` | string, nullable, cast to `ProviderErrorCategory` | yes | |
| `occurred_at` | timestamp | no | |
| `created_at`, `updated_at` | timestamp | — | |

**What this table explicitly does NOT contain, by design (corrected this
round):** wallet balance; retail amount; debit/credit; rate; reservation
amount; payer; invoice state; spending-cap state; webhook-rejection detail
(§4.6's `messaging_webhook_rejections` owns that); and — separated from this
table entirely — the RFC-005-owned measurement quantity itself
(`business_usage_measurements`, §4.8 owns that). This table only answers "did
this specific send/receive happen, was it accepted, what is its current
delivery state, and how do we find it again."

**Indexes/constraints:**

* `UNIQUE(operation_key) WHERE operation_key IS NOT NULL` — outbound
  idempotency.
* `UNIQUE(provider, provider_message_id) WHERE provider_message_id IS NOT
  NULL` — one shared namespace per provider across both directions (item
  11's evidence), the inbound-replay guard and the DLR-replay guard alike.
* Index on `(business_id, occurred_at)` for support/reporting queries.

### `business_usage_measurements` (new table — RFC-005-owned, additive, generic; replaces the withdrawn table's measurement role)

**Corrected this round.** This table is owned by RFC-005's domain
(`app/Library/Usage/**`), not by Messaging — the sole write path into it is
the new `UsageWalletManager::recordMeasurement()` method (§4.8), keeping
`UsageWalletManager` the sole write authority for usage-billing-adjacent
state exactly as RFC-005 already requires for every table in its §25 scope.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint, PK | no | |
| `business_id` | unsigned bigint, FK -> `businesses.id`, `restrictOnDelete()` | no | |
| `feature_key` | string, cast to `App\Enums\Entitlement\PlatformFeature` | no | The new, additive `MessagingTransport` case (§4.8) — generic, not Telnyx-specific; any future measured-without-a-rate feature reuses the same table and method. |
| `quantity` | string (decimal-safe) | no | Follows `UsageWalletManager::reserve()`'s own existing `?string $estimatedQuantity` convention (RFC-005 §11/§13), not a native float. |
| `unit` | string | no | e.g. `segment`. Generic, not Telnyx-specific. |
| `transport_marker` | string, nullable | yes | `managed` \| `byo` when the caller is Messaging; generic enough for any future feature with the same self-provided-vs-platform-provided distinction — the column name itself names no provider. |
| `idempotency_key` | string | no | **Unique.** A repeat call with the same key returns the existing row; no duplicate measurement. |
| `occurred_at` | timestamp | no | |
| `created_at` | timestamp | no | |

**Indexes/constraints:** `UNIQUE(idempotency_key)`; index on
`(business_id, feature_key, occurred_at)`.

**Explicitly has no relationship to `business_usage_reservations`,
`business_usage_rates`, `business_usage_rate_activations`, or any
`business_usage_ledger_entries`-shaped table** — §4.8 details why, and why
this is still a genuinely RFC-005-owned addition rather than a
Messaging-owned table that merely claims to be outside RFC-005.

### `messaging_webhook_rejections` (new table — bounded security/rejection audit only, corrected this round)

**Payload minimization, by design:** no raw webhook body is stored. Only a
hash/fingerprint and a small set of non-sensitive, already-low-sensitivity
identifiers (comparable to what `phone_numbers.number` already stores
elsewhere in this schema) are retained.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint, PK | no | |
| `reason` | string, cast to `App\Enums\Messaging\WebhookRejectionReason` | no | `invalid_signature` \| `malformed_payload` \| `duplicate` \| `unknown_mapping` \| `conflicting_mapping`. |
| `provider` | string, cast to `MessagingProvider` | no | |
| `payload_hash` | string(64) | no | SHA-256 hex digest of the raw request body — enough to correlate/dedup without retaining the body itself. |
| `messaging_profile_id` | string, nullable | yes | Stored only if present and readable in the payload, even when unattributable — needed for support triage of a noisy Profile; never a credential. |
| `destination_number` | string, nullable | yes | Same rationale; E.164 if normalizable. |
| `profile_resolved_identity_id` | unsigned bigint, nullable | yes | Admin-only, informational, populated only for `conflicting_mapping` rows — the identity the Profile ID alone would have resolved to. **Never treated as authoritative attribution and never joined into conversation data.** |
| `number_resolved_identity_id` | unsigned bigint, nullable | yes | Same, for the destination-number signal. |
| `occurrence_count` | unsigned int | no, default `1` | Incremented, not duplicated, on a repeat of the same `(reason, provider, payload_hash)`. |
| `first_seen_at`, `last_seen_at` | timestamp | no | |
| `created_at` | timestamp | no | |

**No `business_id` column exists on this table at all** — by design,
consistent with "no Business attribution when attribution is unknown"
(§4.6): a row here is, by definition, a case where authoritative attribution
could not be established, so no attribution is recorded, only two
non-authoritative candidate identifiers for debugging the conflicting case.

**Indexes/constraints:** `UNIQUE(reason, provider, payload_hash)` — the
idempotent-upsert key; a repeat of the identical rejection increments
`occurrence_count` and updates `last_seen_at` rather than inserting a new
row, bounding storage growth under replay/retry.

**Retention and disposal:** governed by `config('messaging.webhook_rejection_retention_days')`
(default 30). A new, narrowly scoped scheduled command,
`app/Console/Commands/PurgeMessagingWebhookRejections.php` (new), registered
in `app/Console/Kernel.php`'s existing `schedule()` method (one new line),
hard-deletes rows whose `last_seen_at` is older than the configured window —
disposal, not soft-delete, since even the minimized content here should not
accumulate indefinitely. **No credential is ever stored on this table** (it
has no field capable of holding one); **no message body is retained** at
all, on any row, under any reason.

### Uniqueness/conflict rules, stated exactly

* **One active-or-pending managed identity per Business** — `UNIQUE(messaging_profile_id)` plus the transactional create-time check.
* **One active-or-pending primary number per identity** — the partial unique index on `business_messaging_numbers`.
* **No provider phone number belongs to two Businesses** — the partial unique index on `phone_number`.
* **A Business may have multiple active numbers** — `business_messaging_numbers` has no per-identity row-count limit; only the "at most one primary" constraint above.
* **BYO identity cardinality** is unchanged from today — `CustomerBasedSendingServer` already permits at most one non-managed dedicated connection per Business per provider type; Slice 3 does not alter this.
* **A Business can never have both a managed identity and an active BYO connection treated as ambiguously "the" sender for the same operation** — §4.5's resolver checks the managed identity first; no automatic "try BYO if managed fails" fallback.

### Forward migration/backfill/compatibility/`down()`

* All four new tables are created by ordinary additive migrations. None
  touches, backfills, or reads from `sending_servers`,
  `customer_based_sending_servers`, `phone_numbers`, `senderid`,
  `business_usage_reservations`, `business_usage_rates`,
  `business_usage_rate_activations`, or `platform_feature_usage_classifications`
  — no existing BYO credential is ever converted into a managed identity, and
  no existing RFC-005 table is written to by Slice 3's own code (only the
  one new, additive `UsageWalletManager` method, §4.8).
* `down()` on all four migrations is unconditional `Schema::dropIfExists()`
  in reverse dependency order (`business_messaging_numbers` before
  `business_messaging_identities`; the other two have no FK dependents) —
  safe because every one is new in this slice, with no pre-existing data to
  preserve on rollback.

## 4.3 PROVIDER-NEUTRAL INTERFACES

All under `app/Library/Messaging/` unless noted. Unchanged from the initial
pass except where this section notes a correction.

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
  (Ed25519 for Telnyx). Returns `false` — never throws — on any missing
  header, malformed signature, or timestamp outside an allowed skew window.
* `parseInboundWebhook()` — turns a verified raw body into an
  `InboundWebhookEvent`, including **both** `messagingProfileId` and the
  normalized destination number (corrected this round — §4.6 requires both).

**DTOs** (`app/Library/Messaging/DTO/`, plain readonly value objects, no
Eloquent):

* `OutboundMessageRequest` — `businessMessagingIdentityId: int`,
  `businessMessagingNumberId: int` (**corrected this round** — the specific
  resolved number, not an implicit single column), `toNumber: string`,
  `body: string`, `mediaUrls: array`, `operationKey: string`.
* `OutboundMessageResult` — `accepted: bool`, `providerMessageId: ?string`,
  `status: MessageDispatchStatus`, `errorCategory: ?ProviderErrorCategory`.
  Carries no raw provider response body.
* `InboundWebhookEvent` — `kind` enum (`MESSAGE_RECEIVED` \|
  `DELIVERY_STATUS`), plus `messagingProfileId`, `destinationNumber`
  (**corrected this round** — explicitly named and always populated when the
  provider payload carries it, since §4.6 now requires cross-checking it),
  `fromNumber`, `body`, `mediaUrls`, `providerMessageId`, `deliveryStatus`
  (nullable, populated only for `DELIVERY_STATUS`), `occurredAt`.

**Enums** (`app/Enums/Messaging/`):

* `MessagingProvider` — `TELNYX`.
* `BusinessMessagingIdentityStatus` — `PENDING`, `ACTIVE`, `SUSPENDED`, `ARCHIVED`.
* `BusinessMessagingNumberStatus` (**new this round**) — `PENDING`, `ACTIVE`, `SUSPENDED`, `RELEASED`.
* `MessagingTransportMode` — `MANAGED`, `BYO`.
* `MessageDispatchStatus` — `ACCEPTED`, `REJECTED`.
* `MessagingOperationStatus` (**new this round**) — `ATTEMPTED`, `ACCEPTED`, `REJECTED`, `DELIVERED`, `FAILED`.
* `ProviderErrorCategory` — `RETRYABLE`, `TERMINAL`, `CONFIGURATION`, `UNKNOWN`.
* `WebhookRejectionReason` (**new this round**) — `INVALID_SIGNATURE`, `MALFORMED_PAYLOAD`, `DUPLICATE`, `UNKNOWN_MAPPING`, `CONFLICTING_MAPPING`.

**Exceptions** (`app/Library/Messaging/Exceptions/`):

* `MessagingProviderNotConfiguredException` — thrown before any HTTP call
  when required config is absent/incomplete, **or when
  `managed_messaging_enabled` is false** (corrected this round, §4.4).
* `MessagingIdentityUnresolvedException` — no matching, unambiguous
  `BusinessMessagingIdentity`/`BusinessMessagingNumber` for a given
  resolution key.
* `MessagingIdentityConflictException` — two or more candidates found, or
  two independent resolution signals disagree (**corrected this round** —
  this exception now also covers §4.6's Profile-vs-number conflict, not only
  a data-integrity race).

**Implementations:**

* `TelnyxMessagingAdapter implements MessagingProviderAdapter` — constructed
  with the platform credential read from `config('services.telnyx')`;
  throws `MessagingProviderNotConfiguredException` if `managed_messaging_enabled`
  is false or any required key is absent (§4.4). Uses Laravel's `Http`
  facade, never raw `curl`.
* `FakeMessagingAdapter implements MessagingProviderAdapter` — deterministic,
  in-memory, records every call, exposes scripted rejections, and a
  `queueInboundWebhook()` helper. Bound only inside test `setUp()`.

**Orchestration** (application services, not adapters):

* `BusinessMessagingIdentityResolver` — `resolveForBusiness(Business
  $business): ?BusinessMessagingIdentity`; `resolveByMessagingProfileId(string
  $id): ?BusinessMessagingIdentity`; `resolveByPhoneNumber(string $number):
  ?BusinessMessagingIdentity` (**new this round** — joins through
  `business_messaging_numbers`); `resolvePrimaryNumber(BusinessMessagingIdentity
  $identity): ?BusinessMessagingNumber` (**new this round**); `create(Business
  $business, ...): BusinessMessagingIdentity`; `attachNumber(BusinessMessagingIdentity
  $identity, string $number, ...): BusinessMessagingNumber` (**new this
  round**) — all four resolution methods return `null`, never a guess, on
  anything short of exactly one unambiguous, active match.
* `ManagedMessageDispatcher` — the single outbound orchestration point,
  writing to `business_messaging_operations` and calling
  `UsageWalletManager::recordMeasurement()` directly (**corrected this
  round** — no intermediate Messaging-owned usage-recording class; the write
  authority is `UsageWalletManager` itself, not a Messaging wrapper around
  it).
* `InboundWebhookAttributionResolver` — rewritten this round to require both
  independent signals to agree (§4.6).
* `MessagingWebhookRejectionRecorder` (**new this round**, replaces the
  withdrawn idea of logging rejections into the usage table) — the sole
  writer of `messaging_webhook_rejections`, implementing the idempotent
  upsert-by-`(reason, provider, payload_hash)` behaviour.

**Provider acceptance vs local persistence.** Unchanged: a local database
write is never itself treated as proof of provider acceptance; delivery
state is written only from an authenticated inbound `DELIVERY_STATUS` event.

## 4.4 CONFIGURATION AND CREDENTIAL CUSTODY

**`config/services.php` — new `telnyx` block** (keys only, no values):

```php
'telnyx' => [
    'api_key' => env('TELNYX_API_KEY'),
    'webhook_public_key' => env('TELNYX_WEBHOOK_PUBLIC_KEY'),
    'mode' => env('TELNYX_MODE', 'sandbox'), // 'sandbox' | 'live'
],
```

**`config/messaging.php` (new)** — non-secret behavioural configuration:

```php
return [
    'managed_messaging_enabled' => env('MANAGED_MESSAGING_ENABLED', false),
    'default_provider' => 'telnyx',
    'webhook_rejection_retention_days' => env('MESSAGING_WEBHOOK_REJECTION_RETENTION_DAYS', 30),
];
```

**Corrected this round — activation requires two independent things, not
one.** `managed_messaging_enabled` (default **false**) is a platform-wide
kill switch, deliberately separate from `services.telnyx.*`'s presence:

* `TelnyxMessagingAdapter`'s constructor throws
  `MessagingProviderNotConfiguredException` if **either**
  `config('messaging.managed_messaging_enabled')` is falsy **or** any of
  `services.telnyx.api_key`/`webhook_public_key` is empty. **Real credentials
  existing in the environment are, by themselves, insufficient to activate
  production traffic** — the explicit switch must also be on. This is a
  deliberate, disclosed policy choice: an environment that happens to carry
  real Telnyx credentials (e.g. staged ahead of a Slice 4 launch) does not
  thereby start sending real messages.
* `ManagedMessageDispatcher` catches that exception and returns
  `MessageDispatchStatus::REJECTED` / `ProviderErrorCategory::CONFIGURATION`
  — a clearly reported failure, never a false "sent."

**Test-environment network safety — corrected this round, replacing the
withdrawn "never resolved" claim.** Container resolution is not itself a
security boundary (an adapter can be constructed without making a request);
the real guarantees are:

* Every test that exercises `ManagedMessageDispatcher` binds
  `FakeMessagingAdapter` via `$this->app->instance(MessagingProviderAdapter::class,
  new FakeMessagingAdapter())` in `setUp()` — the same pattern as
  `FakeAgencyProspectingMessageSender` (item 15).
* `tests/TestCase.php` (existing — one new line) calls
  `Http::preventStrayRequests();` globally, so **any** unmatched real HTTP
  request from **any** test — not only a messaging one — fails that test
  immediately (Laravel 12's built-in mechanism, confirmed available, item 19).
  This is the actual network-safety boundary, not an assertion about which
  classes the container happened to construct.
* Request-shape tests for the real adapter use `Http::fake()` with an
  explicit, matched fixture — an unmatched request under `Http::fake()` is
  caught by the same `preventStrayRequests()` guard.
* `managed_messaging_enabled` defaults to `false` in every environment
  including `testing` — a test that wants to exercise
  `TelnyxMessagingAdapter`'s own logic under `Http::fake()` must explicitly
  set it to `true` in that test's own config override, making the
  activation visible in the test itself rather than ambient.

**No committed secret anywhere.** No credential value appears in this
document, `config/messaging.php`, or any migration/seeder. No credential in
views/logs/events/exceptions/serialized jobs/test snapshots — exception
messages name the missing config **key**, never a value; no Job serializes
an adapter instance.

**Platform credentials are never stored per-Business under Candidate B** —
`business_messaging_identities`/`business_messaging_numbers` carry no
credential column at all.

**BYO credentials stay isolated to the advanced path, with its existing
storage mechanism unchanged.** `SendingServer`'s credential columns are not
given an `encrypted` cast in Slice 3 — stated honestly: BYO Telnyx/Twilio
credentials remain stored in plaintext in `sending_servers` after Slice 3,
exactly as today. This round's Twilio-inbound fix (§4.6) uses the **existing**
`auth_token` value as-is; it does not add encryption to it.

**Config-cache behaviour.** Ordinary Laravel config; `TelnyxMessagingAdapter`
reads configuration once at construction, not per-call, consistent with the
`stripe`/`google_business_profile` convention.

## 4.5 OUTBOUND ISOLATION

**Full flow, corrected this round for multi-number identities and the
RFC-005-owned measurement call:**

1. **Business resolution.** Reused from the calling flow's existing tenancy
   resolution — no new resolution invented here.
2. **Identity resolution.** `BusinessMessagingIdentityResolver::resolveForBusiness($business)`.
   `null` on anything short of exactly one `active` identity → fall through
   to the legacy/BYO path unchanged, zero provider calls.
3. **Primary number resolution (new step, corrected this round).**
   `resolvePrimaryNumber($identity)`. `null` on zero or more-than-one active
   primary number → fail closed with `MessagingIdentityConflictException`,
   zero provider calls — **no fallback to "the first number."**
4. **Authorization/status check.** Folded into steps 2-3 — the resolver never
   accepts a caller-supplied identity or number ID for the outbound path; it
   always re-resolves from the tenancy-verified `Business` model.
5. **Messaging Profile + number selection.** Read directly off the resolved
   identity (`messaging_profile_id`) and the resolved primary number
   (`phone_number`) — no separate lookup.
6. **Operational record + usage measurement (corrected this round).**
   `ManagedMessageDispatcher` writes an `attempted` row to
   `business_messaging_operations` keyed by `operationKey`, then calls
   `app(UsageWalletManager::class)->recordMeasurement($business,
   PlatformFeature::MessagingTransport, $quantity, 'segment',
   $operationKey, transportMarker: 'managed')` — **both writes happen before
   the adapter call**, and both are idempotent on the same key. No RFC-005
   `reserve()`/`commit()`/`release()` call is made for managed telecom
   transport in Slice 3.
7. **Provider adapter.** `MessagingProviderAdapter::send($request)`.
8. **Provider-confirmed acceptance.** Only `OutboundMessageResult::$accepted
   === true` is treated as success; the `business_messaging_operations` row
   is updated to `accepted`/`rejected` with the adapter's own
   `$providerMessageId`.
9. **Local persistence/settlement.** The calling flow's existing
   conversation/message persistence proceeds using the confirmed
   `$providerMessageId`.

**Proofs (updated table/column names, otherwise unchanged in substance):**

* **Business A can never send through Business B's number/profile.** Steps 2-4's re-resolution, combined with the two partial unique indexes (§4.2), is a structural guarantee. T-MSG-11.
* **Forged IDs fail before provider invocation.** No request-input path into identity/number selection exists. T-MSG-12.
* **Inactive/suspended/archived/missing/conflicted identities or numbers make zero provider calls.** T-MSG-13 (identity), T-MSG-6 (number).
* **No fallback to an arbitrary first sending server or number.** Step 3's explicit fail-closed rule, never `getSendingServer()`'s "first active" pattern.
* **Provider request construction uses only the resolved Business identity and number.**
* **Platform-wide credential failure, or the switch being off, is reported honestly, never becomes false per-Business success.**
* **No retail rate activation.** `recordMeasurement()` never calls `setActiveRate()`/`activateMetering()` (§4.8).

## 4.6 INBOUND/DLR FAIL-CLOSED ROUTING

**Corrected this round in three ways:** (a) attribution now requires two
independent signals to agree, not one; (b) the shared legacy fail-open
default is actually removed, not left beside a new route; (c) response-code
claims are limited to what Telnyx's own published documentation states.

### 4.6.1 The managed route (new)

* New route, `routes/public.php` (one new line, under the already
  CSRF-exempt `inbound/*` prefix):
  `Route::post('inbound/telnyx-managed', 'Customer\DLRController@inboundTelnyxManaged')->name('inbound.telnyx_managed');`
* New method, `DLRController::inboundTelnyxManaged(Request $request)` —
  delegates immediately to `InboundWebhookAttributionResolver::handle($request)`.
* `routes/web.php`'s two duplicate/dead Telnyx lines (`:45`, `:73`) are
  **removed** in this correction — not left standing beside the new route.

### 4.6.2 `InboundWebhookAttributionResolver::handle()` — resolution priority, exactly

1. **Signed webhook verification, first, always.**
   `MessagingProviderAdapter::verifyInboundSignature($rawBody, $headers)`
   against the platform's own known Telnyx Ed25519 public key. Failure here
   returns `403`, writes one `invalid_signature` row to
   `messaging_webhook_rejections`, and does nothing else.
2. **Payload parse.** `parseInboundWebhook($rawBody)` → `InboundWebhookEvent`.
   A payload that passes signature verification but fails to parse into the
   expected shape returns `400`, writes one `malformed_payload` rejection
   row, and does nothing else.
3. **Provider-message-ID replay check.** Guarded upsert against
   `business_messaging_operations`'s `UNIQUE(provider, provider_message_id)`.
   A duplicate returns `200` (see 4.6.4) and does nothing further.
4. **Dual-signal attribution — corrected this round, the central fix.**
   Both of the following are resolved **independently**:
   * `identityByProfile = BusinessMessagingIdentityResolver::resolveByMessagingProfileId($event->messagingProfileId)`
   * `identityByNumber = BusinessMessagingIdentityResolver::resolveByPhoneNumber($event->destinationNumber)`

   Processing proceeds **only when all of the following hold**:
   * `$event->messagingProfileId` is present and non-empty;
   * `$event->destinationNumber` is present and E.164-normalizable;
   * `identityByProfile` is not `null` (the Profile ID resolves to exactly
     one active, non-archived identity);
   * `identityByNumber` is not `null` (the destination number resolves to
     exactly one active, non-suspended/non-released number mapping, joined
     to its identity);
   * `identityByProfile->id === identityByNumber->id` (both signals name the
     **same** Business identity).

   Any other outcome fails closed:

   | Case | Rejection reason | Response |
   |---|---|---|
   | Profile ID missing from payload | `malformed_payload` | `400` |
   | Destination number missing/unnormalizable | `malformed_payload` | `400` |
   | Profile ID unknown (`identityByProfile` null) | `unknown_mapping` | `200` |
   | Destination number unknown (`identityByNumber` null) | `unknown_mapping` | `200` |
   | Profile resolves to Business A, number resolves to Business B | `conflicting_mapping` | `200` |
   | Either mapping resolved but inactive/suspended/archived/released | `unknown_mapping` | `200` |
   | Multiple matches for either signal (should be structurally impossible; checked anyway) | `conflicting_mapping` | `200` |

   Every row in this table writes exactly one (idempotently upserted)
   `messaging_webhook_rejections` row via `MessagingWebhookRejectionRecorder`,
   and — critically — updates **no** conversation/message row, debits **no**
   wallet, triggers **no** automation, and attributes to **no** Business.
5. **Only on full agreement:** proceed to persist the inbound message against
   the single, mutually-confirmed `BusinessMessagingIdentity`, write the
   `business_messaging_operations` row (`direction = inbound`), and call
   `recordMeasurement()` (transport marker `managed`).

**Never** a global-first-match, an optional Business fallback, a
user-provided Business ID, the source phone number, a URL path segment, or
any single-signal shortcut. Neither the Messaging Profile ID nor the
destination number is ever, on its own, sufficient — this is the mechanical
fix for the initial pass's "Profile ID as sole attribution key" defect.

### 4.6.3 Delivery-status attribution

A `DELIVERY_STATUS` event resolves **first** through the recorded outbound
operation, by `provider_message_id`, against `business_messaging_operations`
(never against a usage-measurement row, and never against the legacy
`reports.status` packing). If the event's payload **also** carries
`messagingProfileId`/`destinationNumber` evidence (Telnyx's own published
`message.finalized` example payload includes both), that evidence is
independently resolved and cross-checked against the stored operation's own
`business_messaging_identity_id`; a mismatch is a `conflicting_mapping`
rejection — the status update is **refused**, not applied — rather than
trusting either source blindly. An unknown `provider_message_id` is treated
identically to the unknown-mapping case above.

### 4.6.4 Response-code and retry claims — corrected this round

Telnyx's own published webhook documentation (scraped and read as part of
this correction) states only: a response within 2000ms with a `2xx` status
is treated as successful receipt; any other outcome — timeout or any
non-`2xx` status — "will indicate to Telnyx that you did not receive the
webhook," triggering a retry. **It does not document a fixed retry count,
backoff schedule, or any distinction in retry behaviour between different
non-2xx status codes.** (A separately documented 1-minute/10-minute/1-hour
retry cadence exists only for an unrelated subsystem — 10DLC
campaign-reactivation processing — and this document does not apply that
schedule to webhook delivery, which would be citing evidence out of
context.) Accordingly, **this document makes no claim that `403`, `400`, or
any other specific status code suppresses or alters Telnyx's retry
behaviour.** Response codes are chosen for semantic honesty (`403` = we do
not trust this request's authenticity; `400` = we could not parse an
authenticated request; `200` = we received and fully handled this, including
the handling of "we cannot attribute it"; `500` reserved for a genuine,
unexpected internal failure, where a retry is actually desirable and
correct), and **correctness against duplicate delivery is achieved entirely
through the idempotency guards in §4.2/§4.9, never through a status-code
choice.**

### 4.6.5 Legacy BYO inbound — corrected this round, no longer left fail-open

**The shared boundary fix (all ~60 providers).** `DLRController::inboundDLR()`'s
existing `else` branch (item 7, `:917-934`) is edited so that when
`$phone_number` cannot be resolved, **no** `Reports`/`ChatBox` write occurs
and **no** STOP/blacklist processing runs — the call instead writes one
`unknown_mapping`-reasoned row to `messaging_webhook_rejections` (`provider`
taken from the calling method) and returns the method's existing generic
"processed" response shape, so no legacy provider's polling/webhook
expectations are broken. No repository caller passes an explicit `$user_id`
to `inboundDLR()` today (item 7's grep), so this changes no correct existing
behaviour — it only removes the specific unattributed write. This benefits
**every** provider that flows through `inboundDLR()`, with no accompanying
claim that any of the other ~58 non-Telnyx/Twilio providers now has
signature verification — they do not, and this document says so plainly
(§4.1's exclusions).

**BYO Twilio — Option A, secured now.** `DLRController::inboundTwilio()` is
edited to require `Twilio\Security\RequestValidator` verification before
calling `inboundDLR()`. Because the route carries no tenant identifier, and
more than one Twilio `SendingServer` may be active, verification iterates
the active Twilio `SendingServer` rows and accepts the first whose
`auth_token` validates the given signature (a genuine HMAC-style match
against an independently-set secret is itself strong proof of which account
produced the request; the probability of an unrelated `auth_token`
coincidentally validating an unrelated signature is cryptographically
negligible). This is an accepted, disclosed, bounded CPU cost (a handful of
local HMAC computations per request, not an external call), not a schema
change — no `SendingServer` column is added. A request that validates
against none of them is rejected before `inboundDLR()` is called, logged as
`invalid_signature`.

**BYO Telnyx — Option B, fail closed until upgraded.** No `SendingServer`
column exists to hold a per-connection Ed25519 public key (item 4/19), so
verifying an arbitrary BYO customer's own Telnyx account's webhook signature
is not mechanically possible today. `DLRController::inboundTelnyx()` is
edited to check, read-only, whether the resolved `SendingServer` is linked
to a `CustomerBasedSendingServer` row (i.e., is a Business-facing BYO
connection reachable from `MessagingChannelsController`'s own surface, per
its existing `isManagedConnection()`-adjacent check, item 16) — if so, and
the provider is Telnyx, inbound processing is **disabled**: no
`inboundDLR()` call, one `unknown_mapping`-adjacent audit row (a distinct,
explicit disablement reason may be added to `WebhookRejectionReason` at
implementation time if useful; not required for this contract), and a `200`
acknowledgment (there is nothing actionable to tell Telnyx, and the customer
is not a signature-verified party we owe a retry signal to). **The relocated
advanced-settings UI (§4.7) states this honestly** — a BYO Telnyx connection
shows that inbound message receiving is unavailable pending a security
upgrade, while outbound sending is unaffected. An admin-only/legacy Telnyx
connection with **no** `CustomerBasedSendingServer` link (not reachable from
any Business-facing surface at all) is outside this gate and outside Slice
3's customer-accessible-BYO scope entirely — its pre-existing behaviour,
including the now-fixed shared default-to-1 removal but not any signature
check, is unchanged, and this is disclosed rather than silently left
ambiguous.

**Canonical route, unambiguous.** After this correction there is exactly one
legacy Telnyx inbound route (`routes/public.php:26`, now BYO-disabled) and
exactly one new managed Telnyx inbound route
(`inbound/telnyx-managed`) — no duplicate can bypass either, because the
duplicates are removed, not merely outnumbered.

### 4.6.6 Test against the real production entry points

§4.12's T-MSG-16 (managed route, full cross-check success), T-MSG-19..23
(every fail-closed combination), and T-MSG-20/T-MSG-22/T-MSG-23 (legacy
`inboundDLR()`/`inboundTwilio()`/`inboundTelnyx()`) all issue real `POST`
requests to the real routes — never calling
`InboundWebhookAttributionResolver::handle()` directly as a shortcut for
these specific assertions.

## 4.7 BYO RELOCATION

Restating the superseded B2 docblock rules
(`MessagingChannelsController.php:22-39`, item 16) exactly, per the parent
contract's §27 C-5:

> A "connection" is represented entirely with existing schema: Business ->
> CustomerBasedSendingServer -> SendingServer. A connection always gets its
> own dedicated, non-shared SendingServer row (never silently shared across
> Businesses); a pre-existing legacy/admin assignment that IS shared (or
> whose SendingServer's legacy owner isn't this Business's owner) is
> surfaced read-only ("Managed"). Every action resolves its Business via the
> exact RFC-003 §14.1 boundary — never `business.customer_id ===
> Auth::id()`.

These rules are **unchanged** by relocation — only the surface they're
reached from moves, and — corrected this round — the old surface is actually
removed, not left standing.

**`manage_advanced_provider` — corrected this round, exact representation.**
This flag **does not exist in application code today** (item 19) — this
document stops referring to it as if it did. Its representation:

* One new entry in `config/customer-permissions.php` (existing file, one
  array addition): `'manage_advanced_provider' => ['display_name' =>
  'manage_advanced_provider', 'category' => 'Messaging', 'default' =>
  false]` — the same conservative-default precedent `manage_google_business_profile`
  already establishes (item 19).
* Automatically registered as a Laravel `Gate` by the existing generic loop
  in `app/Providers/AuthServiceProvider.php:55-56` — **no change to that
  file is required.**
* Granted/revoked through the existing, generic
  `SubAccountController`-based permission-management surface, stored on
  `Customer.permissions` (item 19) — no new grant UI is built.
* **Additional, Slice-3-owned restriction, beyond the generic mechanism.**
  The generic sub-account permission system is not role-hierarchy-aware; it
  would let anyone who can edit a sub-account's permissions grant this key to
  any sub-account, including a Business-scoped one, which is stricter than
  the parent contract's own §6 table (Platform owner/Agency owner only). The
  relocated advanced-settings route therefore checks **both**
  `Gate::allows('manage_advanced_provider')` **and** an explicit,
  Slice-3-owned check that the acting account is Agency- or Platform-tier —
  reusing whichever existing account-tier check this repository already uses
  elsewhere for Agency-gated capabilities. This document does not invent a
  method name for that check where it has not independently verified one;
  identifying and citing the exact existing method is a required, narrow
  step at implementation time, not a new capability to design from scratch.
* Audit behaviour: a grant/revoke of this specific key is recorded the same
  way every other `customer-permissions` change already is recorded by the
  existing `SubAccountController` flow — no new audit table is introduced
  for this alone.

**Genuine relocation, corrected this round.**

* New routes live under the existing `resources/views/customer/settings/advanced/**`
  surface (`routes/customer.php`, narrow addition).
* **The old `businesses/{businessUid}/channels` routes
  (`routes/customer.php:975-983`) are removed**, not left reachable beside
  the new ones — a genuine rename, not a permanent duplicate surface. A
  request to the old path resolves to Laravel's ordinary 404 for an
  undefined route; no redirect is required to satisfy "relocation," though
  one may be added at implementation time as a convenience without changing
  this contract's authorization guarantees.
* `resolveAccessibleBusiness()`/`resolveOwnedConnection()` (item 16) are
  reused verbatim at the relocated routes — not reimplemented, not weakened.
* An ordinary Business-role user cannot reach the relocated surface at all —
  neither by navigation (never linked from an empty state or guided flow)
  nor by a direct URL, because the combined `manage_advanced_provider` +
  account-tier check in this section refuses it before
  `MessagingChannelsController`'s own tenancy checks even run.

**Locked behaviour, restated, otherwise unchanged from the initial pass:**
managed messaging is the normal experience; existing BYO connections are
retained, never auto-migrated; BYO transport gets zero platform transport
rate or wallet debit (§4.8's `recordMeasurement()` call with
`transport_marker: 'byo'` is how a BYO send is still measured); non-transport
services still meter/debit normally on a BYO Business; managed and BYO
identities cannot be ambiguously active for the same operation; BYO Telnyx
inbound is disabled per §4.6.5, honestly explained in this relocated UI.

## 4.8 USAGE MEASUREMENT WITHOUT RETAIL CHARGING

**Corrected this round — the seam is now genuinely RFC-005-owned, not a
Messaging-owned table declared "outside" RFC-005 by name.**

**Exact seam.** One new, additive public method on the existing
`app/Library/Usage/UsageWalletManager.php` (the same file RFC-005 already
designates the sole write authority for its own §25 tables):

```php
public function recordMeasurement(
    Business $business,
    PlatformFeature $featureKey,
    string $quantity,
    string $unit,
    string $idempotencyKey,
    ?string $transportMarker = null,
): BusinessUsageMeasurement
```

* Writes exactly one row to the new `business_usage_measurements` table
  (§4.2), guarded by `UNIQUE(idempotency_key)` — a repeat call with the same
  key returns the existing row.
* **Never** calls `setActiveRate()` or `activateMetering()`.
* **Never** inserts into `business_usage_rates`, `business_usage_rate_activations`,
  `business_usage_reservations`, or any ledger-entry table.
* **Never** reads or writes `platform_feature_usage_classifications` — no
  classification row is created for `PlatformFeature::MessagingTransport`
  (**new, additive enum case** in the existing `app/Enums/Entitlement/PlatformFeature.php`,
  §4.11) in Slice 3. A future slice that activates a retail rate for this
  feature is the one that inserts a classification row and decides how
  already-recorded `business_usage_measurements` rows feed any
  reconciliation/backfill billing process — Slice 3 makes no promise about
  that mechanism, only that this table's generic shape (`feature_key`,
  `quantity`, `unit`) does not block one being built later.
* Is called directly by `ManagedMessageDispatcher` (outbound, transport
  marker `managed`) and `InboundWebhookAttributionResolver` (inbound,
  transport marker `managed`), and by the relocated BYO send path (transport
  marker `byo`) — in every case as a direct call to `UsageWalletManager`,
  never through a Messaging-owned intermediary that would re-introduce a
  shadow-ledger shape.

**Responsibility separation, stated exactly (corrected this round):**

| Responsibility | Owning table/mechanism |
|---|---|
| Outbound operation/idempotency | `business_messaging_operations.operation_key` |
| Provider acceptance | `business_messaging_operations.status` |
| Inbound message replay | `business_messaging_operations` `UNIQUE(provider, provider_message_id)` |
| DLR/delivery-status replay | Same constraint, status-transition guard |
| Security/rejection audit | `messaging_webhook_rejections`, via `MessagingWebhookRejectionRecorder` |
| Usage quantity measurement | `business_usage_measurements`, via `UsageWalletManager::recordMeasurement()` only |
| Wallet accounting | RFC-005's existing `business_usage_reservations`/ledger tables — **untouched by Slice 3** for telecom transport |

No table above serves more than one of these responsibilities.

**Future RFC-005 documentation debt, recorded but not made in this branch.**
`docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` will eventually
need a new subsection documenting `recordMeasurement()` and
`business_usage_measurements` as an RFC-005-owned, additive
measurement-only primitive, alongside its existing §11/§13/§14 material. That
edit is **not** made in this branch, because Lane A may currently be
touching that RFC document concurrently; the parent contract's existing §27
C-3 row (which already covers the §11.5 measurement-versus-debit distinction)
is the natural home for it and is extended, narrowly, in this correction
(§4.11).

**Tests demonstrate measurement while no retail rate is active** by
asserting directly against `business_usage_measurements` row counts/fields,
and by asserting `platform_feature_usage_classifications` carries no row at
all for `PlatformFeature::MessagingTransport` throughout the test run —
proving the RFC-005 rate/reservation machinery was never touched, not merely
unasserted-on.

## 4.9 CONCURRENCY, IDEMPOTENCY AND TRANSACTION BOUNDARIES

* **Duplicate outbound submission.** `operationKey` checked against
  `business_messaging_operations.operation_key` before calling `send()` — a
  duplicate is a no-op returning the previously recorded result.
* **Duplicate inbound webhook / duplicate DLR.** `UNIQUE(provider,
  provider_message_id)` guarded insert/status-transition guard, both sharing
  one table (§4.2's item-11-evidenced shared namespace).
* **Provider-message-ID uniqueness and scope.** Enforced at the database
  level, scoped per `provider` (a composite constraint), so Telnyx's and
  Twilio's ID spaces never collide even though both are UUID-shaped.
* **Identity-creation races.** `BusinessMessagingIdentityResolver::create()`
  wraps its existence check and insert in `DB::transaction()` +
  `lockForUpdate()`; the table's own unique constraints are the backstop.
* **Number-attachment races (new this round).** `attachNumber()` uses the
  identical transactional pattern against `business_messaging_numbers`; the
  partial unique indexes on `phone_number` and on
  `(business_messaging_identity_id) WHERE is_primary` are the hard backstop.
* **Retries after uncertain provider outcomes.** A timeout/ambiguous
  response is recorded as `rejected`/`RETRYABLE`, never `accepted`; **this
  document does not claim exactly-once external delivery.** A retry with the
  same `operationKey` updates/reuses the existing
  `business_messaging_operations` row rather than creating a second one, but
  if the prior attempt's outcome is genuinely unknown, this document does
  not prevent a second live provider request being attempted — only a
  second local row. This is a disclosed limitation, not solved here.
* **No second provider call after confirmed acceptance.** Once a row's
  `status = accepted` with a `provider_message_id` recorded, a further call
  with the same `operationKey` short-circuits and returns the recorded
  result without calling `send()` again.
* **DB transaction boundaries around provider calls.** `send()` is never
  called from inside an open transaction holding a broad/long-lived lock;
  the identity/number-creation `lockForUpdate()` transactions are
  create-time-only and commit before any adapter call; the per-send
  idempotency check is a short, separate transaction that commits before
  `send()` is invoked.
* **What's persisted before/after an external request.** Before: the
  `operationKey`-guarded `attempted` row and the `recordMeasurement()` row.
  After: the operation row is updated to `accepted`/`rejected`. No
  conversation/message row is created before the adapter call returns.
* **Replay never double-debits/duplicates/double-triggers automations.**
  Slice 3 creates no wallet debit for telecom transport, so there is nothing
  to double-debit; the operations table's replay guard is what prevents a
  replayed inbound webhook from creating a second conversation row.
* **Retention.** `business_messaging_operations` rows are retained
  indefinitely, as ordinary message/conversation history already is;
  `messaging_webhook_rejections` rows are purged per §4.2's retention policy;
  `business_usage_measurements` rows follow RFC-005's own general retention
  practice for usage-adjacent records (unchanged by Slice 3).

## 4.10 COMPATIBILITY AND MIGRATION

* Existing Twilio/Telnyx BYO connections continue behaving as today, with
  two narrow, disclosed exceptions this round introduces deliberately: BYO
  Twilio inbound now requires a valid signature (a **security** change, not
  a compatibility break — a legitimate Twilio webhook already carries a
  valid signature today, so no legitimate traffic is rejected); BYO Telnyx
  inbound is disabled until upgraded (§4.6.5), honestly surfaced in the UI.
  Outbound BYO sending is **unaffected** for both providers.
* Existing sending servers of every other provider type (~250 `TYPE_*`
  constants) keep exactly their current inbound behaviour except for the one
  shared fix (§4.6.5): the unconditional default-to-user-1 write is removed
  for all of them, changing no behaviour any real caller depends on (item 7).
* Existing campaigns, conversations, messages, automations, sender IDs, and
  phone-number records all continue functioning unchanged — the outbound
  delegation points only add a **preceding** check that falls through to the
  existing code path unchanged for every Business without a managed
  identity. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` and
  `tests/Feature/AgencyProspecting/AgencyProspectingRuntimeTest.php` must
  pass unmodified (§4.12 T-MSG-38).
* **No automatic credential migration** — no migration or resolver code
  reads a `SendingServer` credential and writes it anywhere in the new
  schema, which has no credential column to receive one.
* **No destructive migration** — all four new tables are pure additions.
* **No silent payer/wallet change** — no wallet reservation/debit for
  telecom transport is created by Slice 3.
* **No rewrite owned by Slice 4/6** — number provisioning UI/flow and
  default-sender automation resolution are not built here.

## 4.11 EXACT ALLOWLIST

**Corrected this round.** This replaces, in full, the previous revision's
§4.11 and the corresponding parent-contract §22.1 Slice 3 row.

**Implementation paths:**

`app/Library/Messaging/**` (new); `app/Library/Messaging/Contracts/**` (new);
`app/Library/Messaging/DTO/**` (new); `app/Library/Messaging/Exceptions/**`
(new); `app/Models/BusinessMessagingIdentity.php` (new);
`app/Models/BusinessMessagingNumber.php` (new, corrected this round);
`app/Models/BusinessUsageMeasurement.php` (new, corrected this round —
RFC-005-owned model); `app/Models/MessagingWebhookRejection.php` (new,
corrected this round); `app/Http/Controllers/Customer/Business/MessagingChannelsController.php`
(existing); `app/Enums/Messaging/**` (new);
`app/Enums/Entitlement/PlatformFeature.php` (existing — **corrected this
round, new addition**: one additive enum case, `MessagingTransport`, only —
no existing case renamed or removed); `app/Library/Usage/UsageWalletManager.php`
(existing — **corrected this round, new addition**: one additive public
method, `recordMeasurement()`, only — no existing method modified);
`resources/views/customer/business/MessagingChannels/**` (existing);
`resources/views/customer/settings/advanced/**` (new);
`config/services.php` (existing — new `telnyx` block only);
`config/messaging.php` (new); `config/customer-permissions.php` (existing —
**corrected this round, new addition**: one new array entry,
`manage_advanced_provider`, only); `app/Providers/AppServiceProvider.php`
(existing — binding addition only); `database/migrations/**` (new,
additive-only — the four tables in §4.2); `app/Http/Controllers/Customer/DLRController.php`
(existing — **corrected this round, widened**: one new method,
`inboundTelnyxManaged()`; a narrow edit to the existing `inboundDLR()`
method's fail-open `else` branch only; a narrow edit to the existing
`inboundTwilio()` method adding signature verification only; a narrow edit
to the existing `inboundTelnyx()` method adding the BYO-disable gate only —
every one of `DLRController`'s other ~57 provider methods stays untouched);
`routes/public.php` (existing — one new route line only); `routes/web.php`
(existing — **corrected this round, new addition**: removal of exactly the
two dead/duplicate Telnyx lines, no other line touched); `routes/customer.php`
(existing — new routes only, for the relocated advanced-settings surface,
plus removal of the old `businesses/{businessUid}/channels` route block);
`app/Repositories/Eloquent/EloquentCampaignRepository.php` (existing — the
pre-dispatch delegation insertion in `quickSend()` only);
`app/Models/Campaigns.php` (existing — the pre-dispatch delegation insertion
in its own dispatch switch only); `tests/TestCase.php` (existing —
**corrected this round, new addition**: one new line,
`Http::preventStrayRequests()`, in the base test setup only);
`app/Console/Commands/PurgeMessagingWebhookRejections.php` (new, corrected
this round); `app/Console/Kernel.php` (existing — **corrected this round,
new addition**: one new `$schedule->command(...)` line only).

**Test paths:** `tests/Feature/Messaging/**` (new); `tests/Feature/Security/**`
(existing); `tests/Feature/Usage/**` (existing); `tests/Feature/Business/**`
(existing — narrowly, `MessagingChannelsTest.php` and new
production-delegation/relocation-integration tests only).

**Documentation paths:** this document; the parent contract's §22.1
amendment (this correction) and the narrow extension to its existing §27 C-3
row (§4.8's RFC-005 documentation-debt note).

**Prohibited paths (explicit stop conditions):**

* Any line in `app/Models/SendCampaignSMS.php` — never touched by Slice 3.
* Any of `DLRController.php`'s other ~57 provider methods beyond the four
  named above.
* Any column, cast, or method on `app/Models/SendingServer.php` or
  `app/Models/CustomerBasedSendingServer.php` — read-only access only, for
  the BYO-connection-existence check in §4.6.5.
* Any write to `phone_numbers` or `senderid` from Slice 3's new code.
* Any `business_usage_rates`, `business_usage_rate_activations`,
  `business_usage_reservations`, or `platform_feature_usage_classifications`
  row insertion for `PlatformFeature::MessagingTransport`.
* Any `provider_managed_account_id` column, or any other Managed-Account-shaped
  identifier, anywhere.
* Any change to `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`
  itself, in this branch (§4.8's documentation-debt note explains why).
* Any real Telnyx API call, account, number, brand, campaign, or rate
  activation.

## 4.12 TEST MATRIX

**Renumbered cleanly this round**, replacing the previous T-MSG-1..30 in
full. Inherited from the parent contract (§24), unchanged: **T-PROV-1**,
**T-PROV-2**, **T-BYO-1**, **T-BYO-2**, **T-SCOPE-1** — all still owned by
Slice 3, none reused as a new ID below.

| ID | Assertion | Location |
|---|---|---|
| T-MSG-1 | A second `BusinessMessagingIdentity` creation attempt for a Business that already has a non-archived one fails closed before any provider call | `tests/Feature/Messaging/` |
| T-MSG-2 | `UNIQUE(messaging_profile_id)` violations raise a caught, reported exception, never a silent overwrite | `tests/Feature/Messaging/` |
| T-MSG-3 | A `pending`/`suspended`/`archived` identity resolves to `null` — never a partially-usable object | `tests/Feature/Messaging/` |
| T-MSG-4 | Neither `BusinessMessagingIdentity` nor `BusinessMessagingNumber` has any credential-shaped attribute, cast, or hidden field | `tests/Feature/Messaging/` |
| T-MSG-5 | Migration `up()`/`down()` round-trips cleanly on all four new tables, in correct dependency order, with no data-loss warning | `tests/Feature/Messaging/` |
| T-MSG-6 | **One Business, multiple phone numbers** — a Business's identity may own two or more active `BusinessMessagingNumber` rows simultaneously | `tests/Feature/Messaging/` |
| T-MSG-7 | **A number cannot belong to two Businesses** — attaching an already-active number to a second identity fails closed via the partial unique index | `tests/Feature/Messaging/` |
| T-MSG-8 | **Exact E.164 normalization** — a set of equivalent input formats for the same number all normalize to one canonical E.164 value before any uniqueness check runs | `tests/Feature/Messaging/` |
| T-MSG-9 | `EloquentCampaignRepository::quickSend()` for a Business with an active managed identity delegates to `ManagedMessageDispatcher`/`FakeMessagingAdapter`, never reaching `SendCampaignSMS`'s Telnyx `case` block | `tests/Feature/Business/` |
| T-MSG-10 | `Campaigns`'s own dispatch switch shows the same delegation for its bulk/scheduled path | `tests/Feature/Business/` |
| T-MSG-11 | Business A's resolved identity/number can never be used to construct an `OutboundMessageRequest` for Business B's send | `tests/Feature/Messaging/` |
| T-MSG-12 | A forged identity or number ID submitted as request input is never read by the outbound resolution path | `tests/Feature/Messaging/` |
| T-MSG-13 | Each non-`active` identity status produces zero `FakeMessagingAdapter` calls | `tests/Feature/Messaging/` |
| T-MSG-14 | Zero active-or-primary numbers, or more than one primary, fails closed with zero provider calls — no "first number" fallback | `tests/Feature/Messaging/` |
| T-MSG-15 | `FakeMessagingAdapter::send()` records the call and returns a scripted `OutboundMessageResult` deterministically | `tests/Feature/Messaging/` |
| T-MSG-16 | **Profile and destination number agree** — a real `POST` to `route('inbound.telnyx_managed')` with both signals resolving to the same active identity is processed and attributed correctly | `tests/Feature/Messaging/` |
| T-MSG-17 | **Known Profile + unknown number fails closed** — no processing, one `unknown_mapping` rejection row, `200` | `tests/Feature/Messaging/` |
| T-MSG-18 | **Unknown Profile + known number fails closed** — same shape, reversed | `tests/Feature/Messaging/` |
| T-MSG-19 | **Profile A + number-belonging-to-B fails closed** — one `conflicting_mapping` rejection row recording both candidate identity IDs, no attribution to either | `tests/Feature/Messaging/` |
| T-MSG-20 | **Inactive/suspended/released number mapping** with an otherwise-valid Profile fails closed | `tests/Feature/Messaging/` |
| T-MSG-21 | Invalid/missing signature on `inbound.telnyx_managed` returns `403`, writes one `invalid_signature` rejection row, updates no conversation data | `tests/Feature/Messaging/` |
| T-MSG-22 | **Delivery-status operation resolution and evidence cross-check** — a `DELIVERY_STATUS` event resolves via the stored `provider_message_id`; when it also carries Profile/number evidence that conflicts with the stored operation's identity, the status update is refused | `tests/Feature/Messaging/` |
| T-MSG-23 | Duplicate delivery of the same inbound/DLR webhook (`provider_message_id` replay) produces exactly one attributed effect, `200`, no second write | `tests/Feature/Messaging/` |
| T-MSG-24 | **Legacy Telnyx route cannot default to user 1** — a `POST` to `routes/public.php`'s legacy `inbound/telnyx/{gateway?}` with an unattributable `from` number writes no `Reports`/`ChatBox` row and no STOP/blacklist entry | `tests/Feature/Messaging/` |
| T-MSG-25 | **Legacy Twilio route cannot default to user 1** — same assertion against `inboundTwilio()` | `tests/Feature/Messaging/` |
| T-MSG-26 | **BYO Twilio inbound is securely verified** — a `POST` to the legacy Twilio route with a valid `X-Twilio-Signature` (matching a fixture `auth_token`) is processed; an invalid one is rejected before `inboundDLR()` runs | `tests/Feature/Messaging/` |
| T-MSG-27 | **Missing BYO verification material / BYO Telnyx inbound explicitly disabled** — a `POST` to the legacy Telnyx route for a `CustomerBasedSendingServer`-linked (BYO) connection makes no `Reports`/`ChatBox` write regardless of payload content, and records the disablement | `tests/Feature/Messaging/` |
| T-MSG-28 | **Duplicate/dead Telnyx route cannot bypass canonical handling** — `routes/web.php`'s two former duplicate lines no longer resolve to any route after this correction | `tests/Feature/Messaging/` |
| T-MSG-29 | **`manage_advanced_provider` flag authorization** — the relocated advanced-settings route is unreachable without both the granted permission and the Agency/Platform-tier check | `tests/Feature/Security/` |
| T-MSG-30 | **Old BYO routes removed/redirected** — the pre-relocation `businesses/{businessUid}/channels` routes no longer resolve after relocation ships | `tests/Feature/Business/` |
| T-MSG-31 | `TelnyxMessagingAdapter` throws `MessagingProviderNotConfiguredException` when `managed_messaging_enabled` is `false`, even with otherwise-complete `services.telnyx` config, before any HTTP call | `tests/Feature/Messaging/` |
| T-MSG-32 | Real credentials present but the enable switch off produces **zero** HTTP calls across a representative set of adapter operations | `tests/Feature/Messaging/` |
| T-MSG-33 | `Http::preventStrayRequests()` is active for the base test class; a deliberately unmatched HTTP call anywhere in a sample test fails immediately | `tests/Feature/Messaging/` |
| T-MSG-34 | A managed send writes exactly one `business_messaging_operations` row and exactly one `business_usage_measurements` row (via `recordMeasurement()`), with no row in either table serving the other's purpose | `tests/Feature/Messaging/` |
| T-MSG-35 | A BYO send (through the relocated advanced-settings path) writes exactly one `business_usage_measurements` row with `transport_marker = byo` and creates no wallet reservation/debit | `tests/Feature/Business/` |
| T-MSG-36 | Throughout the full Slice 3 suite run, `platform_feature_usage_classifications` carries no row at all for `PlatformFeature::MessagingTransport`, and no telecom feature's `is_metered` becomes `true` | `tests/Feature/Usage/` |
| T-MSG-37 | **Rejection records obey retention/minimization** — a `messaging_webhook_rejections` row never contains a raw message body or any credential; a repeated identical rejection increments `occurrence_count` rather than inserting a new row; the purge command removes rows past the configured retention window and leaves newer ones | `tests/Feature/Messaging/` |
| T-MSG-38 | `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` and `tests/Feature/AgencyProspecting/AgencyProspectingRuntimeTest.php` pass unmodified after every change in this correction | `tests/Feature/Usage/`, `tests/Feature/AgencyProspecting/` (regression) |
| T-MSG-39 | A concurrent pair of identity-creation or number-attachment calls for the same Business/number cannot both succeed | `tests/Feature/Messaging/` |
| T-MSG-40 | **Outbound/inbound/DLR idempotency remain independent** — forcing a duplicate on one of the three (same `operation_key`, same inbound `provider_message_id`, same delivery-status `provider_message_id`) does not suppress or interfere with processing of the other two for different keys in the same test run | `tests/Feature/Messaging/` |
| T-MSG-41 | No exception message or `OutboundMessageResult` contains a credential-shaped substring | `tests/Feature/Messaging/` |
| T-MSG-42 | A provider timeout/ambiguous response never produces `MessageDispatchStatus::ACCEPTED` | `tests/Feature/Messaging/` |

**Ownership.** Every ID above is owned by Slice 3 alone; none collides with
any ID in the parent contract's §24/§24.1. No ID is reused across two rows.

## 4.13 IMPLEMENTATION ORDER

**Corrected this round** to reflect the four-table schema, the RFC-005-owned
measurement seam, and the legacy-route work.

1. Migrations, enums (including the new `PlatformFeature::MessagingTransport`
   case), and the `BusinessMessagingIdentity`/`BusinessMessagingNumber`
   models.
2. Contracts, DTOs, exceptions — pure interfaces.
3. `FakeMessagingAdapter` and its test-suite `setUp()` binding pattern;
   `Http::preventStrayRequests()` added to `tests/TestCase.php`.
4. Configuration (`config/services.php`'s `telnyx` block,
   `config/messaging.php` including `managed_messaging_enabled`) and the
   default `AppServiceProvider` binding behind the fail-closed constructor
   check.
5. `TelnyxMessagingAdapter`'s real implementation, developed entirely
   against `Http::fake()`.
6. `BusinessMessagingIdentityResolver` (including number-resolution methods)
   and `ManagedMessageDispatcher`, wired into the two narrow production
   delegation points; the additive `UsageWalletManager::recordMeasurement()`
   method and `business_usage_measurements` table.
7. `DLRController::inboundTelnyxManaged()`, the new `routes/public.php` line,
   removal of `routes/web.php`'s two dead/duplicate lines, and
   `InboundWebhookAttributionResolver`'s dual-signal cross-check.
8. The three legacy `DLRController` edits: `inboundDLR()`'s fail-open
   removal (all providers), `inboundTwilio()`'s signature verification (BYO
   Twilio), `inboundTelnyx()`'s BYO-disable gate (BYO Telnyx); plus
   `messaging_webhook_rejections`, `MessagingWebhookRejectionRecorder`, and
   the purge command/schedule entry.
9. `manage_advanced_provider`'s representation
   (`config/customer-permissions.php`), the relocated advanced-settings
   routes/views with the combined permission + account-tier guard, and
   removal of the old routes.
10. Full regression: `ConversationsPlainSmsMeteringTest.php`,
    `AgencyProspectingRuntimeTest.php`, every existing
    `tests/Feature/Business/**`/`tests/Feature/Security/**` test, run
    alongside the full T-MSG-1..42 matrix plus the five inherited IDs.

Adjustable if implementation-time evidence proves a safer sequence
necessary — not itself authorization to implement (§1).

## 5. CONTRACT INTEGRITY SELF-CHECK

* **Already-existing behaviour** (traced, not re-implemented): items 1-19 of
  §2, including this round's new item 19 evidence.
* **Slice-3-will-implement:** every item in §4.1's inclusions; §4.2's four
  tables; §4.3's contracts/DTOs/adapters; the eight (widened, corrected)
  production delegation/edit points in §3/§4.11.
* **Deferred to Slice 4/6/9/Managed-Accounts-migration:** every item in
  §4.1's exclusions, including the BYO-Telnyx upgrade path (Slice 9) and
  number lifecycle workflow (Slice 4), both stated explicitly this round.
* **Assumptions:** none stated as fact without evidence; item 19's findings
  (`manage_advanced_provider` non-existence, `preventStrayRequests`
  availability) are as mechanically verified as items 1-18.
* **Mechanically-proven facts:** §2's 19 items; §3's executability table.
* **Already-locked human decisions, not re-litigated:** Candidate B (§28.3),
  no Managed-Account column (§21.2), no retail rate activation
  (§28.1/§28.1a), $5 funding floor and BYO billing semantics (§11.5).
* **No isolation control is described as "implemented"** anywhere — §4.5-§4.8
  describe what Slice 3 **will build**.
* **Every cited path/symbol** was verified against the merged tree at
  `6c820c801da08ecfd6165d1d3a52ae6336606f0c`, including this round's new
  greps (`manage_advanced_provider`, `preventStrayRequests`,
  `laravel/framework` version, `PlatformFeature`'s existing case list,
  `config/customer-permissions.php`, `AuthServiceProvider.php`'s Gate loop,
  `SubAccountController.php`'s permission storage).
* **Every internal `§` reference** resolves to a section in this document
  (§1-§4.13) or, when prefixed "parent contract," to that document's current
  numbering (§6, §11, §21, §22, §24, §27, §28), re-confirmed current.
* **Test-to-slice map has no duplicates or unowned tests:** T-MSG-1..42 are
  new, unique IDs, fully replacing the withdrawn T-MSG-1..30; the five
  inherited IDs are unchanged and were not renumbered.
* **No live credential value or secret-shaped example** appears anywhere.

## 6. VALIDATION

* Only two paths changed in this branch across both correction rounds: this
  document and the parent contract's §22.1 (and its narrow §27 C-3
  extension). No source code, migration, configuration, dependency, or
  generated asset changed.
* `git diff --check`: clean — verified below.
* Every cited path in §2-§4 exists in the merged tree, or is explicitly
  marked `(new)`.
* Every internal `§` reference resolves per §5.
* The §4.12 test-to-slice map carries no duplicate or unowned test ID.
* Stale-phrase sweep, corrected this round, additionally confirms: **zero**
  remaining references to `business_messaging_usage_events` anywhere in this
  document (the withdrawn table); **zero** claims that a table's name alone
  exempts it from RFC-005 ownership (the new table is explicitly described
  as RFC-005-owned, written only via `UsageWalletManager`); **zero**
  Messaging-Profile-only inbound attribution (§4.6 now requires both
  signals); **zero** default-to-user-1 compatibility promise (§4.6.5 removes
  it); **zero** `LIKE`-based number fallback establishing tenancy in the
  contracted managed or BYO-Twilio-secured path (the legacy `LIKE` fallback
  inside `inboundDLR()`'s attribution logic for a **resolved** `$phone_number`
  is unchanged legacy behaviour, not a tenancy-establishing mechanism for the
  new managed/cross-checked path, and is not claimed as fixed for the
  remaining ~58 providers); **zero** claim that `403` (or any status code)
  prevents Telnyx retry beyond what its own documentation states (§4.6.4);
  multiple phone numbers are representable (§4.2); operational state,
  rejection audit, measurement, and accounting are held in four separate
  tables with no overlap (§4.8's responsibility table); BYO relocation
  removes the old surface (§4.7); no real Telnyx call is authorized; no
  retail rate is activated; no Managed Accounts launch field exists; every
  parent-contract allowlist addition is named exactly and justified in §3.
* Secret-shaped-string sweep over every line added/changed in this branch:
  none found.
* Confirmed: zero source code, migration, configuration, dependency, or
  generated asset changed by this branch.

---

**CUSTOMER EXPERIENCE SLICE 3 MESSAGING PROVIDER CONTRACT — CORRECTION ROUND 1 READY FOR HUMAN/CHATGPT REVIEW**
