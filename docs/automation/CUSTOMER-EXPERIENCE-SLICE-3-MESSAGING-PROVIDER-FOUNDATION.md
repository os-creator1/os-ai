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
distinct, narrowly scoped stores.

**Correction Round 2 (2026-09-09).** Round 1's three uniqueness invariants
(one active-or-pending managed identity per Business; a number cannot belong
to two active-or-pending identities; at most one active primary number per
identity) were each described as a "partial unique index," which MySQL does
not support — PostgreSQL's `CREATE UNIQUE INDEX ... WHERE ...` syntax has no
MySQL equivalent, and this repository runs MySQL, not PostgreSQL (§2 item
20). This round replaces every such claim with an executable design already
proven in this exact repository: a nullable `STORED` generated guard column
plus an ordinary `UNIQUE` index on it, exactly matching
`database/migrations/2026_08_16_140001_create_payment_provider_customers_table.php`'s
already-merged, already-tested pattern. All three invariants are now
enforced by MySQL's own unique-index conflict detection at the storage
engine level — never by application validation or row locks alone — with
exact migration syntax, rollback behaviour, and concurrency/replay tests
specified in §4.2/§4.9/§4.12. §4.8's RFC-005 measurement seam is also
re-audited for exact file ownership at every layer (manager, repository,
model, migration, enum, documentation, test), per this round's request.

