# CUSTOMER EXPERIENCE SLICE 3 — MANAGED MESSAGING PROVIDER IMPLEMENTATION

## 1. Status

Every requirement Slice 3 owns is implemented and green, and every finding
from both adversarial audits is closed. §6 is the complete executable
coverage ledger; §7 records each finding and how it was resolved.

| Field | Value |
|---|---|
| Branch | `agent/customer-experience-slice-3-messaging-provider-implementation` |
| Original base | `origin/main` at `35219ef` |
| Merged since | `origin/main` `e5499df`, Lane E `28810ae`, `origin/main` `b8bab0a`, `origin/main` `7f729ca` |
| Contract | `docs/automation/CUSTOMER-EXPERIENCE-SLICE-3-MESSAGING-PROVIDER-FOUNDATION.md` |
| Governance route | Human-authorized manual lane (AGENTS.md route 3) |
| Isolated database | `ultimatesms_testing_lane_a_msg`, validated through `Tests\Support\TestDatabaseSafety` |
| Migration-cycle database | a third disposable sibling from `TestDatabaseSafety::derivedName()`, dropped in `finally` |
| Baseline comparison database | `ultimatesms_testing_lane_a_base` |
| MySQL | 8.4.3 |

No real Telnyx call, account, number, brand, campaign or rate was created at
any point. Since T-MSG-33's guard landed, a test that forgets to fake now
fails loudly rather than reaching the network.

## 2. What is implemented

**Schema (§4.2).** Five additive migrations plus Lane E's
`2026_09_12_100006`, verified forward, rollback and replay on real MySQL by
an automated test. Uniqueness is enforced by MySQL itself — STORED generated
guard columns under UNIQUE indexes for the identity and number tables;
ordinary NULL-tolerant unique indexes elsewhere. Client idempotency is scoped
by Business (§7 P6); provider-message attribution stays global.

**Provider boundary (§4.3).** Nine enums; a three-method adapter interface
with no Slice-4-shaped stub; readonly DTOs carrying no raw provider body;
exceptions that name a config key and never a value.
`TransportProviderIdentifier` normalizes what is persisted, whose domain is
the whole legacy fleet rather than the one-case managed-adapter enum.

**Adapters and kill switch (§4.4).** `TelnyxMessagingAdapter` enforces both
activation gates in its constructor; `ManagedMessageDispatcher` enforces the
platform kill switch itself, so it holds for any adapter. A timeout or an
uncorrelatable 2xx is Rejected, never Accepted.

**Outbound isolation (§4.5).** The resolver returns null rather than
guessing, and fails closed on zero or several active primary numbers.
`dispatch()` exposes no identity or number parameter at all. Managed dispatch
no longer requires a legacy `SendingServer`. Operation keys are durable and
caller-derived; a caller that cannot name one is refused rather than handed a
random UUID.

**Inbound and DLR (§4.6).** Dual-signal attribution; replay decided by the
status-transition guard; the inbound operation and its measurement written in
one transaction with duplicate-key loss treated as replay; real segment
counts from `SMSCounter`; an early delivery callback asked for redelivery
within a bounded budget rather than lost.

**Relocated surface (§4.7).** Views and route URIs moved to
`settings/advanced`; the old path 404s; the guard tightened from
`canManage()` to `isOwner` with `manage_advanced_provider` stacked
independently.

**Measurement (§4.8).** Managed and BYO transport are both measured through
`UsageWalletManager::recordMeasurement()`, at zero rate, with no reservation,
no debit and no cap consumption.

## 3. Test results

All on `ultimatesms_testing_lane_a_msg` after `migrate:fresh` (256
migrations, 0 pending).

| Suite | Result |
|---|---|
| `tests/Feature/Messaging` + `MessagingTransportMeasurementLayeringTest` | 138 passed (737 assertions) |
| `tests/Feature/Messaging` + `ConversationsPlainSmsMeteringTest` | 151 passed (829 assertions) |
| `tests/Feature/Outreach` | 17 passed (77 assertions) |
| `tests/Feature/Security` | 188 passed (1693 assertions) |
| `tests/Feature/Business/MessagingChannelsTest.php` | 48 passed (100 assertions) |
| `tests/Feature/Business/ManagedCampaignDelegationTest.php` | 13 passed (99 assertions) |
| `tests/Unit/Entitlement/EntitlementEnumsTest.php` | 6 passed (18 assertions) |

The full-repository regression and its pristine-main comparison are recorded
in §8.

## 4. Repeated idempotency and concurrency runs

Requirements whose value depends on ordering are exercised repeatedly rather
than once, because a guarantee that holds on one pass may simply have been
lucky:

* six consecutive duplicate inbound deliveries, with the one-operation and
  one-measurement invariant re-asserted after **every** delivery, not only at
  the end;
* three attempts at the same logical campaign send, asserting one provider
  call, one operation, one measurement and one Reports row;
* the three idempotency mechanisms (outbound key, inbound provider-message
  id, DLR status transition) exercised in ONE run, each duplicated in turn
  with the other two re-asserted intact after each;
* the early-DLR budget driven to exhaustion and past it.

## 5. `chat_boxes.uid` — three writers, not two

Lane E proved `campaignBuilder()`'s AI-prospecting hook inserted `chat_boxes`
rows without `uid`, a NOT NULL `char(36)` with no default; a non-strict MySQL
connection coerced it to `''`. There were **three** such writers, not the two
an earlier revision of this document claimed:

1. `EloquentCampaignRepository` — the raw `insertGetId` in the AI-prospecting
   hook (Lane E's finding);
2. `EloquentCampaignRepository` — `ChatBox::firstOrNew()` in the two-way
   quick-send path;
3. `DLRController` — the inbound `ChatBox` writer, rewritten from
   `updateOrCreate` to `firstOrNew`/`save` so a replayed inbound message
   keeps the conversation's original identifier instead of being re-issued
   one.

All three mint `(string) Str::uuid()`, the convention this repository uses
wherever a uid is minted at the write site. The column was not made nullable,
given a default, handed an empty string, or otherwise loosened.

## 6. Coverage ledger — T-MSG-1..66 and the five inherited IDs

Derived mechanically from the test sources, not written by hand. Every ID
maps to a named executable test; none is marked outstanding, manual,
structural, indirect or deferred.

| ID | Test class | Test method | Result |
|---|---|---|---|
| T-MSG-1 | `MessagingSchemaInvariantsTest` | `test_all_four_tables_exist_with_their_generated_guard_columns` (+1 more) | pass |
| T-MSG-2 | `MessagingSchemaInvariantsTest` | `test_a_duplicate_messaging_profile_id_conflicts_at_both_layers` | pass |
| T-MSG-3 | `OutboundIsolationTest` | `test_equivalent_input_formats_normalize_to_one_canonical_value` (+1 more) | pass |
| T-MSG-4 | `MessagingSchemaInvariantsTest` | `test_a_second_active_identity_for_one_business_is_rejected_by_mysql` | pass |
| T-MSG-5 | `MessagingSchemaInvariantsTest` | `test_all_four_tables_exist_with_their_generated_guard_columns` | pass |
| T-MSG-6 | `MessagingSchemaInvariantsTest` | `test_one_business_may_hold_several_active_numbers` | pass |
| T-MSG-7 | `MessagingSchemaInvariantsTest` | `test_one_business_may_hold_several_active_numbers` | pass |
| T-MSG-8 | `OutboundIsolationTest` | `test_equivalent_input_formats_normalize_to_one_canonical_value` | pass |
| T-MSG-9 | `ManagedCampaignDelegationTest` | `test_quick_send_for_a_managed_business_never_reaches_the_legacy_provider_switch` | pass |
| T-MSG-10 | `ManagedCampaignDelegationTest` | `test_the_campaigns_dispatch_switch_delegates_for_a_managed_business` | pass |
| T-MSG-11 | `OutboundIsolationTest` | `test_a_send_always_uses_the_sending_businesss_own_identity_and_number` | pass |
| T-MSG-12 | `OutboundIsolationTest` | `test_a_send_always_uses_the_sending_businesss_own_identity_and_number` (+1 more) | pass |
| T-MSG-13 | `OutboundIsolationTest` | `test_each_non_active_identity_status_resolves_to_null_and_calls_no_provider` | pass |
| T-MSG-14 | `MessagingSchemaInvariantsTest` | `test_a_second_active_primary_number_is_rejected_by_mysql` | pass |
| T-MSG-15 | `OutboundIsolationTest` | `test_a_managed_send_writes_one_operation_row_and_one_measurement_row` | pass |
| T-MSG-16 | `InboundAttributionTest` | `test_matching_profile_and_number_are_attributed` | pass |
| T-MSG-17 | `InboundAttributionTest` | `test_known_profile_with_unknown_number_fails_closed` | pass |
| T-MSG-18 | `InboundAttributionTest` | `test_unknown_profile_with_known_number_fails_closed` | pass |
| T-MSG-19 | `InboundAttributionTest` | `test_profile_of_business_a_with_number_of_business_b_is_a_conflict` | pass |
| T-MSG-20 | `InboundAttributionTest` | `test_every_non_active_number_state_fails_closed` | pass |
| T-MSG-21 | `InboundAttributionTest` | `test_an_invalid_signature_is_refused_with_403_and_changes_nothing` | pass |
| T-MSG-22 | `InboundAttributionTest` | `test_delivery_evidence_resolving_to_another_business_is_refused` | pass |
| T-MSG-23 | `InboundAttributionTest` | `test_a_replayed_inbound_message_produces_exactly_one_effect` | pass |
| T-MSG-24 | `LegacyInboundFailClosedTest` | `test_the_legacy_telnyx_route_cannot_attribute_an_unknown_number_to_user_one` (+1 more) | pass |
| T-MSG-25 | `LegacyInboundFailClosedTest` | `test_the_legacy_twilio_route_cannot_attribute_an_unknown_number_to_user_one` | pass |
| T-MSG-26 | `LegacyInboundFailClosedTest` | `test_a_valid_twilio_signature_passes_the_gate_and_an_invalid_one_never_reaches_it` | pass |
| T-MSG-27 | `LegacyInboundFailClosedTest` | `test_byo_telnyx_inbound_writes_nothing_regardless_of_payload_and_records_the_disablement` | pass |
| T-MSG-28 | `LegacyInboundFailClosedTest` | `test_the_removed_duplicate_telnyx_routes_no_longer_resolve` | pass |
| T-MSG-29 | `RelocatedAdvancedProviderAuthorizationTest` | `test_the_workspace_owner_entitled_and_permitted_retains_full_access` (+1 more) | pass |
| T-MSG-30 | `MessagingChannelsTest` | `test_every_former_business_scoped_channel_route_no_longer_resolves` | pass |
| T-MSG-31 | `InboundAttributionTest` | `test_inbound_traffic_is_ignored_while_managed_messaging_is_disabled` (+1 more) | pass |
| T-MSG-32 | `TelnyxAdapterAndKillSwitchTest` | `test_the_kill_switch_alone_prevents_construction_even_with_complete_credentials` | pass |
| T-MSG-33 | `StrayRequestGuardTest` | `test_an_unmatched_http_call_fails_immediately` | pass |
| T-MSG-34 | `OutboundIsolationTest` | `test_a_managed_send_writes_one_operation_row_and_one_measurement_row` | pass |
| T-MSG-35 | `MessagingTransportMeasurementLayeringTest` | `test_a_byo_send_writes_exactly_one_measurement_marked_byo` | pass |
| T-MSG-36 | `OutboundIsolationTest` | `test_a_repeated_operation_key_never_sends_twice` (+1 more) | pass |
| T-MSG-37 | `InboundAttributionTest` | `test_repeated_identical_rejections_increment_rather_than_duplicate` | pass |
| T-MSG-38 | `MessagingTransportMeasurementLayeringTest` | `test_the_two_named_regression_suites_are_byte_identical_to_main` | pass |
| T-MSG-39 | `MessagingSchemaInvariantsTest` | `test_a_second_active_identity_for_one_business_is_rejected_by_mysql` | pass |
| T-MSG-40 | `InboundAttributionTest` | `test_outbound_inbound_and_dlr_idempotency_never_interfere` | pass |
| T-MSG-41 | `OutboundIsolationTest` | `test_no_result_or_exception_carries_a_credential_shaped_value` | pass |
| T-MSG-42 | `OutboundIsolationTest` | `test_a_provider_rejection_is_recorded_as_rejected_not_accepted` | pass |
| T-MSG-43 | `MessagingSchemaInvariantsTest` | `test_one_business_may_hold_several_active_numbers` | pass |
| T-MSG-44 | `MessagingSchemaInvariantsTest` | `test_a_second_active_primary_number_is_rejected_by_mysql` | pass |
| T-MSG-45 | `MessagingSchemaInvariantsTest` | `test_archiving_frees_the_slot_and_a_replacement_succeeds` | pass |
| T-MSG-46 | `MessagingSchemaInvariantsTest` | `test_archiving_frees_the_slot_and_a_replacement_succeeds` | pass |
| T-MSG-47 | `MessagingSchemaInvariantsTest` | `test_the_slice_three_schema_survives_forward_rollback_and_replay` | pass |
| T-MSG-48 | `MessagingTransportMeasurementLayeringTest` | `test_the_messaging_transport_classification_is_inactive_unmetered_and_unpriced` (+1 more) | pass |
| T-MSG-49 | `InboundAttributionTest` | `test_the_first_legitimate_delivery_callback_is_applied_not_discarded` | pass |
| T-MSG-50 | `InboundAttributionTest` | `test_an_exact_delivery_replay_is_a_no_op_only_after_the_first_transition` | pass |
| T-MSG-51 | `InboundAttributionTest` | `test_the_full_lifecycle_applies_each_transition_exactly_once` | pass |
| T-MSG-52 | `InboundAttributionTest` | `test_a_regressive_delivery_callback_cannot_move_a_terminal_row_backward` | pass |
| T-MSG-53 | `InboundAttributionTest` | `test_an_unknown_provider_message_id_is_retried_and_then_finally_refused` | pass |
| T-MSG-54 | `InboundAttributionTest` | `test_inbound_dedup_dlr_replay_and_regression_do_not_interfere` | pass |
| T-MSG-55 | `RelocatedAdvancedProviderAuthorizationTest` | `test_the_workspace_owner_entitled_and_permitted_retains_full_access` | pass |
| T-MSG-56 | `RelocatedAdvancedProviderAuthorizationTest` | `test_an_active_agency_admin_who_is_not_the_owner_is_denied_on_every_method` | pass |
| T-MSG-57 | `RelocatedAdvancedProviderAuthorizationTest` | `test_ordinary_agency_staff_is_denied_with_and_without_the_permission` | pass |
| T-MSG-58 | `RelocatedAdvancedProviderAuthorizationTest` | `test_ordinary_agency_staff_is_denied_with_and_without_the_permission` | pass |
| T-MSG-59 | `RelocatedAdvancedProviderAuthorizationTest` | `test_a_core_or_growth_tier_owner_is_denied_even_holding_the_permission` | pass |
| T-MSG-60 | `RelocatedAdvancedProviderAuthorizationTest` | `test_a_platform_administrator_with_no_workspace_membership_is_denied_on_the_customer_route` | pass |
| T-MSG-61 | `RelocatedAdvancedProviderAuthorizationTest` | `test_the_owner_of_an_inactive_workspace_is_denied` | pass |
| T-MSG-62 | `RelocatedAdvancedProviderAuthorizationTest` | `test_an_owner_of_another_workspace_is_denied_on_every_method` | pass |
| T-MSG-63 | `MessagingSchemaInvariantsTest` | `test_operation_key_is_unique_but_null_tolerant` | pass |
| T-MSG-64 | `MessagingSchemaInvariantsTest` | `test_operation_key_is_unique_but_null_tolerant` | pass |
| T-MSG-65 | `ManagedCampaignDelegationTest` | `test_the_outreach_campaign_route_reaches_the_delegated_switch_through_the_real_job_chain` | pass |
| T-MSG-66 | `ManagedCampaignDelegationTest` | `test_the_outreach_campaign_route_reaches_the_delegated_switch_through_the_real_job_chain` (+1 more) | pass |
| T-PROV-1 | `MessagingProviderAuthorizationTest` | `test_no_customer_role_response_carries_a_provider_credential_name_or_value` | pass |
| T-PROV-2 | `MessagingProviderAuthorizationTest` | `test_no_customer_role_response_carries_a_provider_credential_name_or_value` (+2 more) | pass |
| T-BYO-1 | `MessagingTransportMeasurementLayeringTest` | `test_a_byo_send_writes_exactly_one_measurement_marked_byo` (+1 more) | pass |
| T-BYO-2 | `MessagingTransportMeasurementLayeringTest` | `test_a_byo_send_writes_exactly_one_measurement_marked_byo` | pass |
| T-SCOPE-1 | `MessagingProviderAuthorizationTest` | `test_slice_three_exposes_no_voice_capability_anywhere` | pass |

**Implementation paths exercised.** T-MSG-1..8, 39, 43..47, 63, 64 exercise
`database/migrations/2026_09_12_1000*` and the real MySQL constraints;
T-MSG-9..15, 34, 41, 42, 65, 66 exercise
`app/Library/Messaging/ManagedDispatchDelegate.php`,
`ManagedMessageDispatcher.php`, `EloquentCampaignRepository::quickSend()` and
`Campaigns::sendSMS()`; T-MSG-16..23, 37, 40, 49..54 exercise
`InboundWebhookAttributionResolver.php` and
`MessagingWebhookRejectionRecorder.php` through the real managed route;
T-MSG-24..28 exercise `DLRController.php`'s four narrow edits through the
real legacy routes; T-MSG-29..30, 55..62 exercise
`MessagingChannelsController.php` and `routes/customer.php`; T-MSG-31..33
exercise `TelnyxMessagingAdapter.php`, `AppServiceProvider.php` and
`tests/TestCase.php`; T-MSG-35..36, 48 and T-BYO-1/2 exercise
`UsageWalletManager::recordMeasurement()` and
`EloquentBusinessUsageMeasurementRepository.php`; T-PROV-1/2 and T-SCOPE-1
exercise the customer-facing surfaces and Slice 3's own enums and views.

## 7. Findings — every one closed

### Contract defects

**(a) A rejection row named the wrong provider.** Twilio rejections were
recorded as `telnyx` because the managed-adapter enum has one case. The enum
stays as it is; `TransportProviderIdentifier` normalizes the persisted
identifier, whose domain is the whole legacy fleet. The model's enum cast is
dropped and all three `DLRController` sites derive the provider from fact.

**(b) T-MSG-36's "no classification row at all" was unreachable.** The merged
backfill migration inserts one row per `PlatformFeature` case and throws if
any lacks one. §4.8 and T-MSG-36 are corrected **in the contract** to the
invariant that actually protects the guarantee: the row exists and is
inactive, unmetered and unpriced, with zero rate and zero activation rows —
strictly stronger than an absent row, which proves nothing about whether a
rate was activated elsewhere.

**(c) Missing legacy AI messaging schema.** Supplied by Lane E, merged here
rather than shipped alone, because its migration and this lane's UUID writer
correction are two halves of one change.

**(d) Managed dispatch required a legacy gateway.** The four legacy-gateway
guards are skipped for a managed Business. RFC-005 accounting is preserved by
the existing code: `qualifyConversationsMeterReservation()` already declares
`?SendingServer` and already treats null as non-qualifying.

**(e) RESOLVED — no model path for `business_messaging_operations`.** The
contract's §4.11 named models for four tables and none for this one, while
naming its two writers elsewhere. That was an omission in the prose, not an
instruction to add a fifth model, and it is corrected in §4.11 rather than by
inventing one: the authorized path is Query-Builder access from inside
`ManagedMessageDispatcher`, `InboundWebhookAttributionResolver` and
`DLRController`'s shared delivery-callback resolution seam, with
`ManagedMessageDispatcher::TABLE` as the single place the table name is
written. The table is operational transport state with no domain behaviour
and exactly two writers; a model would add an attribute surface the same
section's credential-minimization rules would then have to police, for no
benefit.

**(f) Hardcoded canonical-database runners.** Resolved upstream by main's own
conversion of eight files onto `TestDatabaseSafety`.

**(g) `campaignBuilder()` trusted request input for tenancy.** Closed — see
Lane B 1 below.

**(h) The kill switch only worked for one adapter.** Now enforced by
`ManagedMessageDispatcher` itself, so a disabled platform writes no operation
row and no measurement row.

**(i) The delegation returned a shape the caller could not use.**
`track_message()` reads the result with both property and array access;
`recordLegacyReport()` returns a real `Reports` model.

**(j) Provider call inside an open transaction.** Under the sync queue driver
the campaign chain runs inside `Batch::add()`'s bookkeeping transaction. With
a real queue worker `SendMessage` runs in its own process and §4.9's property
holds. The idempotent operation key is the protection either way.

**(k) `EntitlementEnumsTest` pinned sixteen cases.** Closed under explicit
authorization: the expected list gains `messaging_transport` in its
contracted position and the count becomes 17. It still pins the complete
ordered list exactly.

### Lane B — security audit

**1. Forged `business_id` / `user_id` tenant escape.** `CampaignController`
forwarded `$request->except('_token', ...)` into the repository, which reads
those keys as tenancy authority — they choose the sending server, sender id,
debited balance, blacklist scope, contact groups, campaign owner and managed
transport. Closed at both layers: all twenty forwarding sites in that
controller strip both keys, and
`EloquentCampaignRepository::assertSuppliedTenancyIsAuthorized()` fails closed
if a direct caller supplies them anyway. The rule is modelled on the
legitimate Outreach caller — the ACTING user must be authorized for the
supplied Business via `WorkspaceManager`, and a supplied `user_id` may only
be that Business's own owner.

**2. Partial phone-number tenant guessing.** `PhoneNumbers::where('number',
'like', "%$from%")` on an unauthenticated webhook attributed a message to
whichever assigned number merely contained the submitted string, with
`->first()` choosing the victim by insertion order. Removed. Attribution is
one exact match on an assigned number or nothing; several matches fail closed
too.

**3. Voice sent as managed SMS.** The delegation ran above the `sms_type`
switch, so a managed Business's voice campaign was sent as a text and
measured as messaging transport. `SUPPORTED_MESSAGE_TYPES` is enforced inside
the delegate as well as by passing `sms_type` from both call sites.

**4. No production container binding.** `MessagingProviderAdapter` had no
concrete default at all — every test bound the fake and masked it, and
production would have thrown `BindingResolutionException`. Bound in
`AppServiceProvider` as a non-shared `bind()`, so the adapter's fail-closed
constructor runs at the moment of use rather than at container-build time.

**5. Rejection destination fields — REPORTED, NOT CHANGED.** The audit read
these as recording the sender. They do not. Both handlers assign
`$to = <external sender>` and `$from = <our receiving number>` — inverted
against intuition, which is how the misreading happened. `$from` was already
correct, and changing it would have introduced the reported bug. Both sites
now pass it as a NAMED argument with the inversion documented, and two tests
settle it by execution.

**6. WhatsApp verify-token logging.** `$request->all()` was logged before the
`hub_verify_token` check, writing the shared secret to a log that outlives
the request. Only minimized non-secret metadata is logged now, with the token
key removed from even the key list.

### Lane D — billing and idempotency audit

**P1 — unstable outbound idempotency.** The delegate invented
`managed:<businessId>:<random uuid>` when a caller gave no key, and the
campaign path gave none — so every `SendMessage` retry produced another
provider call, operation, measurement and Reports row. Campaign sends now
derive `managed:campaign:<id>:<recipient digits>`; quick sends use their
client token or a deterministic content-derived key; a caller that can name
no durable identity is refused.

**P2 — stranded Conversations reservation.** Transport classification now
runs before the reservation block, so the invalid reservation is never
created rather than compensated for after the early return.

**P3 — BYO measurement.** Implemented at the authoritative successful
dispatch boundary, with ownership resolved from the persisted
`customer_based_sending_servers` assignment rather than from request input,
and a key derived from the `Reports` row's own uid.

**P4 — atomic inbound replay.** Insert and measurement are one transaction;
duplicate-key loss is an idempotent replay, not a 500.

**P5 — inbound segment quantity.** `SMSCounter`, not a hardcoded `1`.

**P6 — cross-Business idempotency isolation.** `UNIQUE(business_id,
operation_key)` and `UNIQUE(business_id, feature_key, idempotency_key)`;
`(provider, provider_message_id)` stays global so webhook attribution cannot
become ambiguous. Every lookup uses the identical scope and re-verifies
ownership after retrieval.

**P7 — delivery status and billing consistency.** Acceptance records `Sent`,
deliberately not containing the substring `Delivered` that every legacy debit
is gated on. A nullable `report_id` foreign key is the durable correlation,
and a delivery callback updates the operation row and the Report in one
transaction.

**P8 — webhook DoS boundary.** The rejection fingerprint derives only from
the reason and provider, capping the table at one row per pair while
`occurrence_count` carries the volume; the managed route is throttled at
600/minute, a bound sized to stay above a real provider's delivery and retry
rate.

**P9 — third blank-uid writer.** See §5.

**P10 — early DLR race.** An unattributable delivery callback is asked for
redelivery within a bounded budget rather than answered 200 and lost.

**Small corrections.** `PlatformFeature::MessagingTransport`'s docblock now
states the corrected reality; `BusinessMessagingIdentityResolver` no longer
converts every `QueryException` into a conflict; `occurrence_count`
increments in the database; the stale `ManagedCampaignDelegationTest` comment
is gone; T-MSG-20 covers every non-active number AND identity state;
T-MSG-30 drives the real old routes.

## 8. Full regression and pristine-main comparison

**5403 passed, 9 failed, 28156 assertions.**

Run in two halves rather than one process. A single run exhausted PHP's
512 MB limit at test 5330, inside the Workspace suite, after this slice added
roughly ninety tests — cumulative memory growth in one long-lived process,
not a test failure, and `-d memory_limit=-1` does not reach the child process
`artisan test` spawns. Splitting the run is a runner-resource decision; no
assertion was changed, skipped or conditioned to obtain it.

| Half | Result |
|---|---|
| `tests/Unit` + Feature A–Messaging | 2096 passed, 4 failed (13655 assertions) |
| Feature Opportunity–Workspace | 3307 passed, 5 failed (14501 assertions) |

**All nine failures are pre-existing.** Verified head-to-head on a pristine
checkout of the SAME `origin/main` (`7f729ca`), same machine, same
environment, on the separate validated sibling database
`ultimatesms_testing_lane_a_base`: **9 failed, 180 passed** — the same nine
test names, exactly.

| Test | This branch | Pristine `7f729ca` |
|---|---|---|
| `BrandingAdminFooterRenderTest::admin_footer_renders_the_company_name_exactly_once` | fail | fail |
| `BusinessKnowledgeProfileControllerTest::missing_stale_and_present_fields_are_all_displayed` | fail | fail |
| `BusinessKnowledgeProfileHoursTest::no_change_row_for_a_true_hours_no_op` | fail | fail |
| `BusinessKnowledgeProfileSeamTest::only_the_manager_writes_the_tracked_tables` | fail | fail |
| `OpportunityManagerBeginRunTest::heartbeat_one_second…` | fail | fail |
| `WebsiteIndexingTest::robots_txt_is_untouched_by_this_feature_branch` | fail | fail |
| `WebsiteDraftPageServiceSeamTest::store_page_persists_every_draft_field…` | fail | fail |
| `WebsiteDraftPageServiceSeamTest::update_page_persists_every_draft_field…` | fail | fail |
| `WebsiteDraftPublishTest::rollback_repoints_the_website…` | fail | fail |

**Introduced: 0. Introduced errors: 0.** Fixed relative to earlier rounds of
this lane: the three `TemporaryEnvironmentFileTest` failures (resolved by
merging upstream PR #235) and the `EntitlementEnumsTest` count collision
(resolved under explicit authorization). Unchanged: the nine above.

## 8a. Security Correction 36 — legacy webhook P0 hardening

An independent read-only audit after the previous "ready for review" report
found five mechanically proven P0 defects on the public, unauthenticated
legacy webhook surface, plus two Twilio sibling bypasses. All seven are
closed; the regression is
`tests/Feature/Messaging/LegacyWebhookSecurityCorrectionTest.php`
(24 tests).

**P0-1 `updateDLR()` — cross-tenant lookup and repeatable refund.**
`Reports::whereLike(['status'], $message_id)->first()` searched every
tenant's reports platform-wide, with `%`/`_` from the attacker's own id live
as LIKE metacharacters and `->first()` breaking ties by insertion order.
Paired with an unconditional `sms_unit + cost` credit it was a repeatable
billing-credit primitive.

*Resolution strategy, exactly:* one shared seam,
`resolveReportForProviderMessage()`, strongest first — (1) the managed
`business_messaging_operations` provider-message → `report_id` correlation
(composite `UNIQUE(provider, provider_message_id)` index — a provider message
id ALONE is not globally unique, so this strategy runs only for a caller that
supplied authoritative provider context and is skipped entirely otherwise —
plus a durable foreign key, an exact join, and no string matching);
(2) failing that, the legacy packed `status` column under four constraints
together: the id is escaped so `%` and `_` are literal, the pattern anchors
it to the END of the packed value (`%|<id>`), the candidate set is scoped to
the resolved SendingServer when the caller knows one, and each candidate is
re-checked by exact parsed equality with an exactly-one-survivor requirement.
Zero or several survivors fail closed.

*Refund idempotency strategy, exactly:* the credit fires only on the
TRANSITION INTO a non-delivered terminal state. The row is re-read under
`lockForUpdate()` inside a short transaction and the previous, already-durable
`customer_status` decides — no new column is invented and nothing is inferred
from a value that could be lost. A second identical callback finds the row
already terminal and credits nothing. A late `Delivered` after a refund
re-debits the cost, so a previously-refunded failure cannot silently become a
free message. No provider or network work happens inside the lock.

**P0-2 `inboundSolucoesdigitais` / P0-3 `inboundTextbelt`.** Both matched a
foreign report by substring and then trusted its `from` as this Business's
sender for a forged inbound write, with the STOP/keyword/blacklist side
effects that follow. Both now use the same seam; foreign, partial, wildcard
and ambiguous ids all resolve to nothing and write nothing.

**P0-4 `inboundWhatsender` — arbitrary file write.** The provider-supplied
`media.filename` was concatenated onto `public_path('mms/')`, so
`../../evil.php` escaped the directory and `evil.php` wrote executable PHP
into the web root. `basename()` alone would not have been a fix. The
provider's filename now controls nothing: the stored name is generated, the
extension comes from `finfo`'s reading of the actual bytes, the type must be
in a small allowlist for the media this handler genuinely supports, a 16 MB
bound applies, the resolved parent directory is re-checked, and failed
validation writes nothing.

**P0-5 legacy Telnyx outbound branch.** §4.6.5 gated the inbound branch and
left `direction === 'outbound'` calling `updateDLR()` directly, so the same
unverifiable request could mutate a Report and move `sms_unit` through the
other door. Authenticity is a property of the connection, not of a payload's
direction field. Both branches now fail closed, and an admin/legacy
connection gets no unauthenticated exception either.

**Twilio siblings.** `inboundTwilioCopilot()` is a Twilio webhook that never
called the validator; it now reuses the canonical one, parameterized by
provider rather than copied. `inbound/webhook/{user}` passed the literal
string `'Twilio'` where every other caller passes a resolved SendingServer —
a provider name typed into an argument is not evidence of provenance — and
now resolves the connection and validates the signature or writes nothing.

**Deferred by name, not silently closed.** The same audit reported possible
vendor signing mechanisms for **Plivo, Vonage, Bandwidth, Infobip,
GatewayAPI, WhatsApp/Meta, AWS SNS and others**. Each needs its own
externally-verified provider-authenticity contract; implementing signature
schemes from memory would be worse than recording the gap. They are **not**
addressed by this correction and remain open.

## 9. Deferred, exactly as the contract defers them

`EloquentCampaignRepository::sendApi()`;
`app/Console/Commands/SendScheduleAPIMessage.php` delegation;
`EloquentCampaignRepository::apiCampaignBuilder()`; Managed Accounts; BYO
Telnyx inbound upgrade (Slice 9); number lifecycle automation (Slice 4);
retail usage-rate activation; live provider activation. None is touched,
tested or delegated here.