**Correction Round 3 (2026-09-09), post-merge, human-owner-authorized.**
Rounds 1 and 2 above merged as PR #224. This round resolves five further
findings against that merged state: (1) an honest governance/autonomy-state
reconciliation, recorded at §1.1, rather than a silent state-file edit; (2)
Round 2's sweep missed two of its own targeted invalid definitions —
`business_messaging_operations`'s `operation_key` and `(provider,
provider_message_id)` indexes still used the PostgreSQL-only `UNIQUE(...)
WHERE ... IS NOT NULL` syntax — replaced at §4.2 with ordinary MySQL `UNIQUE`
indexes (no generated column needed here, unlike Round 2's three invariants,
because neither column carries a *conditional* uniqueness rule); (3)
`EloquentCampaignRepository::campaignBuilder()`'s own immediate-send branch,
reached by `CampaignController`/`OutreachController`, was never in the
managed-dispatch delegation scope alongside `quickSend()` — corrected at §3
and §4.11; (4) the delivery-status replay design at §4.6.3 had the exact
defect described below and is corrected to a real status-transition guard;
(5) the relocated advanced-provider surface's authorization at §4.7 checked
`canManage()` (owner-or-admin), looser than the parent contract's own §6
matrix row for this capability (Agency **owner**, not admin) — corrected to
`WorkspaceCandidate::$isOwner`, an already-existing, already-populated
predicate, cited exactly rather than deferred.

Every change below is evidence-driven; §2 records the mechanical
verification each round required. Still a documentation-and-audit pass
only — no Telnyx API call, no provider account/profile/number/registration/webhook/rate/credential
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

**Round 3 base verification (2026-09-09), fresh post-merge branch.** Rounds 1
and 2 above merged as PR #224 (merge commit
`3be7fcdf2160ce8c6c259cdd6ff4da3e4de65640`). This round is a **fresh,
human-owner-authorized post-merge correction**, not a continuation of the
authoring branch above: `agent/customer-experience-slice-3-contract-post-merge-correction`
was created from `origin/main` at `ef0c01346b517fa093d7d95b3384cf25689a0288`
(confirmed exact via `git fetch` + `git rev-parse origin/main`), with PR #224's
head `e40766ae764e5a76d215a31b41d2bb084093fd18` and PR #225's head
`d62ceda9817f459190403792161f2a3ae1ae6f97` both confirmed ancestors via
`git merge-base --is-ancestor`. No Lane A/B/C/E branch was merged or rebased
into this one. This round remains, exactly as Rounds 1/2 were, a
documentation-and-audit-only pass: no Telnyx API call, no provider
account/profile/number/registration/webhook/rate/credential change, no
application code, migration, dependency, configuration, or generated asset
changed.

### 1.1 Governance and autonomy-state reconciliation — corrected Round 3

**The apparent conflict.** `docs/automation/AI-AUTONOMY-STATE.json`, read in
full as part of this round's preflight, currently records a closure state for
an entirely different, already-completed effort — RFC-005 Milestone 6
(`contract_source: docs/automation/RFC-005-M6-CONTRACT.md`,
`completed_pull_request: 164`) — with `implementation_authorized: false`,
`allowed_paths: []`, `active_pull_request: null`, `next_candidate: null`, and
a `forbidden_scope` that includes "No product, test, schema, config, or route
change of any kind" and "Any future work requires separate, explicit human
authorization." Read superficially, PR #224 (this Slice 3 contract's Rounds 1
and 2, merged) and PR #225 (an unrelated Security Remediation Slice 0,
merged) both landed real, substantial documentation/contract and code work
respectively while that closure state stood unchanged and unadvanced.

**The honest reconciliation, per `AGENTS.md`.** `AGENTS.md`'s only obligation
regarding this file is: "Read `docs/automation/AI-AUTONOMY-STATE.json` before
reviewing an automation-managed pull request. Enforce that file's allowed
paths, required tests, and locked slice contract." Both clauses are scoped
explicitly to *reviewing an automation-managed pull request* — one dispatched
through the semi-autonomous Routine described in `CLAUDE.md`
(`docs/automation/CLAUDE-ROUTINE-PROMPT.md` + this same state file's "locked
task"). Neither PR #224 nor PR #225 was such a PR: both were carried out
through `CLAUDE.md`'s own documented **Manual completion path**
(`docs/automation/AI-SUBSCRIPTION-LOOP.md`, "Manual completion path") — a
human operator directly, explicitly instructing an interactive Claude Code
session for one specific, separately-scoped, separately-authorized piece of
work, exactly as this present correction round was itself dispatched (the
task instruction opening this round states plainly: "This is a
human-owner-authorized, documentation-only post-merge correction. It does not
authorize automatic implementation or activate the autonomous loop.").
`CLAUDE.md` permits this path *instead of* dispatching the Routine, without
requiring a Routine handoff, a Codex review, or — on its own text, and on
`AGENTS.md`'s own text — an `AI-AUTONOMY-STATE.json` update. The state file's
`current_slice`/`active_pull_request`/`allowed_paths` fields track the
Routine's own one locked task; they were never the record of manually,
directly human-authorized interactive work, and their remaining parked on
RFC-005's closure while three unrelated manual efforts (Slice 5's PR #223,
Slice 3's PR #224, Security Slice 0's PR #225) proceeded is the **expected**
shape of the documented two-path system, not a silently-tolerated
inconsistency.

**Why no edit is made to `AI-AUTONOMY-STATE.json` in this round.** The task
opening this round poses an explicit conditional: update the state file
minimally and truthfully *if* `AGENTS.md` requires it to record manual
documentation authorization; otherwise, if the schema cannot represent that
without activating automation, stop and report the blocker rather than
inventing semantics. Per the reconciliation above, `AGENTS.md` does **not**
require it — its state-file obligation is scoped to reviewing
automation-managed PRs, and this was not one. Separately, the schema itself
offers no field shaped for "a one-off, human-authorized, non-automation
correction is in progress": every field that could describe "work is
happening" (`implementation_authorized`, `allowed_paths`,
`active_pull_request`, `next_candidate`, `gate_label`) is, by this same
file's own design, read by the Routine as automation authorization/gating
state, not as a neutral audit log. Populating any of them — even narrowly,
even truthfully, even scoped to this round's own documentation-only paths —
would risk being mechanically misread, by the very Routine this task
explicitly forbids reactivating, as a grant to advance automatically. That is
precisely the outcome both this task and `AI-AUTONOMY-STATE.json`'s own
`forbidden_scope` ("No automatic start of any work," "Any future work
requires separate, explicit human authorization") exist to prevent. Rather
than invent a safe-looking semantic the schema does not actually offer, this
round makes **no edit** to `docs/automation/AI-AUTONOMY-STATE.json` at all —
it is not part of this round's changed-path set — and instead records this
reconciliation here, in the correction's own documentation, where a human or
Codex reviewer will read it alongside the rest of this round's evidence. The
file's RFC-005 closure state, its `ai:paused` gate label, and its blanket
`forbidden_scope` remain completely intact and unweakened; nothing in this
round enables automatic implementation, automatic advancement, or an
autonomous loop for Slice 3, RFC-005, or anything else.

## 2. MECHANICAL REPOSITORY AUDIT — SUMMARY AND FILE:LINE EVIDENCE

Items (1)-(18) are carried forward from the initial pass, unchanged, and
remain the evidentiary basis for everything in §3-§4 that they support. Item
(19) is new evidence gathered specifically for this correction round.

**(1) Outbound SMS/MMS dispatch entry points — corrected Round 3, one
citation was wrong and three real entry points were missing entirely.**
Two independent families:

* Legacy/B1-B4 core, all funnelling into `Campaigns` (which `extends
  SendCampaignSMS`, `app/Models/Campaigns.php:52`):
  `EloquentCampaignRepository::quickSend()` (`app/Repositories/Eloquent/EloquentCampaignRepository.php:421,441,446` — switch on `sms_type` to `sendPlainSMS`/`sendVoiceSMS`/`sendMMS`), `Campaigns`'s own internal dispatch switch (`app/Models/Campaigns.php:979,983,987`), and direct callers: `app/Library/Automation/Actions/SendMessageAction.php:139` (via `quickSend()`), `app/Console/Commands/CheckUserPreferences.php:103,129,179,205`, `app/Repositories/Eloquent/EloquentAnnouncementsRepository.php:105`, `app/Notifications/TwoFactorCode.php:82`, `app/Notifications/TopupNotification.php:75` (these last four are platform-initiated notifications, not customer-composed sends — deliberately out of Slice 3's customer-messaging scope, unchanged from the initial pass).

  **Corrected citation.** The previous revision cited `:1826,1830,1834` as
  "a second switch in the same file's bulk/scheduled `campaignBuilder()`
  path." **This was wrong** — those three line numbers fall inside
  `sendApi()` (`:1526-1894`), a completely different method reached only
  from the public API, not from `campaignBuilder()` at all.
  `campaignBuilder()` (`:847-1251`) contains **no inline dispatch switch of
  its own**; its real chain, traced this round end to end, is:
  `campaignBuilder()`'s immediate-send branch (`:1131-1134`, `status =
  QUEUING`, `run_at = now()`) → `Campaigns::execute()`
  (`Campaigns.php:1381-1417`, dispatches the `RunCampaign` job) →
  `RunCampaign::handle()` (`app/Jobs/RunCampaign.php:38-86`) →
  `Campaigns::run()` (`Campaigns.php:1092-1208`, dispatches a batch of
  `LoadCampaign` jobs) → `LoadCampaign::handle()`
  (`app/Jobs/LoadCampaign.php`) → `Campaigns::loadDeliveryJobsByIds()`
  (`Campaigns.php:1799-1879`, builds `SendMessage` jobs) →
  `SendMessage::send()` (`app/Jobs/SendMessage.php:122`, calls
  `$this->campaign->send(...)`) → `Campaigns::send()`
  (`Campaigns.php:807-876`) → **`Campaigns::sendSMS()`**
  (`Campaigns.php:974-1003`, the already-allowlisted switch). Structurally,
  this converges on the exact same delegation point `Campaigns.php:979,983,987`
  already covers — but only after crossing three asynchronous job
  boundaries, never synchronously inside `campaignBuilder()` itself, and
  **no test anywhere in this document's prior revisions actually exercised
  that chain** — T-MSG-9/10 assert delegation for `quickSend()` and for
  `Campaigns`'s switch in isolation, never for a `campaignBuilder()`-created
  campaign's full async path. §4.12 T-MSG-65/66 (new, below) close that gap.
  The identical convergence, via the parallel `SendFileMessage` job, is how
  `sendUsingFile()`'s (`:1894-2053`, file-upload campaigns) sends reach
  `Campaigns::sendSMS()` too.

  **Three entry points found this round that were absent from every prior
  revision, not merely mislabeled — genuine, currently-undelegated gaps,
  disclosed rather than silently left out:**
  * `EloquentCampaignRepository::sendApi()` (`:1526-1894`, the actual owner
    of the mislabeled `:1826,1830,1834` lines) — the public bulk/comma-separated-recipient
    branch of the `sms/send`/`sms/campaign` API routes. Its own inline
    switch calls `sendPlainSMS()`/`sendMMS()`/etc. **directly**, reaching
    neither `quickSend()` nor `Campaigns::sendSMS()`.
  * `app/Console/Commands/SendScheduleAPIMessage.php:60,64,68` — the cron
    that later dispatches `sendApi()`'s scheduled branch; its own inline
    switch calls the same dispatch methods directly, independently of
    `sendApi()`'s own gap.
  * `EloquentCampaignRepository::apiCampaignBuilder()`
    (`:2165-2637`) — a structurally-identical sibling of `campaignBuilder()`
    reached from the public `sms/campaign` API route
    (`API\CampaignController@campaign`, `CampaignHTTPController@campaign`),
    never mentioned anywhere in any prior revision of this document. Its
    immediate branch (`:2560-2563`, `execute()` at `:2605`) converges on
    `Campaigns::sendSMS()` through the identical async chain traced above
    for `campaignBuilder()` — structurally covered by the same delegation
    point, subject to the identical prior gap of being untested.

  **Disposition, this round: fix what was asked, disclose the rest,
  authorize nothing beyond either.** This correction's mandate is
  `campaignBuilder()` specifically (§4.11, §4.12 below); it adds the
  missing test proving that chain's existing structural coverage and
  corrects the wrong citation. `sendApi()` and `SendScheduleAPIMessage.php`
  are genuine, currently-live gaps — a managed Business's bulk/scheduled
  API-driven sends can bypass `ManagedMessageDispatcher` entirely today,
  and will continue to be able to after this correction ships, unless and
  until a **separately authorized** correction adds their own delegation
  seam. `apiCampaignBuilder()` requires no new seam (it already converges
  on the covered switch) but does need the same missing-test treatment
  `campaignBuilder()` gets here. Widening this correction's allowlist to
  fix `sendApi()`/`SendScheduleAPIMessage.php` now would be exactly the
  unauthorized scope-widening this task's own instructions forbid ("Do not
  broadly authorize unrelated edits to the repository") — so this document
  states the gap plainly instead of silently carrying it forward or
  quietly fixing it out of scope. Tracked at §4.11's allowlist note and
  §3's executability table as an explicit, named, not-fixed-here finding.
* Agency Prospecting (entirely separate, never touches `SendCampaignSMS`):
  `app/Jobs/AgencyProspectingInitialSendJob.php:98` → `AgencyProspectingMessageSender` contract → `app/Library/AgencyProspecting/ProviderAgencyProspectingMessageSender.php:38-42` (`match($channel->provider) { TYPE_TWILIO, TYPE_TELNYX }`). Its own docblock states it never touches `EloquentCampaignRepository::campaignBuilder()` or the legacy Campaigns/Reports tables — confirmed independent by design, not a gap.
* Conversation/chat and DLR-triggered sends, already managed:
  `ChatBoxController@sent`/`@reply` (`:313`,`:564`) and
  `DLRController.php`'s STOP/opt-out/auto-reply handling
  (`:765,782,804,846,862,899`) both call `quickSend()` — already covered by
  the existing `quickSend()` delegation, no new path.

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

**(20) NEW THIS ROUND — database version, migration convention, and
generated-column precedent for uniqueness invariants that must survive
concurrency.**

* `composer.json:45` — `laravel/framework: ^12.0` (already cited, item 19).
* CI provisions MySQL, not PostgreSQL, for every workflow that runs a
  database-backed test: `.github/workflows/ai-subscription-gate.yml:46` and
  `.github/workflows/rfc-003-m3-aggregate-regression.yml:38` both declare
  `image: mysql:8.0` as the service container. `docs/automation/CUSTOMER-EXPERIENCE-SLICE-2-AUTH-SHELL.md:263`
  additionally records a real run against `MySQL 8.4`. **MySQL does not
  support PostgreSQL's `CREATE UNIQUE INDEX ... WHERE ...` partial-index
  syntax at all** — no `WHERE` clause exists on a MySQL `CREATE INDEX`/`ALTER
  TABLE ... ADD UNIQUE` statement, in any MySQL version. Every "partial
  unique index" claim in the prior round of this document was therefore not
  executable against this repository's actual database.
* This repository already has a proven, merged, tested alternative for
  exactly this shape of problem — a nullable `STORED` generated column that
  collapses every non-guarded row to `NULL` (which a MySQL `UNIQUE` index
  permits in unlimited quantity, since MySQL — like every SQL-standard
  implementation — treats `NULL` as distinct from every other `NULL` for
  uniqueness purposes) plus an ordinary `UNIQUE` index on that generated
  column:
  `database/migrations/2026_08_16_140001_create_payment_provider_customers_table.php`
  (read in full). It creates `payment_provider_customers` with a
  `business_id`/`workspace_id`/`status` shape, then, in a **separate**
  `Schema::table()` call after `Schema::create()` (the migration's own
  comment, `:33-38`, explains why: "Laravel's fluent generated-column
  builder targets a fresh column add, not create-time definition alongside a
  foreign key in the same statement in every MySQL/Laravel version
  combination"), adds:
  ```php
  $table->unsignedBigInteger('active_business_id')
      ->nullable()
      ->storedAs("CASE WHEN status = 'active' THEN business_id ELSE NULL END")
      ->after('status');
  ```
  and a matching `active_workspace_id` column, then, in a **third**
  `Schema::table()` call, `$table->unique(['provider', 'active_business_id']);`
  and `$table->unique(['provider', 'active_workspace_id']);`. `down()` is a
  plain `Schema::dropIfExists('payment_provider_customers')` — the whole
  table, generated columns and their indexes included, drops together.
* This exact pattern is already covered by a real, merged, passing test:
  `tests/Feature/Usage/ProviderCustomerOwnershipTest.php` (read in full).
  `test_unique_provider_and_active_business_id_rejects_a_second_active_row()`
  (`:90-113`) raw-inserts one `active` row for a Business, then asserts a
  second raw insert for the same Business/`active` status throws
  `Illuminate\Database\QueryException` — **the database itself**, not
  application code, rejects it. `test_detach_then_recreate_allows_a_new_active_row()`
  (`:115-144`) proves the inverse: updating the first row's `status` away
  from `active` (which recomputes the generated column to `NULL` in the same
  `UPDATE`, per MySQL's own generated-column semantics — no separate cleanup
  step) immediately frees the slot for a new `active` row. Both tests use
  plain sequential `DB::table(...)->insert()`/`->update()` calls, not a
  multi-process harness — sufficient because the actual protection is
  InnoDB's own atomic unique-index conflict check, which does not care
  whether two conflicting statements are issued from the same process, two
  processes, or genuinely simultaneously; the repository's own established
  convention already treats sequential-insert-expecting-`QueryException` as
  valid, sufficient proof of a uniqueness invariant holding under
  concurrency (a true multi-process harness exists elsewhere in this
  repository, e.g. `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php::test_two_genuinely_concurrent_processes_confirming_the_same_attempt_produce_exactly_one_ledger_credit_and_transition()`,
  but that pattern is reserved for races the database's own constraints do
  not themselves resolve — not needed here, where a real `UNIQUE` index is
  the entire mechanism).
* **Conclusion: §4.2's three uniqueness invariants are redesigned this round
  around this exact, already-proven mechanism** — a `STORED` generated guard
  column plus an ordinary `UNIQUE` index — never a partial index, never
  application validation or a row lock as the source of truth.

## 3. EXECUTABILITY AUDIT OF THE §22.1 SLICE 3 ALLOWLIST

| Required behaviour | Current production entry point | Implementation path required | Was it allowlisted before this document? | Why it must change | Test proving delegation |
|---|---|---|---|---|---|
| Fail-closed inbound/DLR attribution for **managed** Telnyx traffic, cross-checked on two independent signals | `DLRController::inboundTelnyx()`/`inboundDLR()`, reached by `routes/public.php:26` (and the dead/duplicate `routes/web.php:45,73`) | New method on `DLRController` (fail-closed, signature-verified, Profile+number-cross-checked) + one new route; removal of the two dead/duplicate routes | **No** — `DLRController.php`, `routes/public.php`, `routes/web.php` were absent from the row | A resolver nothing routes to proves nothing about production traffic; two extra routes to the same unsafe handler leave the "fix" ambiguous | §4.12 T-MSG-16/T-MSG-19..25 |
| Removal of the shared fail-open default-to-user-1 write for **every** provider reached by `inboundDLR()`, without claiming signature verification for providers that do not have it | `DLRController::inboundDLR()`'s unconditional `else` branch (`:917-934`) | A narrow edit to the **existing** `inboundDLR()` method: no `Reports`/`ChatBox` write and no STOP/blacklist processing when `$phone_number` cannot be resolved | **No** | This is the exact behaviour named unacceptable in this correction round; it cannot be fixed by adding a new route beside the old one | §4.12 T-MSG-20/T-MSG-21 |
| Secure BYO **Twilio** inbound, using data already on the connection | `DLRController::inboundTwilio()` | A narrow edit to the **existing** `inboundTwilio()` method: `Twilio\Security\RequestValidator` verification using the resolved `SendingServer.auth_token`, mirroring the Agency Prospecting precedent (item 10) | **No** | `auth_token` is already stored and already proven sufficient (item 10) — Option A is mechanically achievable today | §4.12 T-MSG-22 |
| Fail-closed (not fail-open) BYO **Telnyx** inbound, honestly, given no per-connection Ed25519 material exists | `DLRController::inboundTelnyx()` | A narrow edit to the **existing** `inboundTelnyx()` method: Business-facing BYO Telnyx connections (identified via the existing `isManagedConnection()`-adjacent, read-only `CustomerBasedSendingServer` existence check, item 16) are disabled for inbound processing, not silently left fail-open | **No** | No schema exists to verify Telnyx BYO inbound (item 4); Option B is the only honest choice absent that material | §4.12 T-MSG-23 |
| Managed outbound dispatch actually used by real sends | `EloquentCampaignRepository::quickSend()` (`:421,441,446`), `Campaigns`'s own switch (`Campaigns.php:979,983,987`) | Pre-dispatch managed-identity resolution/delegation inserted before each switch | **No** — neither file was in the row | Without this, a managed `BusinessMessagingIdentity` never leaves this document's tests | §4.12 T-MSG-9/T-MSG-10 |
| **Corrected Round 3 — `campaignBuilder()`'s async chain actually reaches the delegated switch, but was never tested and its evidence was mis-cited** | `campaignBuilder()` (`:847-1251`) → `execute()`→`RunCampaign`→`run()`→`LoadCampaign`→`SendMessage`→`Campaigns::send()`→**`Campaigns::sendSMS()`** (item 1) | No new implementation path — the existing `Campaigns.php` switch delegation already covers this chain structurally; a new test proving it | **N/A — already structurally covered**; the gap was test/evidence coverage, not delegation | The prior revision's citation for this chain (`:1826,1830,1834`) named a different method (`sendApi()`); no test exercised the real chain, so the claim was unverified, not merely undocumented | §4.12 T-MSG-65/T-MSG-66 |
| **New this round, disclosed, not fixed here — `sendApi()`, `SendScheduleAPIMessage.php`, and `apiCampaignBuilder()`** | `EloquentCampaignRepository::sendApi()` (`:1526-1894`), `app/Console/Commands/SendScheduleAPIMessage.php:60,64,68`, `EloquentCampaignRepository::apiCampaignBuilder()` (`:2165-2637`) | `sendApi()`/`SendScheduleAPIMessage.php` each need their own delegation seam (not designed here); `apiCampaignBuilder()` needs only the same test treatment as `campaignBuilder()` (not built here) | **No** — none of the three appear in any prior revision of this row or the allowlist | Explicitly out of this round's authorized scope (campaignBuilder() only); disclosed here rather than silently carried forward, per this task's own "do not broadly authorize unrelated edits" instruction | Not built this round — tracked as an open finding, no ID assigned |
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
on `business_id`.

**One active-or-pending managed identity per Business — corrected this
round, MySQL-executable, database-enforced.** A nullable `STORED` generated
guard column, added in a `Schema::table()` call immediately after
`Schema::create()` (mirroring `database/migrations/2026_08_16_140001_create_payment_provider_customers_table.php`,
§2 item 20, exactly):

```php
$table->unsignedBigInteger('active_or_pending_business_id')
    ->nullable()
    ->storedAs("CASE WHEN status IN ('pending','active') THEN business_id ELSE NULL END")
    ->after('status');
```

followed, in a subsequent `Schema::table()` call, by:

```php
$table->unique(['provider', 'active_or_pending_business_id']);
```

A `suspended` or `archived` row's generated column is `NULL`; MySQL's
`UNIQUE` index permits unlimited `NULL`s, so historical/inactive rows never
collide with each other or with the one live row. A `pending` or `active`
row's generated column equals its own `business_id`, so a second `pending`
or `active` row for the same Business — whichever combination of the two
statuses — collides on the same non-`NULL` value and MySQL's own unique-index
check (InnoDB, at the storage-engine level) rejects the `INSERT`/`UPDATE`
with a `QueryException`, before any application code runs. **This is the
sole enforcement mechanism; it is not a backstop behind an application check.**
`BusinessMessagingIdentityResolver::create()` still performs a `DB::transaction()`
+ `lockForUpdate()` existence pre-check (§4.9) — but only to turn what would
otherwise be a raw `QueryException` into a clean, catchable
`MessagingIdentityConflictException` for a well-behaved caller; the
invariant holds even if that pre-check is skipped, raced, or removed,
because the database constraint does not depend on it.

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

**Indexes/constraints — corrected this round, MySQL-executable, database-enforced.**
Two independent `STORED` generated guard columns, following the identical
pattern and migration ordering as `business_messaging_identities` above
(and the same repository precedent, §2 item 20), added together in one
`Schema::table()` call after `Schema::create()`:

```php
$table->string('active_or_pending_phone_number', 32)
    ->nullable()
    ->storedAs("CASE WHEN status IN ('pending','active') THEN phone_number ELSE NULL END")
    ->after('phone_number');

$table->unsignedBigInteger('active_primary_identity_id')
    ->nullable()
    ->storedAs("CASE WHEN is_primary = 1 AND status = 'active' THEN business_messaging_identity_id ELSE NULL END")
    ->after('is_primary');
```

then, in a subsequent `Schema::table()` call:

```php
$table->unique('active_or_pending_phone_number');
$table->unique('active_primary_identity_id');
$table->index('business_messaging_identity_id');
$table->index('status');
```

* **No provider phone number belongs to two Businesses.**
  `UNIQUE(active_or_pending_phone_number)` — a `suspended`/`released` row's
  generated value is `NULL` (unlimited `NULL`s permitted), so a `released`
  number's row is retained (never deleted) without claiming an impossible
  eternal reservation; a `pending`/`active` row's generated value is its own
  `phone_number`, so a second `pending`/`active` row for the same number —
  on any identity, including a different Business's — collides and MySQL
  rejects it. **Deliberately not scoped by `provider`** (unlike the identity
  table's composite index): a real E.164 phone number is unique in reality
  regardless of which provider label a row carries, and scoping by provider
  would let two different "provider" rows falsely claim the same real
  number simultaneously — the opposite of what this invariant exists to
  prevent.
* **At most one active primary number per identity.**
  `UNIQUE(active_primary_identity_id)` — a non-primary or non-`active` row's
  generated value is `NULL`; a row that is both `is_primary = true` and
  `status = 'active'` generates its owning identity's ID, so a second such
  row for the same identity collides and MySQL rejects it.
* Both are the sole enforcement mechanism, exactly as for the identity
  table above — `BusinessMessagingIdentityResolver::attachNumber()`'s
  transactional pre-check exists only to produce a clean
  `MessagingIdentityConflictException` instead of a raw `QueryException`,
  never as the actual source of truth.

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

**Indexes/constraints — corrected Round 3 (2026-09-09).** Round 2's sweep
replaced three PostgreSQL-only partial-unique-index claims on
`business_messaging_identities`/`business_messaging_numbers` with the
`STORED`-generated-column mechanism, but missed these two on this table,
which used the identical invalid `UNIQUE(...) WHERE ... IS NOT NULL`
syntax MySQL does not support. Unlike the three Round-2 invariants, neither
column here needs a computed guard column at all: `operation_key` and
`provider_message_id` carry no *conditional* uniqueness rule (there is no
"only unique while some other column has value X"; every non-null value
must simply be globally unique, full stop), which is exactly what an
ordinary MySQL `UNIQUE` index already provides natively — MySQL treats
each `NULL` as distinct for uniqueness purposes, so a nullable column
under a plain `UNIQUE` index permits unlimited `NULL` rows while still
rejecting any duplicate *non-null* value. The `STORED`-column idiom exists
to encode a condition; there is no condition to encode here, so adding one
would be unnecessary complexity, not a fix.

* Ordinary `UNIQUE(operation_key)` — outbound idempotency. `operation_key`
  is `nullable()` (present on outbound rows only, per its column
  definition above); every inbound row leaves it `NULL`, and MySQL permits
  any number of `NULL` rows under this index without conflict. A second
  outbound row inserted with an already-used, non-null `operation_key`
  raises `Illuminate\Database\QueryException` from this index.
* Ordinary composite `UNIQUE(provider, provider_message_id)` — one shared
  namespace per provider across both directions (item 11's evidence). Rows
  with a `NULL` `provider_message_id` (an outbound row not yet accepted by
  the provider) never conflict with each other or with any other row,
  again by MySQL's own null-handling in a `UNIQUE` index; a second row for
  the same `provider` with an already-used, non-null
  `provider_message_id` raises `QueryException`. **This index is
  necessary but not sufficient to detect a delivery-status replay** — see
  §4.6.3's corrected replay semantics below; the outbound operation row
  that owns a given `provider_message_id` is expected to already exist by
  the time its first delivery-status callback arrives, which is normal,
  not a duplicate.
* Index on `(business_id, occurred_at)` for support/reporting queries.

**Historical-row behaviour, stated exactly.** Neither unique index is ever
violated by an `attempted`-status row awaiting provider acceptance (both
guarded columns are still `NULL` at that point), by an inbound row (which
never carries an `operation_key`), or by any archived/superseded row —
nothing here is ever deleted or nulled out after the fact to "free" the
index; the two columns are simply write-once-then-immutable per row, so no
historical row can ever collide with a later one once both are populated,
and no row transitions in a way that would newly collide with an existing
row either.

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

* **One active-or-pending managed identity per Business** — `UNIQUE(provider, active_or_pending_business_id)` on the `STORED` generated column above, a real MySQL database constraint, not an application check.
* **At most one active primary number per identity** — `UNIQUE(active_primary_identity_id)` on the `STORED` generated column above, same mechanism.
* **No provider phone number belongs to two Businesses** — `UNIQUE(active_or_pending_phone_number)` on the `STORED` generated column above, same mechanism.
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
  preserve on rollback. Dropping a table drops its `STORED` generated
  columns and their indexes together, in one statement — there is no
  separate generated-column or index cleanup step in `down()`.

### Archival, replacement, and reactivation — corrected this round, stated precisely

**Archival is a plain `status` update, and the guard column recomputes
automatically as part of it.** MySQL recomputes a `STORED` generated column
on every `UPDATE` that touches a column its expression reads — here,
`status` (and, for the number table, `is_primary`/`status`). Setting
`business_messaging_identities.status` from `pending`/`active` to
`archived` (or `business_messaging_numbers.status` to `suspended`/`released`)
recomputes the guard column to `NULL` **in that same `UPDATE` statement**,
atomically freeing the unique slot — no separate cleanup migration, batch
job, or application-level "release the slot" step exists or is needed.

**Multiple historical rows are preserved without limit.** Because an
archived/suspended/released row's guard column is always `NULL`, and a
`UNIQUE` index permits unlimited `NULL`s, a Business may accumulate any
number of archived `BusinessMessagingIdentity` rows (and a number may
accumulate any number of released `BusinessMessagingNumber` rows) — nothing
in this schema deletes or limits historical rows, satisfying "preserve
historical inactive identities and numbers without preventing multiple
historical records."

**Replacement.** Once identity A for Business X is archived (guard column
`NULL`), a fresh `INSERT` creating identity B (`status = 'pending'`) for the
same Business X succeeds without any conflict — A's archived row plays no
part in the constraint check. The identical logic applies to
`business_messaging_numbers`: once a number is `released`, a **different**
number (or, if genuinely reissued by Telnyx, the same E.164 value on a new
row) can be attached to a new or different identity without colliding with
the released row.

**Reactivation re-runs every invariant, by construction, not by extra
application logic.** Reactivating identity A (`archived` → `active`, or
`archived` → `pending`) is itself an `UPDATE` that touches `status` — MySQL
recomputes `active_or_pending_business_id` back to A's `business_id` **as
part of committing that `UPDATE`**, and the same `UNIQUE(provider,
active_or_pending_business_id)` index is checked at that instant. If some
other identity is already `pending`/`active` for that same Business, the
reactivating `UPDATE` itself fails with a `QueryException` — the exact same
protection creation gets, for free, with no separate "is this Business
already claimed" method to write or to forget to call. The identical
argument applies to reactivating a `business_messaging_numbers` row (its
generated columns recompute on the same basis).

**Concurrency.** Every case above — first creation, replacement creation,
and reactivation — is protected by the same real MySQL `UNIQUE` index;
InnoDB's own row-insert/row-update conflict detection is what decides which
of two racing statements wins, not application code, a `lockForUpdate()`
read, or an assumption about statement ordering. §4.9 and §4.12 (T-MSG-1,
T-MSG-7, T-MSG-14, T-MSG-43..47) restate and test this exactly.

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
* `WebhookRejectionReason` (**new this round**) — `INVALID_SIGNATURE`, `MALFORMED_PAYLOAD`, `DUPLICATE`, `UNKNOWN_MAPPING`, `CONFLICTING_MAPPING`, `REGRESSIVE_TRANSITION` (**new, Round 3** — a `DELIVERY_STATUS` callback requesting a transition absent from §4.6.3's permitted-next table for the operation's current status; distinct from `DUPLICATE`, which is an exact-status replay, not an invalid one).

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

* **Business A can never send through Business B's number/profile.** Steps 2-4's re-resolution, combined with the real MySQL `UNIQUE` indexes on the generated guard columns (§4.2) — not partial indexes, not application checks — is a structural, database-enforced guarantee. T-MSG-11.
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
3. **Branch by event kind — corrected Round 3.** `$event->kind` decides what
   "already exists" means, because the two kinds have opposite expected
   states for `(provider, provider_message_id)`:
   * `MESSAGE_RECEIVED` — a genuinely new row is expected. Guarded insert
     against `business_messaging_operations`'s `UNIQUE(provider,
     provider_message_id)`; a row already existing for this exact pair
     means this exact inbound message was already processed — a true
     replay. Returns `200` (see §4.6.4) and does nothing further. Continue
     to step 4 only when no such row exists yet.
   * `DELIVERY_STATUS` — an existing row is expected and required: it is
     the outbound operation the provider is confirming delivery of,
     already written when `ManagedMessageDispatcher` recorded the
     provider's acceptance. Its existence is never, by itself, evidence of
     replay — treating it as such would discard every message's first,
     and often only, legitimate delivery-status callback. Skip step 4
     entirely and go directly to the corrected §4.6.3 below.
4. **Dual-signal attribution (MESSAGE_RECEIVED only) — corrected this round,
   the central fix.**
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

### 4.6.3 Delivery-status attribution — corrected Round 3, replay semantics fixed

**The defect this round fixes.** The prior revision treated
`business_messaging_operations`'s `UNIQUE(provider, provider_message_id)`
as a single, undifferentiated "have I seen this pair before" replay guard
shared identically by inbound messages and delivery-status callbacks. That
is correct for `MESSAGE_RECEIVED` (§4.6.2 step 3) but backwards for
`DELIVERY_STATUS`: the outbound operation row **already holds** that exact
`(provider, provider_message_id)` pair from the moment the provider
accepted the send — long before any delivery-status callback arrives. A
naive "row exists → this is a replay, discard it" check would have
discarded the **first**, and frequently only, legitimate delivery-status
callback for every managed message ever sent. Replay detection for a
`DELIVERY_STATUS` event must instead compare the requested transition
against the row's own **current status**, not against the row's mere
existence.

A `DELIVERY_STATUS` event resolves as follows, in order:

1. **Locate, never create.** Resolve the existing outbound operation by
   `(provider, provider_message_id)` against `business_messaging_operations`
   (never against a usage-measurement row, and never against the legacy
   `reports.status` packing). No row found (an unknown `provider_message_id`)
   is an `unknown_mapping` rejection — refused, `200`, no state change.
2. **Business-identity validation.** If the event's payload **also** carries
   `messagingProfileId`/`destinationNumber` evidence (Telnyx's own published
   `message.finalized` example payload includes both), that evidence is
   independently resolved and cross-checked against the stored operation's
   own `business_messaging_identity_id`; a mismatch — including evidence
   that resolves to a **different** Business entirely — is a
   `conflicting_mapping` rejection: refused, `200`, no state change, logged.
   This is the existing cross-Business protection, unchanged in substance,
   stated here as its own explicit step.
3. **Status-transition guard — the corrected mechanism.** Determine the
   requested target `MessagingOperationStatus` from `$event->deliveryStatus`
   and apply **only** the permitted forward transition below. This is what
   "was this exact callback already processed" actually means for a
   `DELIVERY_STATUS` event: a function of the row's own current, already-persisted
   `status`, never of the shared unique index's mere existence.

   | Current `status` | Permitted next `status` | Set by |
   |---|---|---|
   | `attempted` | `accepted`, `rejected` | The outbound send path itself (`ManagedMessageDispatcher`), **never** a `DELIVERY_STATUS` callback — a callback can only ever move a row that is already `accepted` |
   | `accepted` | `delivered`, `failed` | A `DELIVERY_STATUS` callback |
   | `delivered` | *(none — terminal)* | — |
   | `failed` | *(none — terminal)* | — |
   | `rejected` | *(none — terminal)* | — |

   * **Exact replay.** The requested target status equals the row's current
     status: a no-op, `200`, logged as a `duplicate`-reasoned
     `messaging_webhook_rejections` row (the same reason already used for an
     inbound-message replay, §4.6.2 step 3), operation row unchanged. This
     is the **only** circumstance in which this exact callback is recognized
     as already processed — it becomes a no-op only *after* the first valid
     transition into that status has actually been applied, never before.
   * **Regressive or otherwise invalid transition.** The requested target
     status is not in the table's permitted-next set for the row's current
     status (moving backward — e.g. `delivered` → `accepted` — or out of a
     terminal status to anything else, or any other combination absent from
     the table): refused, `200`, logged as a **new**
     `regressive_transition`-reasoned `messaging_webhook_rejections` row
     (one new `WebhookRejectionReason::RegressiveTransition` case, additive
     to the existing enum), operation row unchanged. The operation's status
     can never move backward through a `DELIVERY_STATUS` callback, and an
     out-of-order delivery from the provider can never corrupt an
     already-later state.
   * **Permitted forward transition.** The requested target status is
     exactly the table's permitted next status: applied. `status` updates to
     the new value; `occurred_at` is updated to reflect the callback's own
     timestamp (never the original send's `occurred_at`). This is the first
     and only application of that specific transition — a subsequent,
     identical callback for the same target status now falls into the
     "exact replay" case above.

**What the shared unique index still does, and does not do (Round 3
clarification).** `UNIQUE(provider, provider_message_id)` (§4.2) still does
real, load-bearing work: it is what makes step 1's "locate the existing
outbound operation" a unique, race-free lookup, and it still is exactly
what guards inbound-message replay in §4.6.2 step 3. What it is **never**
used for, after this correction, is deciding delivery-status replay by its
own existence — that determination is made solely by the status-transition
guard above.

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
* **Additional, Slice-3-owned restriction, beyond the generic mechanism —
  corrected Round 3: Workspace-owner-only, mechanically cited, not
  deferred.** The generic sub-account permission system is not
  role-hierarchy-aware; it would let anyone who can edit a sub-account's
  permissions grant this key to any sub-account, including a
  Business-scoped one, which is far looser than the parent contract's own
  §6 table row for this exact capability: `Advanced / BYO provider
  (manage_advanced_provider, new)` reads **Platform owner ✅, Agency owner
  ✅, Agency admin ❌, Agency staff ❌, Business owner ❌, Business staff ❌,
  Client (viewed) ❌** — Agency **owner**, not "Agency-tier," and explicitly
  **not** Agency admin. The prior revision of this document deferred
  identifying the exact existing predicate ("this document does not invent
  a method name... identifying and citing the exact existing method is a
  required, narrow step at implementation time"). That predicate is now
  identified, mechanically, by direct citation:

  * `WorkspaceCandidate::$isOwner` (`app/Library/Navigation/WorkspaceCandidate.php:28`)
    is a public, readonly `bool`, computed once per Workspace in
    `CustomerContextSnapshot::forUser()`
    (`app/Library/Navigation/CustomerContextSnapshot.php:107`, `$isOwner =
    (int) $row->workspace_owner_user_id === $userId`) and passed straight
    into the candidate. It is the exact, already-existing, owner-exclusive
    fact this rule needs — no new column, method, or query.
  * It is **not** the same thing as `WorkspaceCandidate::canManage()`
    (`WorkspaceCandidate.php:43-54`), whose own docblock states it plainly:
    "Owner-or-active-Admin — the same authority rule `WorkspaceManager`
    applies to every Workspace mutation." `canManage()` returns `true` for
    an active Agency-wide Admin staff member even when `isOwner` is
    `false` — exactly the actor §6's row marks ❌.
  * **The merged Security Remediation Slice 0 guard already uses
    `canManage()` here, and this is not a defect in Slice 0 to
    retroactively fault.** `MessagingChannelsController::hasAdvancedProviderAccess()`
    (added by the separately-authorized, already-merged Security
    Remediation Slice 0) checks, in order: the Workspace resolves in the
    caller's `CustomerContext::$workspaces`; `isAgency()`; `isActive`;
    `canManage()`; `$business->status === BusinessStatus::Active`; then
    `EntitlementManager::decide()` for `PlatformFeature::Conversations`.
    Slice 0's job was to close an **immediately** exploitable fail-open gap
    on the legacy, still-live credential surface, fast, without waiting for
    Slice 3's relocation — `canManage()` (owner-or-admin) is strictly
    narrower than what existed before Slice 0 (no server-side check at
    all) and was never claimed to be the final, relocated surface's rule.
    Slice 3 **relocates and tightens** that surface to its final,
    contractually-correct form; it does not claim Slice 0 was wrong, and it
    must not weaken anything Slice 0 already blocks.
  * **The tightening, exactly.** At the relocated surface, the
    Slice-3-owned check changes exactly one clause from Slice 0's guard:
    `$workspaceCandidate->canManage()` becomes
    `$workspaceCandidate->isOwner`. Every other clause (Agency tier, active
    Workspace, active Business, the `Conversations`/relevant-feature
    entitlement decision) is retained unchanged. This can only **narrow**
    Slice 0's already-merged behaviour — every actor Slice 0 already denies
    (Core/Growth tier, denied entitlement, inactive Business, cross-tenant,
    unauthenticated) remains denied; the only actor newly denied by this
    tightening is an Agency-wide active Admin/staff member who is not the
    Workspace owner, who previously passed `canManage()` and must not pass
    `isOwner`. `Gate::allows('manage_advanced_provider')` remains an
    **additional**, independent requirement stacked on top — the owner must
    also hold the granted permission; holding the permission alone, even at
    Agency tier, is never sufficient without `isOwner`, and `isOwner` alone
    is never sufficient without the granted permission. Either check may
    narrow access further; **neither may substitute for, or widen past, the
    other.**
  * **Platform owner (§6's other ✅ cell) — disclosed honestly, not
    invented.** "Platform owner" in §6 is the platform's own internal
    administrator, authorized through this application's entirely separate,
    already-existing platform-admin path — `users.is_admin` (`User.php:186`,
    `isAdmin()`-shaped accessor), enforced independently by
    `EnsureUserIsAdministrator` middleware on the distinct `Admin`
    route/controller group, and by the repeated
    `assertPlatformAdministrator()`-style direct `is_admin` read already
    used by `EntitlementManager`, `UsageWalletManager`, and
    `UsageBillingCheckoutManager`. This path is **never** derived from a
    Workspace's plan tier, a Workspace membership row, or
    `manage_advanced_provider` — a platform administrator with no Workspace
    membership at all still qualifies, and an Agency-tier Workspace owner
    does not become a platform administrator by virtue of their tier.
    Slice 3 builds and touches only the customer-facing, per-Workspace
    relocated route (§4.11's allowlist); it does not build a
    platform-admin-side advanced-provider view. If the platform team later
    wants platform administrators to manage a Business's advanced provider
    settings directly, that surface must be reached through the existing,
    separate `Admin`/`is_admin` boundary — never by loosening this
    customer route's `isOwner` check, and never by inferring platform-owner
    status from `WorkspacePlanTier::Agency` or from `canManage()`. This
    document does not build that admin-side surface and says so plainly,
    consistent with its own established practice of disclosing a gap
    rather than silently inventing scope to close it.
  * **Cross-Workspace and inactive-Workspace access.** Unchanged from Slice
    0's existing shape: `guardAdvancedProviderAccess()`'s
    `abort_unless(..., 404)` already fails closed, with no information
    disclosure, for a Workspace the caller cannot resolve at all, an
    inactive Workspace, or a foreign Business — this correction adds the
    `isOwner` clause to the same existing 404 path; it introduces no new
    response shape.
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

**Corrected this round — every layer named exactly, so ownership is
unambiguous, not merely a method name declared "RFC-005-owned."** RFC-005's
own established layering (evidenced by `UsageWalletManager`'s constructor —
`app/Library/Usage/UsageWalletManager.php:77,82` inject
`BusinessUsageWalletRepository`/`BusinessUsageReservationRepository`, bound
in `app/Providers/AppServiceProvider.php:159,166` as
`\App\Repositories\Contracts\X::class => \App\Repositories\Eloquent\EloquentX::class`
pairs) is Manager-calls-Repository-writes-table, never Manager-writes-table
directly. `recordMeasurement()` follows that exact, already-established
layering — it does not bypass it by writing to the new table itself.

**Exact source, migration, model, enum, manager, and repository paths —
every one named, none left as a glob standing in for "somewhere in Usage":**

| Layer | Exact path | Status |
|---|---|---|
| Migration | `database/migrations/<timestamp>_create_business_usage_measurements_table.php` | new |
| Model | `app/Models/BusinessUsageMeasurement.php` | new |
| Enum case | `App\Enums\Entitlement\PlatformFeature::MessagingTransport`, in `app/Enums/Entitlement/PlatformFeature.php` | existing file, additive case only |
| Repository contract | `app/Repositories/Contracts/BusinessUsageMeasurementRepository.php` | new |
| Repository implementation | `app/Repositories/Eloquent/EloquentBusinessUsageMeasurementRepository.php` | new |
| Container binding | `app/Providers/AppServiceProvider.php` — one new `\App\Repositories\Contracts\BusinessUsageMeasurementRepository::class => \App\Repositories\Eloquent\EloquentBusinessUsageMeasurementRepository::class` line in the existing `$bindings` array | existing file, one array-entry addition only |
| Manager method | `UsageWalletManager::recordMeasurement()`, in `app/Library/Usage/UsageWalletManager.php` | existing file — **one additive public method, and one additive constructor-injected dependency** (`BusinessUsageMeasurementRepository $measurementRepository`), added to the existing constructor's parameter list alongside its current repository dependencies; no existing method or existing constructor parameter is modified or removed |
| Documentation | `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` §27 C-3 (parent contract) — extended, not edited directly in this branch (below) | existing row, narrow extension |
| Tests | `tests/Feature/Usage/` (new test file(s) for the repository/manager pair) | new |

**Exact seam, corrected this round to show the repository call:**

```php
// app/Library/Usage/UsageWalletManager.php — additive method only
public function recordMeasurement(
    Business $business,
    PlatformFeature $featureKey,
    string $quantity,
    string $unit,
    string $idempotencyKey,
    ?string $transportMarker = null,
): BusinessUsageMeasurement
{
    return $this->measurementRepository->recordOnce(
        $business, $featureKey, $quantity, $unit, $idempotencyKey, $transportMarker,
    );
}
```

`EloquentBusinessUsageMeasurementRepository::recordOnce()` is the **only**
code in the entire codebase that writes to `business_usage_measurements` —
`firstOrCreate`-style, guarded by the table's own `UNIQUE(idempotency_key)`.
Messaging's own classes (`ManagedMessageDispatcher`,
`InboundWebhookAttributionResolver`, the relocated BYO send path) call
`UsageWalletManager::recordMeasurement()` only — none of them holds a
reference to the repository, the model, or the table directly. This is what
makes "measurements belong to RFC-005; provider operations and webhook
rejections do not" unambiguous at the code level, not only in prose:
`business_messaging_operations` and `messaging_webhook_rejections` are
written exclusively by Messaging's own classes
(`ManagedMessageDispatcher`/`InboundWebhookAttributionResolver` and
`MessagingWebhookRejectionRecorder` respectively, both in
`app/Library/Messaging/**`), and `business_usage_measurements` is written
exclusively by `EloquentBusinessUsageMeasurementRepository`, in
`app/Repositories/Eloquent/**` — no class exists that can write to a table
outside its own owning layer.

* **Never** calls `setActiveRate()` or `activateMetering()`.
* **Never** inserts into `business_usage_rates`, `business_usage_rate_activations`,
  `business_usage_reservations`, or any ledger-entry table.
* **Never** reads or writes `platform_feature_usage_classifications` from
  Slice 3's own code. A future slice that activates a retail rate for this
  feature is the one that inserts a rate and an activation and decides how
  already-recorded `business_usage_measurements` rows feed any
  reconciliation/backfill billing process — Slice 3 makes no promise about
  that mechanism, only that this table's generic shape (`feature_key`,
  `quantity`, `unit`) does not block one being built later.

  **Correction — Implementation Round 1.** An earlier revision of this
  bullet, and of T-MSG-36 in §4.12, required that
  `platform_feature_usage_classifications` carry **no row at all** for
  `PlatformFeature::MessagingTransport`. That requirement is not reachable,
  and asserting it would have meant either editing merged migration history
  or failing the suite for a reason unrelated to Slice 3's guarantees.

  The already-merged migration
  `2026_08_16_120008_backfill_platform_feature_usage_classifications`
  inserts one classification row for **every** `PlatformFeature` case and
  **throws** `PlatformFeatureUsageClassificationBackfillIncompleteException`
  if any case lacks one. Adding the contracted
  `PlatformFeature::MessagingTransport` case therefore necessarily creates
  that row on any fresh `migrate`, and merged migrations may not be edited.
  One row per feature is that architecture's deliberate invariant, not an
  accident.

  **The corrected invariant, which is the one that actually protects the
  guarantee:** the classification row exists, and is **inactive, unmetered
  and unpriced** — `is_metered = 0` and `active_rate_id = NULL` — with zero
  rows in `business_usage_rates` and zero in
  `business_usage_rate_activations` for the feature. That is byte-identical
  to how every other unpriced feature (`conversations` among them) sits in
  this table, and it is what "no retail charging" means mechanically. The
  absence of a row was only ever a proxy for it, and a worse one: a row
  present but unmetered is directly assertable, whereas an absent row proves
  nothing about whether a rate was activated elsewhere.

**Responsibility separation, stated exactly (corrected this round):**

| Responsibility | Owning table/mechanism |
|---|---|
| Outbound operation/idempotency | `business_messaging_operations.operation_key` |
| Provider acceptance | `business_messaging_operations.status` |
| Inbound message replay | `business_messaging_operations` `UNIQUE(provider, provider_message_id)` (guarded insert — a row already existing for the pair **is** the replay signal) |
| DLR/delivery-status replay | **Corrected Round 3 — not the same signal.** The status-transition guard alone (§4.6.3): the row existing is normal and expected, never itself evidence of replay; only a callback whose requested status exactly equals the row's current status is a replay |
| Security/rejection audit | `messaging_webhook_rejections`, via `MessagingWebhookRejectionRecorder` |
| Usage quantity measurement | `business_usage_measurements`, via `UsageWalletManager::recordMeasurement()` only |
| Wallet accounting | RFC-005's existing `business_usage_reservations`/ledger tables — **untouched by Slice 3** for telecom transport |

No table above serves more than one of these responsibilities.

**Future RFC-005 documentation debt — owned explicitly, not left unowned,
corrected this round.** `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`
will eventually need a new subsection documenting
`recordMeasurement()`/`BusinessUsageMeasurementRepository`/`business_usage_measurements`
as an RFC-005-owned, additive measurement-only primitive, alongside its
existing §11/§13/§14 material. That edit is **not** made in this branch,
because Lane A may currently be touching that RFC document concurrently —
but the debt itself is not left as an unowned "someday" note: the parent
contract's existing §27 C-3 row (`docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`
§27, "Corrections required to older contracts") is explicitly the row that
tracks it, extended narrowly in this correction (§4.11) to name the exact
primitive by name. §27's own table format already carries a "Blocking?"
column (`No`, for this row) — the debt is tracked exactly as every other
pending correction to an older contract in this repository already is, not
in a new, ad hoc, easily-missed note.

**Tests demonstrate measurement while no retail rate is active** by
asserting directly against `business_usage_measurements` row counts/fields,
and — per the Implementation Round 1 correction above — by asserting that
`PlatformFeature::MessagingTransport`'s classification row is inactive and
unmetered (`is_metered = 0`, `active_rate_id = NULL`) with zero rate and
zero activation rows throughout the test run, proving the RFC-005
rate/reservation machinery was never touched, not merely unasserted-on.

## 4.9 CONCURRENCY, IDEMPOTENCY AND TRANSACTION BOUNDARIES

* **Duplicate outbound submission.** `operationKey` checked against
  `business_messaging_operations.operation_key` before calling `send()` — a
  duplicate is a no-op returning the previously recorded result.
* **Duplicate inbound webhook.** `UNIQUE(provider, provider_message_id)`
  guarded insert — the row already existing for the pair is itself the
  replay signal (§4.6.2 step 3).
* **Duplicate/regressive delivery-status callback — corrected Round 3, a
  different mechanism from the above, not the same one.** The row already
  existing for `(provider, provider_message_id)` is the *normal, expected*
  state for a `DELIVERY_STATUS` event (it is the outbound operation being
  confirmed), never itself a replay signal. Replay/invalidity is decided
  solely by §4.6.3's status-transition guard: a callback whose requested
  status equals the row's current status is an exact replay (no-op); one
  requesting a status absent from the current status's permitted-next set
  is regressive/invalid (refused); both share the same underlying table and
  the same `UNIQUE(provider, provider_message_id)` index only in the sense
  that the index is what makes locating the one correct row possible — the
  index's existence is never read as "discard this."
* **Provider-message-ID uniqueness and scope.** Enforced at the database
  level, scoped per `provider` (a composite constraint), so Telnyx's and
  Twilio's ID spaces never collide even though both are UUID-shaped.
* **Identity-creation races — corrected this round: the database is the
  mechanism, not the backstop.** `UNIQUE(provider, active_or_pending_business_id)`
  (§4.2's `STORED` generated column) is what actually decides a race between
  two concurrent creations for the same Business — InnoDB rejects the
  second `INSERT` with a `QueryException` regardless of timing.
  `BusinessMessagingIdentityResolver::create()`'s `DB::transaction()` +
  `lockForUpdate()` pre-check exists only to convert that raw exception into
  a clean `MessagingIdentityConflictException` for a well-behaved caller —
  removing or racing past that pre-check does not weaken the invariant,
  because the database constraint does not depend on it.
* **Number-attachment races — same correction.** `UNIQUE(active_or_pending_phone_number)`
  and `UNIQUE(active_primary_identity_id)` (§4.2's `STORED` generated
  columns) are what decide a race over the same phone number or over two
  rows both claiming `is_primary` for one identity; `attachNumber()`'s
  transactional pre-check is the same courtesy-only wrapper as above, never
  the actual source of truth.
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
corrected this round);

**`business_messaging_operations` — Query-Builder access, no model
(corrected, Security Correction 36).** This allowlist named models for the
identity, number, measurement and rejection tables and none for the
operations table, while §4.5/§4.6 name `ManagedMessageDispatcher` and
`InboundWebhookAttributionResolver` as its only writers. That was an
omission in the prose, not an instruction to add a fifth model.

The authorized access path is stated here so the branch and the contract stop
telling different stories: **`business_messaging_operations` is reached
through the query builder, from inside those two contract-named classes and
`DLRController`'s shared delivery-callback resolution seam, and no Eloquent
model exists for it.** That is deliberate. The table is operational
transport state with no domain behaviour, no relationships a caller needs to
traverse, and exactly two writers; a model would add an attribute surface
that §4.11's own credential-minimization rules would then have to police for
no benefit. A model is NOT to be invented merely to satisfy the shape of a
sentence. `ManagedMessageDispatcher::TABLE` is the single place the table
name is written.

`app/Repositories/Contracts/BusinessUsageMeasurementRepository.php`
(new, corrected Round 2 — RFC-005-owned repository contract);
`app/Repositories/Eloquent/EloquentBusinessUsageMeasurementRepository.php`
(new, corrected Round 2 — the sole writer of `business_usage_measurements`);
`app/Http/Controllers/Customer/Business/MessagingChannelsController.php`
(existing); `app/Enums/Messaging/**` (new);
`app/Enums/Entitlement/PlatformFeature.php` (existing — **corrected this
round, new addition**: one additive enum case, `MessagingTransport`, only —
no existing case renamed or removed); `app/Library/Usage/UsageWalletManager.php`
(existing — **corrected this round, new addition**: one additive public
method, `recordMeasurement()`, plus one additive constructor-injected
dependency (`BusinessUsageMeasurementRepository`) added to the existing
constructor's parameter list — no existing method, existing parameter, or
existing behaviour modified or removed);
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
pre-dispatch delegation insertion in `quickSend()` only — **confirmed
unchanged this round**: `campaignBuilder()` needs no delegation insertion of
its own, since it never dispatches directly — it converges on
`Campaigns.php`'s already-covered switch through the async chain traced in
§2 item 1; **not** widened to include `sendApi()` or `apiCampaignBuilder()`,
which are explicitly out of this round's scope per §4.11's prohibited-paths
note below);
`app/Models/Campaigns.php` (existing — the pre-dispatch delegation insertion
in its own dispatch switch only — **confirmed this round** to already be the
one convergence point for `quickSend()`, `Campaigns`'s own bulk/scheduled
path, and `campaignBuilder()`'s async chain alike; no additional insertion
point needed for the last of these); `tests/TestCase.php` (existing —
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
* **New this round, disclosed:** `EloquentCampaignRepository::sendApi()`,
  `app/Console/Commands/SendScheduleAPIMessage.php`, and
  `EloquentCampaignRepository::apiCampaignBuilder()` — real, currently
  undelegated outbound-dispatch entry points found during this round's
  re-audit (§2 item 1). None is touched, tested, or delegated by this
  correction; closing them is explicitly **not** authorized here and is
  left for a separately-authorized future correction, named so the gap is
  never silently carried forward as if fixed.

## 4.12 TEST MATRIX

**Round 1 renumbered T-MSG-1..30 in full to T-MSG-1..42; Round 2 corrects
T-MSG-1/2/7/14/39's wording to the database-enforced generated-column
mechanism (§2 item 20, §4.2) and adds T-MSG-43..48** for the
archival/replacement/reactivation/migration-rollback-replay/repository-layering
requirements this round adds — no existing ID 1-42 is renumbered or removed,
only corrected in place or (39) rewritten to a more precise assertion of the
same shape. Inherited from the parent contract (§24), unchanged: **T-PROV-1**,
**T-PROV-2**, **T-BYO-1**, **T-BYO-2**, **T-SCOPE-1** — all still owned by
Slice 3, none reused as a new ID below.

| ID | Assertion | Location |
|---|---|---|
| T-MSG-1 | **One active-or-pending identity per Business, database-enforced.** A raw `DB::table('business_messaging_identities')->insert()` of a second `pending`-or-`active` row for a Business that already has one raises `Illuminate\Database\QueryException` from MySQL's own `UNIQUE(provider, active_or_pending_business_id)` index (mirroring `ProviderCustomerOwnershipTest::test_unique_provider_and_active_business_id_rejects_a_second_active_row`, §2 item 20) — asserted both for a second `active` row against an existing `active` one and for a `pending` row against an existing `active` one (and vice versa), proving the two statuses conflict correctly, not only identical-status pairs | `tests/Feature/Messaging/` |
| T-MSG-2 | `UNIQUE(messaging_profile_id)` and `UNIQUE(provider, active_or_pending_business_id)` violations each raise a caught, reported `MessagingIdentityConflictException` at the `BusinessMessagingIdentityResolver::create()` layer, and an uncaught `QueryException` when the same raw insert bypasses the resolver — never a silent overwrite either way | `tests/Feature/Messaging/` |
| T-MSG-3 | A `pending`/`suspended`/`archived` identity resolves to `null` — never a partially-usable object | `tests/Feature/Messaging/` |
| T-MSG-4 | Neither `BusinessMessagingIdentity` nor `BusinessMessagingNumber` has any credential-shaped attribute, cast, or hidden field | `tests/Feature/Messaging/` |
| T-MSG-5 | Migration `up()`/`down()` round-trips cleanly on all four new tables, in correct dependency order, with no data-loss warning | `tests/Feature/Messaging/` |
| T-MSG-6 | **One Business, multiple phone numbers** — a Business's identity may own two or more active `BusinessMessagingNumber` rows simultaneously | `tests/Feature/Messaging/` |
| T-MSG-7 | **A number cannot belong to two Businesses, database-enforced.** A raw insert of a second `pending`-or-`active` `business_messaging_numbers` row for the same `phone_number` under a different identity raises `QueryException` from `UNIQUE(active_or_pending_phone_number)` — never an application-only check | `tests/Feature/Messaging/` |
| T-MSG-8 | **Exact E.164 normalization** — a set of equivalent input formats for the same number all normalize to one canonical E.164 value before any uniqueness check runs | `tests/Feature/Messaging/` |
| T-MSG-9 | `EloquentCampaignRepository::quickSend()` for a Business with an active managed identity delegates to `ManagedMessageDispatcher`/`FakeMessagingAdapter`, never reaching `SendCampaignSMS`'s Telnyx `case` block | `tests/Feature/Business/` |
| T-MSG-10 | `Campaigns`'s own dispatch switch shows the same delegation for its bulk/scheduled path | `tests/Feature/Business/` |
| T-MSG-65 | **Corrected Round 3 — `campaignBuilder()`'s real async chain, not merely its entry, actually reaches the delegated switch.** For a Business with an active managed identity, a real `POST` to `CampaignController@storeCampaign` (through `campaignBuilder()`'s immediate/`QUEUING` branch), run through the real `RunCampaign`→`LoadCampaign`→`SendMessage` job chain (queue run synchronously in-test, not mocked away), delegates to `ManagedMessageDispatcher`/`FakeMessagingAdapter` and never reaches `SendCampaignSMS`'s Telnyx `case` block — closing the gap left by the prior revision's mis-cited, untested claim | `tests/Feature/Business/` |
| T-MSG-66 | **Named integration test — both campaign-builder entry routes, full seam coverage.** For a managed Business, both `CampaignController@storeCampaign` and `OutreachController@storeSmsCampaign` (the two real, currently-reachable `campaignBuilder()` entry routes traced this round) are each exercised through the full async chain and proven, for each: (a) `ManagedMessageDispatcher`/`FakeMessagingAdapter` is called, never `SendCampaignSMS`'s provider `case` block; (b) the resolved `BusinessMessagingIdentity`/number is this Business's own, never another's; (c) exactly one `business_usage_measurements` row is written via `UsageWalletManager::recordMeasurement()`; (d) the request is refused with zero provider calls when `config('messaging.managed_messaging_enabled')` is `false` (kill-switch) or the Business's `Conversations`/messaging entitlement is denied; (e) exactly one `business_messaging_operations` row records the operation. This is the one test proving the two entry points found this round cannot bypass any of the five seams, together, not merely that each seam exists somewhere | `tests/Feature/Business/` |
| T-MSG-11 | Business A's resolved identity/number can never be used to construct an `OutboundMessageRequest` for Business B's send | `tests/Feature/Messaging/` |
| T-MSG-12 | A forged identity or number ID submitted as request input is never read by the outbound resolution path | `tests/Feature/Messaging/` |
| T-MSG-13 | Each non-`active` identity status produces zero `FakeMessagingAdapter` calls | `tests/Feature/Messaging/` |
| T-MSG-14 | **At most one active primary number per identity, database-enforced.** Zero active-primary numbers fails closed with zero provider calls (no "first number" fallback); a raw `UPDATE ... SET is_primary = 1` against a second `active` row for the same identity while another is already `active`+primary raises `QueryException` from `UNIQUE(active_primary_identity_id)` | `tests/Feature/Messaging/` |
| T-MSG-15 | `FakeMessagingAdapter::send()` records the call and returns a scripted `OutboundMessageResult` deterministically | `tests/Feature/Messaging/` |
| T-MSG-16 | **Profile and destination number agree** — a real `POST` to `route('inbound.telnyx_managed')` with both signals resolving to the same active identity is processed and attributed correctly | `tests/Feature/Messaging/` |
| T-MSG-17 | **Known Profile + unknown number fails closed** — no processing, one `unknown_mapping` rejection row, `200` | `tests/Feature/Messaging/` |
| T-MSG-18 | **Unknown Profile + known number fails closed** — same shape, reversed | `tests/Feature/Messaging/` |
| T-MSG-19 | **Profile A + number-belonging-to-B fails closed** — one `conflicting_mapping` rejection row recording both candidate identity IDs, no attribution to either | `tests/Feature/Messaging/` |
| T-MSG-20 | **Inactive/suspended/released number mapping** with an otherwise-valid Profile fails closed | `tests/Feature/Messaging/` |
| T-MSG-21 | Invalid/missing signature on `inbound.telnyx_managed` returns `403`, writes one `invalid_signature` rejection row, updates no conversation data | `tests/Feature/Messaging/` |
| T-MSG-22 | **Delivery-status operation resolution and evidence cross-check** — a `DELIVERY_STATUS` event resolves via the stored `provider_message_id`; when it also carries Profile/number evidence that conflicts with the stored operation's identity, the status update is refused | `tests/Feature/Messaging/` |
| T-MSG-23 | Duplicate delivery of the same inbound `MESSAGE_RECEIVED` webhook (`provider_message_id` already recorded on an inbound row) produces exactly one attributed effect, `200`, no second write | `tests/Feature/Messaging/` |
| T-MSG-49 | **Corrected Round 3 — the first legitimate DLR is never discarded as a replay.** An outbound operation with `status = accepted` and a real `provider_message_id` receives its first `DELIVERY_STATUS` callback targeting `delivered`; the row's `status` actually updates to `delivered` — proving the row's pre-existing `provider_message_id` is not itself misread as a replay signal (the exact defect this round fixes) | `tests/Feature/Messaging/` |
| T-MSG-50 | **Exact DLR replay is a no-op only after the first transition.** The identical `delivered` callback from T-MSG-49 delivered a second time leaves `status` at `delivered` (unchanged), writes one `duplicate`-reasoned `messaging_webhook_rejections` row, and returns `200` | `tests/Feature/Messaging/` |
| T-MSG-51 | **`accepted` → `delivered` is permitted and applied exactly once**, asserted independently of T-MSG-49/50's specific fixture, over the full `attempted` → `accepted` → `delivered` lifecycle from a real `send()` through two real webhook `POST`s | `tests/Feature/Messaging/` |
| T-MSG-52 | **A regressive callback can never move `delivered` backward.** A `delivered` operation receives a further `DELIVERY_STATUS` callback targeting `accepted` (or any other non-terminal status); `status` remains `delivered`, one `regressive_transition`-reasoned `messaging_webhook_rejections` row is written, `200` is returned, and no other operation field changes | `tests/Feature/Messaging/` |
| T-MSG-53 | **Unknown and cross-Business `provider_message_id`s on a `DELIVERY_STATUS` event fail closed**, asserted separately: (a) a `provider_message_id` with no matching operation row → `unknown_mapping`, `200`, no row created; (b) a matching operation row whose payload evidence resolves to a *different* Business than the row's own `business_messaging_identity_id` → `conflicting_mapping`, `200`, operation `status` unchanged | `tests/Feature/Messaging/` |
| T-MSG-54 | **Inbound-message deduplication is independent of DLR processing.** Forcing an inbound `MESSAGE_RECEIVED` replay (same `provider_message_id` as an already-processed inbound row) and, in the same test run, forcing a DLR exact replay and a DLR regressive callback for an unrelated outbound operation, prove each of the three is detected and handled by its own mechanism (§4.6.2 step 3's guarded insert vs §4.6.3's status-transition guard) without any of the three suppressing or altering the others' outcome | `tests/Feature/Messaging/` |
| T-MSG-24 | **Legacy Telnyx route cannot default to user 1** — a `POST` to `routes/public.php`'s legacy `inbound/telnyx/{gateway?}` with an unattributable `from` number writes no `Reports`/`ChatBox` row and no STOP/blacklist entry | `tests/Feature/Messaging/` |
| T-MSG-25 | **Legacy Twilio route cannot default to user 1** — same assertion against `inboundTwilio()` | `tests/Feature/Messaging/` |
| T-MSG-26 | **BYO Twilio inbound is securely verified** — a `POST` to the legacy Twilio route with a valid `X-Twilio-Signature` (matching a fixture `auth_token`) is processed; an invalid one is rejected before `inboundDLR()` runs | `tests/Feature/Messaging/` |
| T-MSG-27 | **Missing BYO verification material / BYO Telnyx inbound explicitly disabled** — a `POST` to the legacy Telnyx route for a `CustomerBasedSendingServer`-linked (BYO) connection makes no `Reports`/`ChatBox` write regardless of payload content, and records the disablement | `tests/Feature/Messaging/` |
| T-MSG-28 | **Duplicate/dead Telnyx route cannot bypass canonical handling** — `routes/web.php`'s two former duplicate lines no longer resolve to any route after this correction | `tests/Feature/Messaging/` |
| T-MSG-29 | **`manage_advanced_provider` flag authorization, corrected Round 3** — the relocated advanced-settings route is unreachable without both the granted permission and the Workspace-**owner** check (`isOwner`, not tier alone and not `canManage()`) | `tests/Feature/Security/` |
| T-MSG-55 | **Workspace owner, entitled and permitted, retains full access** to the relocated advanced-settings route — the positive case proving the tightening narrows, not breaks | `tests/Feature/Security/` |
| T-MSG-56 | **An active Agency Admin — not the owner — is denied `404`** even holding `manage_advanced_provider` and even under `canManage() === true`, on every method the relocated surface exposes, by direct URL | `tests/Feature/Security/` |
| T-MSG-57 | **Ordinary Agency staff (owner-or-admin neither) is denied `404`** on the relocated surface, with and without `manage_advanced_provider` granted | `tests/Feature/Security/` |
| T-MSG-58 | **Selected-scope staff assigned to the Business is denied `404`** on the relocated surface — Business-scope assignment is not Workspace ownership | `tests/Feature/Security/` |
| T-MSG-59 | **Core/Growth-tier Workspace owner is denied `404`** on the relocated surface — owner status alone, without Agency/Platform tier, is insufficient (unchanged from Slice 0, re-asserted at the relocated route) | `tests/Feature/Security/` |
| T-MSG-60 | **A platform operator reaches equivalent Business data, if at all, only through the existing, separate `is_admin`/`EnsureUserIsAdministrator` admin boundary — never through the relocated customer route.** An authenticated platform administrator with no Workspace membership at all is denied `404` on the customer-facing relocated route by direct URL, proving platform-owner access (§6) is never inferred from `WorkspacePlanTier::Agency` or from this route at all | `tests/Feature/Security/` |
| T-MSG-61 | **Inactive Workspace fails closed `404`** for an actor who would otherwise be the owner — an owner of a Workspace with `is_active = false` is denied exactly like Slice 0's existing inactive-Business/inactive-membership cases | `tests/Feature/Security/` |
| T-MSG-62 | **Cross-tenant direct URL fails closed `404`** — a Workspace owner of Workspace A supplying Workspace B's UID (a Business they do not own or manage at all) is denied on every one of the relocated surface's methods, GET and mutation alike | `tests/Feature/Security/` |
| T-MSG-30 | **Old BYO routes removed/redirected** — the pre-relocation `businesses/{businessUid}/channels` routes no longer resolve after relocation ships | `tests/Feature/Business/` |
| T-MSG-31 | `TelnyxMessagingAdapter` throws `MessagingProviderNotConfiguredException` when `managed_messaging_enabled` is `false`, even with otherwise-complete `services.telnyx` config, before any HTTP call | `tests/Feature/Messaging/` |
| T-MSG-32 | Real credentials present but the enable switch off produces **zero** HTTP calls across a representative set of adapter operations | `tests/Feature/Messaging/` |
| T-MSG-33 | `Http::preventStrayRequests()` is active for the base test class; a deliberately unmatched HTTP call anywhere in a sample test fails immediately | `tests/Feature/Messaging/` |
| T-MSG-34 | A managed send writes exactly one `business_messaging_operations` row and exactly one `business_usage_measurements` row (via `recordMeasurement()`), with no row in either table serving the other's purpose | `tests/Feature/Messaging/` |
| T-MSG-35 | A BYO send (through the relocated advanced-settings path) writes exactly one `business_usage_measurements` row with `transport_marker = byo` and creates no wallet reservation/debit | `tests/Feature/Business/` |
| T-MSG-36 | **Corrected, Implementation Round 1 (§4.8).** Throughout the full Slice 3 suite run, `PlatformFeature::MessagingTransport`'s `platform_feature_usage_classifications` row is inactive and unpriced — `is_metered = 0` and `active_rate_id = NULL`, with zero `business_usage_rates` and zero `business_usage_rate_activations` rows for the feature — and no telecom feature's `is_metered` becomes `true`. The prior wording required **no row at all**, which the merged `2026_08_16_120008` backfill migration makes unreachable: it inserts one row per `PlatformFeature` case and throws if any case lacks one. Merged migrations are not edited; the corrected invariant is strictly the stronger assertion, since an absent row proves nothing about whether a rate was activated elsewhere | `tests/Feature/Usage/` |
| T-MSG-37 | **Rejection records obey retention/minimization** — a `messaging_webhook_rejections` row never contains a raw message body or any credential; a repeated identical rejection increments `occurrence_count` rather than inserting a new row; the purge command removes rows past the configured retention window and leaves newer ones | `tests/Feature/Messaging/` |
| T-MSG-38 | `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` and `tests/Feature/AgencyProspecting/AgencyProspectingRuntimeTest.php` pass unmodified after every change in this correction | `tests/Feature/Usage/`, `tests/Feature/AgencyProspecting/` (regression) |
| T-MSG-39 | **Two simultaneous first-identity creations for the same Business cannot both succeed.** Two sequential raw inserts of a `pending` `business_messaging_identities` row for the same Business (mirroring `ProviderCustomerOwnershipTest::test_unique_provider_and_active_business_id_rejects_a_second_active_row` exactly) prove MySQL's own unique-index conflict detection rejects the second, regardless of statement ordering — the database is the mechanism, not application code (§2 item 20, §4.2) | `tests/Feature/Messaging/` |
| T-MSG-40 | **Outbound/inbound/DLR idempotency remain independent** — forcing a duplicate on one of the three (same `operation_key`, same inbound `provider_message_id`, same delivery-status `provider_message_id`) does not suppress or interfere with processing of the other two for different keys in the same test run | `tests/Feature/Messaging/` |
| T-MSG-41 | No exception message or `OutboundMessageResult` contains a credential-shaped substring | `tests/Feature/Messaging/` |
| T-MSG-42 | A provider timeout/ambiguous response never produces `MessageDispatchStatus::ACCEPTED` | `tests/Feature/Messaging/` |
| T-MSG-43 | **Two Businesses cannot concurrently claim the same number.** Two sequential raw inserts of a `pending`/`active` `business_messaging_numbers` row for the same `phone_number` under two different identities; the second raises `QueryException` from `UNIQUE(active_or_pending_phone_number)` | `tests/Feature/Messaging/` |
| T-MSG-44 | **Two primary numbers cannot concurrently exist for one identity.** Two sequential raw updates setting `is_primary = 1`/`status = 'active'` on two different `business_messaging_numbers` rows under the same identity; the second raises `QueryException` from `UNIQUE(active_primary_identity_id)` | `tests/Feature/Messaging/` |
| T-MSG-45 | **Archived history does not block a legitimate replacement.** Archiving identity A for Business X (an `UPDATE` recomputing its guard column to `NULL`), then inserting fresh `pending` identity B for Business X, succeeds without conflict; A's row is neither deleted nor modified beyond its `status`/`archived_at` | `tests/Feature/Messaging/` |
| T-MSG-46 | **Reactivation re-runs every invariant.** With identity A archived and identity B `active` for the same Business, an `UPDATE` reactivating A (`status: archived → active`) raises `QueryException` from the same `UNIQUE(provider, active_or_pending_business_id)` index that would have blocked a fresh creation; with B archived first, the identical reactivating `UPDATE` on A succeeds | `tests/Feature/Messaging/` |
| T-MSG-47 | **Forward, rollback, and replay work on the repository's actual database.** Running `php artisan migrate` for both new migrations, confirming the constraint-violation behaviour above holds, running `php artisan migrate:rollback` and confirming both tables no longer exist, then running `php artisan migrate` again (replay) and confirming the identical constraint-violation behaviour holds unchanged — executed against this repository's real configured MySQL connection, not a driver-agnostic in-memory substitute | `tests/Feature/Messaging/` |
| T-MSG-63 | **Corrected Round 3 — `operation_key` and `(provider, provider_message_id)` are ordinary, NULL-tolerant unique indexes, proven against the repository's actual MySQL version.** Two outbound rows both left with `operation_key = NULL` (mirroring an inbound row's shape) coexist without conflict; a raw insert duplicating an already-used, non-null `operation_key` raises `Illuminate\Database\QueryException`. Both assertions executed against this repository's real configured MySQL connection, not an in-memory substitute (mirrors T-MSG-47's method) | `tests/Feature/Messaging/` |
| T-MSG-64 | **Corrected Round 3 — same proof for `(provider, provider_message_id)`.** Multiple rows sharing `provider = 'telnyx'` with `provider_message_id = NULL` (outbound rows not yet accepted) coexist without conflict; a raw insert duplicating an already-used, non-null `(provider, provider_message_id)` pair raises `QueryException`; a duplicate pair under a *different* `provider` value does not conflict, proving the composite (not single-column) scope | `tests/Feature/Messaging/` |
| T-MSG-48 | **The RFC-005 measurement seam writes through its own repository, at the right layer.** `UsageWalletManager::recordMeasurement()` calls `BusinessUsageMeasurementRepository::recordOnce()` (asserted via a spy/fake repository binding, mirroring how `UsageWalletManager`'s existing repository dependencies are already tested); no code path in `app/Library/Messaging/**` holds a reference to `BusinessUsageMeasurementRepository`, `EloquentBusinessUsageMeasurementRepository`, `BusinessUsageMeasurement`, or the `business_usage_measurements` table directly | `tests/Feature/Usage/` |

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
   delegation points; `business_usage_measurements`, its
   `BusinessUsageMeasurementRepository`/`EloquentBusinessUsageMeasurementRepository`
   pair, and the additive `UsageWalletManager::recordMeasurement()` method
   that delegates to it.
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
    alongside the full T-MSG-1..66 matrix plus the five inherited IDs.

Adjustable if implementation-time evidence proves a safer sequence
necessary — not itself authorization to implement (§1).

## 5. CONTRACT INTEGRITY SELF-CHECK

* **Already-existing behaviour** (traced, not re-implemented): items 1-20 of
  §2, including this round's new item 20 evidence (MySQL/Laravel version,
  the `payment_provider_customers` generated-column precedent and its test).
* **Slice-3-will-implement:** every item in §4.1's inclusions; §4.2's four
  tables (now with database-enforced, not partial-index, uniqueness); §4.3's
  contracts/DTOs/adapters; the ten (widened, corrected) production
  delegation/edit points in §3/§4.11, including the two new RFC-005
  repository files.
* **Deferred to Slice 4/6/9/Managed-Accounts-migration:** every item in
  §4.1's exclusions, including the BYO-Telnyx upgrade path (Slice 9) and
  number lifecycle workflow (Slice 4), both stated explicitly this round.
* **Assumptions:** none stated as fact without evidence; item 19's findings
  (`manage_advanced_provider` non-existence, `preventStrayRequests`
  availability) and item 20's findings (MySQL/generated-column precedent) are
  as mechanically verified as items 1-18.
* **Mechanically-proven facts:** §2's 20 items; §3's executability table.
* **Already-locked human decisions, not re-litigated:** Candidate B (§28.3),
  no Managed-Account column (§21.2), no retail rate activation
  (§28.1/§28.1a), $5 funding floor and BYO billing semantics (§11.5).
* **No isolation control is described as "implemented"** anywhere — §4.5-§4.8
  describe what Slice 3 **will build**.
* **No uniqueness invariant is described as enforced by a mechanism this
  repository's database cannot execute.** Round 2 corrected the three
  identity/number invariants to a real, physical `STORED` generated column
  plus an ordinary `UNIQUE` index; **Round 3 corrected the two invariants
  Round 2's own sweep missed** — `business_messaging_operations`'s
  `operation_key` and `(provider, provider_message_id)` indexes, which
  needed no generated column at all (neither carries a *conditional*
  uniqueness rule), only an ordinary nullable-column `UNIQUE` index (§4.2).
  As of this round, every `UNIQUE` constraint anywhere in §4.2 is either a
  `STORED`-generated-column index or an ordinary index on a plain nullable
  column — never a `WHERE`-qualified "partial" index.
* **Every cited path/symbol** was verified against the merged tree at
  `6c820c801da08ecfd6165d1d3a52ae6336606f0c`, including this round's new
  greps/reads (`manage_advanced_provider`, `preventStrayRequests`,
  `laravel/framework` version, `PlatformFeature`'s existing case list,
  `config/customer-permissions.php`, `AuthServiceProvider.php`'s Gate loop,
  `SubAccountController.php`'s permission storage, both `mysql:8.0` CI
  service-container declarations, the `payment_provider_customers` migration
  read in full, `ProviderCustomerOwnershipTest.php` read in full,
  `UsageWalletManager`'s constructor and `AppServiceProvider.php`'s
  repository-binding array).
* **Round 3's additional citations**, verified against `origin/main` at
  `ef0c01346b517fa093d7d95b3384cf25689a0288` (which contains
  `6c820c801d...` in its history via PR #219/#224): `WorkspaceCandidate.php`
  (`$isOwner` property and `canManage()` body, `app/Library/Navigation/WorkspaceCandidate.php:28,43-54`),
  `CustomerContextSnapshot.php` (`$isOwner` computation,
  `app/Library/Navigation/CustomerContextSnapshot.php:107`),
  `MessagingChannelsController.php`'s merged Security Remediation Slice 0
  guard (`guardAdvancedProviderAccess()`/`hasAdvancedProviderAccess()`,
  `:361-423`), `User.php:186` (`is_admin` accessor),
  `EnsureUserIsAdministrator.php:34`, `EntitlementManager.php:1377-1379`'s
  `assertPlatformAdministrator()`, `WorkspaceMembershipRole.php`/`WorkspaceBusinessAccessScope.php`'s
  exact enum cases, `EloquentCampaignRepository.php`'s `campaignBuilder()`
  (`:847-1251`) and `apiCampaignBuilder()` (`:2165-2637`) read in full,
  `Campaigns.php`'s `execute()`/`run()` (`:1381-1417`, `:1092-1208`),
  `app/Jobs/RunCampaign.php`, `app/Jobs/LoadCampaign.php`,
  `app/Jobs/SendMessage.php:122`, `EloquentCampaignRepository.php`'s
  `sendApi()` (`:1526-1894`), and `app/Console/Commands/SendScheduleAPIMessage.php:60,64,68`.
* **Every internal `§` reference** resolves to a section in this document
  (§1-§4.13, including new §1.1) or, when prefixed "parent contract," to
  that document's current numbering (§6, §11, §21, §22, §22.1, §22.2, §24,
  §27, §28), re-confirmed current.
* **Test-to-slice map has no duplicates or unowned tests:** T-MSG-1..66 are
  unique IDs; T-MSG-1/2/7/14/39 are corrected in place from Round 1 (same ID,
  no renumbering), T-MSG-43..48 are new from Round 2, T-MSG-49..66 are new
  this round (Round 3); the five inherited IDs (T-PROV-1, T-PROV-2, T-BYO-1,
  T-BYO-2, T-SCOPE-1) are unchanged and were not renumbered; no ID above is
  reused across two rows.
* **No live credential value or secret-shaped example** appears anywhere.

## 6. VALIDATION

* Only two paths changed in this branch across all three correction rounds:
  this document and the parent contract's §22.1 (and its narrow §27 C-3
  extension). No source code, migration, configuration, dependency, or
  generated asset changed. **Round 3 confirms this remains true**: its five
  findings are a governance reconciliation recorded in prose (§1.1, no edit
  to `docs/automation/AI-AUTONOMY-STATE.json`), two corrected index
  definitions (§4.2), a corrected/added evidence trail and new tests for an
  already-covered dispatch chain plus disclosure of three separately-scoped
  gaps (§2 item 1, §3, §4.11, §4.12), a corrected replay-semantics design
  (§4.6.2/§4.6.3/§4.9), and a corrected authorization predicate citation
  (§4.7) — every one a documentation/design/test-specification change to
  this not-yet-implemented contract, never a change to real application
  code, migration, configuration, dependency, or generated asset.
* `git diff --check`: clean — verified below.
* Every cited path in §2-§4 exists in the merged tree, or is explicitly
  marked `(new)`.
* Every internal `§` reference resolves per §5.
* The §4.12 test-to-slice map carries no duplicate or unowned test ID.
* **Round 2 stale-phrase sweep, run in full, confirms:**
  * **Zero** remaining occurrences of the phrase "partial unique" anywhere in
    this document, except inside §2 item 20's and this sweep's own explicit
    statements that MySQL does not support it — no schema section, proof
    bullet, or test description relies on it any longer.
  * **Zero** uniqueness invariant in §4.2 described as application-only —
    every one of the three now cites a real MySQL `UNIQUE` index on a
    `STORED` generated column as its enforcement mechanism, with the
    application-level transactional check explicitly labelled a courtesy
    convenience, never the source of truth (§4.2, §4.9).
  * **Zero** "first-number fallback" language — §4.2's resolution rule and
    §4.5 step 3 both state the fail-closed rule with no fallback, unchanged
    from Round 1 and re-confirmed this round.
  * **Zero** one-identity/one-number claim left unenforced by the database —
    every such claim in §4.2, §4.5's proofs, and §4.9 now names its exact
    `UNIQUE` index.
  * **Zero** remaining references to the withdrawn `business_messaging_usage_events`
    anywhere in this document, outside its own explicit "withdrawn"/history
    callouts (§4.2, §6).
  * **Zero** suggestion that `business_messaging_operations` or
    `messaging_webhook_rejections` are financial ledger entries — §4.2
    explicitly lists what each excludes (wallet balance, retail amount,
    debit/credit, rate, reservation amount, payer, invoice state, spending-cap
    state), and §4.8's responsibility table draws the line to
    `business_usage_measurements` (RFC-005-owned) as the only
    measurement-adjacent store, itself explicitly not a reservation/ledger
    table either.
  * (Carried forward from Round 1, re-confirmed unchanged this round:) zero
    claims that a table's name alone exempts it from RFC-005 ownership; zero
    Messaging-Profile-only inbound attribution; zero default-to-user-1
    compatibility promise; zero `LIKE`-based number fallback establishing
    tenancy in the contracted managed or BYO-Twilio-secured path; zero claim
    that `403` (or any status code) prevents Telnyx retry beyond what its own
    documentation states; no real Telnyx call is authorized; no retail rate
* **Round 3 stale-phrase sweep, run in full across all five corrections,
  confirms:**
  * **Zero** remaining `UNIQUE(...) WHERE ... IS NOT NULL`, "partial unique
    index," "filtered index," or "conditional unique index" anywhere in this
    document or the parent contract, outside explicit historical/prohibition
    callouts (§2 item 1's Round 2 recap, §4.2's own corrected-in-place
    bullets, §5's checklist) — re-swept over the whole of both documents,
    not only the tables named in the task that prompted this round.
  * **Zero** remaining claim that `business_messaging_operations`'s shared
    `(provider, provider_message_id)` index, or the row existing under it,
    is itself proof of delivery-status replay — every surviving reference
    (§4.6.3, §4.8's responsibility table, §4.9) states the corrected
    status-transition-guard mechanism instead.
  * **Zero** remaining claim that `canManage()` (or "Agency-tier"/"Agency or
    Platform-tier" alone) is the relocated advanced-provider surface's
    owner-equivalent check — every surviving reference (§4.7, §4.12's
    T-MSG-29/55-62) names `WorkspaceCandidate::$isOwner` exactly.
  * **Zero** remaining claim that `EloquentCampaignRepository.php`'s only
    outbound-dispatch surface needing delegation is `quickSend()` — §2 item
    1 and §3 now also name `campaignBuilder()`'s (structurally covered, now
    tested) chain and disclose `sendApi()`/`SendScheduleAPIMessage.php`/`apiCampaignBuilder()`
    as separately-scoped, undelegated findings.
  * **Zero** implication that this round's governance reconciliation (§1.1)
    edited, or needed to edit, `docs/automation/AI-AUTONOMY-STATE.json` —
    the file is confirmed untouched by this branch's diff (§6, below).
  * No real Telnyx call is authorized; no retail rate
    is activated; no Managed Accounts launch field exists.
* Secret-shaped-string sweep over every line added/changed in this branch:
  none found.
* Confirmed: zero source code, migration, configuration, dependency, or
  generated asset changed by this branch.

---

**CUSTOMER EXPERIENCE SLICE 3 MESSAGING PROVIDER CONTRACT — CORRECTION ROUND 3 (POST-MERGE) READY FOR HUMAN/CHATGPT REVIEW**
