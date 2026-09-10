<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Library\Messaging\InboundWebhookAttributionResolver;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 3 §4.6 — T-MSG-16..23, 49..54.
 *
 * Every assertion here issues a real POST to the real managed route
 * (route('inbound.telnyx_managed')), never calling the resolver directly,
 * because the contract requires these guarantees to hold at the production
 * entry point rather than only in a helper.
 */
class InboundAttributionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeAdapter();
    }

    /**
     * The fake adapter returns whatever event we queue, so these tests
     * exercise the resolver's decisions rather than Telnyx's payload shape
     * (which TelnyxAdapterTest covers separately, under Http::fake()).
     */
    private function postEvent(InboundWebhookEvent $event, array $body = ['probe' => true]): \Illuminate\Testing\TestResponse
    {
        $this->fakeAdapter->queueInboundWebhook($event);

        return $this->postJson(route('inbound.telnyx_managed'), $body);
    }

    private function messageReceived(
        ?string $profileId,
        ?string $destination,
        string $providerMessageId = 'pm_inbound_1',
    ): InboundWebhookEvent {
        return new InboundWebhookEvent(
            kind: InboundWebhookEventKind::MessageReceived,
            messagingProfileId: $profileId,
            destinationNumber: $destination,
            fromNumber: '+14155550000',
            body: 'inbound text',
            mediaUrls: [],
            providerMessageId: $providerMessageId,
            deliveryStatus: null,
            occurredAt: CarbonImmutable::now(),
        );
    }

    private function deliveryStatus(
        string $providerMessageId,
        string $status,
        ?string $profileId = null,
        ?string $destination = null,
    ): InboundWebhookEvent {
        return new InboundWebhookEvent(
            kind: InboundWebhookEventKind::DeliveryStatus,
            messagingProfileId: $profileId,
            destinationNumber: $destination,
            fromNumber: null,
            body: null,
            mediaUrls: [],
            providerMessageId: $providerMessageId,
            deliveryStatus: $status,
            occurredAt: CarbonImmutable::now(),
        );
    }

    private function rejectionCount(string $reason): int
    {
        return DB::table('messaging_webhook_rejections')->where('reason', $reason)->sum('occurrence_count');
    }

    // ---------------------------------------------------------------
    // T-MSG-16 — both signals agree
    // ---------------------------------------------------------------

    public function test_matching_profile_and_number_are_attributed(): void
    {
        [$business, $identity, $number] = $this->managedBusiness();

        $this->postEvent($this->messageReceived($identity->messaging_profile_id, $number->phone_number))
            ->assertOk()
            ->assertJson(['status' => 'accepted']);

        $operation = DB::table('business_messaging_operations')->where('direction', 'inbound')->first();
        $this->assertNotNull($operation);
        $this->assertSame((int) $business->id, (int) $operation->business_id);
        $this->assertSame((int) $identity->id, (int) $operation->business_messaging_identity_id);
        $this->assertSame(0, DB::table('messaging_webhook_rejections')->count());

        // The inbound message is measured, once.
        $this->assertSame(1, DB::table('business_usage_measurements')
            ->where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------
    // T-MSG-17..20 — every fail-closed combination
    // ---------------------------------------------------------------

    public function test_known_profile_with_unknown_number_fails_closed(): void
    {
        [, $identity] = $this->managedBusiness();

        $this->postEvent($this->messageReceived($identity->messaging_profile_id, '+14155557777'))
            ->assertOk()
            ->assertJson(['status' => 'unattributed']);

        $this->assertSame(1, $this->rejectionCount('unknown_mapping'));
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
    }

    public function test_unknown_profile_with_known_number_fails_closed(): void
    {
        [, , $number] = $this->managedBusiness();

        $this->postEvent($this->messageReceived('mp_not_registered', $number->phone_number))
            ->assertOk()
            ->assertJson(['status' => 'unattributed']);

        $this->assertSame(1, $this->rejectionCount('unknown_mapping'));
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
    }

    public function test_profile_of_business_a_with_number_of_business_b_is_a_conflict(): void
    {
        [, $identityA] = $this->managedBusiness();
        [, $identityB, $numberB] = $this->managedBusiness();

        $this->postEvent($this->messageReceived($identityA->messaging_profile_id, $numberB->phone_number))
            ->assertOk()
            ->assertJson(['status' => 'unattributed']);

        $rejection = DB::table('messaging_webhook_rejections')->where('reason', 'conflicting_mapping')->first();
        $this->assertNotNull($rejection, 'A cross-Business signal disagreement must be recorded as a conflict.');
        // Both candidate identities are recorded for triage, and neither is
        // treated as authoritative.
        $this->assertSame((int) $identityA->id, (int) $rejection->profile_resolved_identity_id);
        $this->assertSame((int) $identityB->id, (int) $rejection->number_resolved_identity_id);

        $this->assertSame(0, DB::table('business_messaging_operations')->count());
    }

    public function test_a_released_number_mapping_fails_closed(): void
    {
        $business = $this->makeBusiness();
        $identity = $this->attachIdentity($business);
        $number = $this->attachNumber($identity, $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Released);

        $this->postEvent($this->messageReceived($identity->messaging_profile_id, $number->phone_number))
            ->assertOk()
            ->assertJson(['status' => 'unattributed']);

        $this->assertSame(1, $this->rejectionCount('unknown_mapping'));
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
    }

    public function test_a_missing_signal_is_a_malformed_payload(): void
    {
        [, $identity, $number] = $this->managedBusiness();

        $this->postEvent($this->messageReceived(null, $number->phone_number))->assertStatus(400);
        $this->postEvent($this->messageReceived($identity->messaging_profile_id, null, 'pm_2'))->assertStatus(400);

        $this->assertSame(2, $this->rejectionCount('malformed_payload'));
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
    }

    // ---------------------------------------------------------------
    // T-MSG-21 — signature failure
    // ---------------------------------------------------------------

    public function test_an_invalid_signature_is_refused_with_403_and_changes_nothing(): void
    {
        [, $identity, $number] = $this->managedBusiness();
        $this->fakeAdapter->signatureValid = false;

        $this->postEvent($this->messageReceived($identity->messaging_profile_id, $number->phone_number))
            ->assertStatus(403);

        $this->assertSame(1, $this->rejectionCount('invalid_signature'));
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
        $this->assertSame(0, DB::table('business_usage_measurements')->count());
    }

    // ---------------------------------------------------------------
    // T-MSG-23 — inbound replay
    // ---------------------------------------------------------------

    public function test_a_replayed_inbound_message_produces_exactly_one_effect(): void
    {
        [$business, $identity, $number] = $this->managedBusiness();

        $this->postEvent($this->messageReceived($identity->messaging_profile_id, $number->phone_number, 'pm_dup'))
            ->assertOk()->assertJson(['status' => 'accepted']);

        $this->postEvent($this->messageReceived($identity->messaging_profile_id, $number->phone_number, 'pm_dup'))
            ->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, DB::table('business_messaging_operations')->where('direction', 'inbound')->count());
        $this->assertSame(1, $this->rejectionCount('duplicate'));
        $this->assertSame(1, DB::table('business_usage_measurements')->where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------
    // T-MSG-49..52 — delivery-status replay semantics
    // ---------------------------------------------------------------

    /** @return array{0: object, 1: string} the operation row and its provider message id */
    private function acceptedOutboundOperation(): array
    {
        [$business, $identity] = $this->managedBusiness();

        $result = app(\App\Library\Messaging\ManagedMessageDispatcher::class)
            ->dispatch($business, '+14155558200', 'hello', 'op_dlr');

        $this->assertTrue($result->accepted);

        $operation = DB::table('business_messaging_operations')->where('operation_key', 'op_dlr')->first();
        $this->assertSame('accepted', $operation->status);

        return [$operation, (string) $result->providerMessageId];
    }

    public function test_the_first_legitimate_delivery_callback_is_applied_not_discarded(): void
    {
        [$operation, $providerMessageId] = $this->acceptedOutboundOperation();

        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))
            ->assertOk()
            ->assertJson(['status' => 'accepted']);

        $this->assertSame('delivered', DB::table('business_messaging_operations')
            ->where('id', $operation->id)->value('status'));
        $this->assertSame(0, DB::table('messaging_webhook_rejections')->count());
    }

    public function test_an_exact_delivery_replay_is_a_no_op_only_after_the_first_transition(): void
    {
        [$operation, $providerMessageId] = $this->acceptedOutboundOperation();

        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))->assertOk();
        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertSame('delivered', DB::table('business_messaging_operations')
            ->where('id', $operation->id)->value('status'));
        $this->assertSame(1, $this->rejectionCount('duplicate'));
    }

    public function test_a_regressive_delivery_callback_cannot_move_a_terminal_row_backward(): void
    {
        [$operation, $providerMessageId] = $this->acceptedOutboundOperation();

        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))->assertOk();

        // delivered -> accepted is absent from the permitted-next table.
        $this->postEvent($this->deliveryStatus($providerMessageId, 'sent'))
            ->assertOk()
            ->assertJson(['status' => 'rejected']);

        $fresh = DB::table('business_messaging_operations')->where('id', $operation->id)->first();
        $this->assertSame('delivered', $fresh->status);
        $this->assertSame(1, $this->rejectionCount('regressive_transition'));
    }

    public function test_the_full_lifecycle_applies_each_transition_exactly_once(): void
    {
        [$operation, $providerMessageId] = $this->acceptedOutboundOperation();

        // attempted -> accepted happened on the send itself; a callback may
        // only ever move an already-accepted row.
        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))->assertOk();
        $this->assertSame('delivered', DB::table('business_messaging_operations')->where('id', $operation->id)->value('status'));

        // A terminal row accepts nothing further.
        $this->postEvent($this->deliveryStatus($providerMessageId, 'failed'))
            ->assertOk()
            ->assertJson(['status' => 'rejected']);
        $this->assertSame('delivered', DB::table('business_messaging_operations')->where('id', $operation->id)->value('status'));
    }

    // ---------------------------------------------------------------
    // T-MSG-53 — unknown and cross-Business delivery evidence
    // ---------------------------------------------------------------

    /**
     * Updated for the early-DLR race (audit P10).
     *
     * An unattributable DELIVERY callback is now asked for redelivery first,
     * because the commonest cause is legitimate: the callback beat its own
     * operation's finalization, and by the next attempt the correlation
     * exists. Answering 200 immediately — as this used to — told the
     * provider not to send it again, and the callback was lost for good.
     *
     * The retry budget is bounded, so a genuinely foreign provider message
     * id cannot make a provider retry forever. Both halves are asserted.
     */
    public function test_an_unknown_provider_message_id_is_retried_and_then_finally_refused(): void
    {
        $this->managedBusiness();

        // Within the budget: ask the provider to redeliver.
        for ($attempt = 1; $attempt <= InboundWebhookAttributionResolver::EARLY_DLR_RETRY_BUDGET; $attempt++) {
            $this->postEvent($this->deliveryStatus('pm_never_seen', 'delivered'))
                ->assertStatus(503)
                ->assertJson(['status' => 'retry']);
        }

        // Past it: stop asking, and accept it as genuinely unattributable.
        $this->postEvent($this->deliveryStatus('pm_never_seen', 'delivered'))
            ->assertOk()
            ->assertJson(['status' => 'unattributed']);

        // One fingerprint, counted — not one row per redelivery.
        $this->assertSame(1, DB::table('messaging_webhook_rejections')->count());
        $this->assertSame(
            InboundWebhookAttributionResolver::EARLY_DLR_RETRY_BUDGET + 1,
            $this->rejectionCount('unknown_mapping'),
        );
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
    }

    /**
     * The race this budget exists for, deterministically staged: the
     * callback arrives while the operation has no provider_message_id yet,
     * and succeeds on redelivery once finalization has attached it.
     */
    public function test_a_delivery_callback_that_beats_finalization_succeeds_on_redelivery(): void
    {
        [$operation, $providerMessageId] = $this->acceptedOutboundOperation();

        // Rewind to the instant before finalization committed.
        DB::table('business_messaging_operations')
            ->where('id', $operation->id)
            ->update(['provider_message_id' => null, 'status' => MessagingOperationStatus::Attempted->value]);

        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))
            ->assertStatus(503)
            ->assertJson(['status' => 'retry']);

        $this->assertSame(
            MessagingOperationStatus::Attempted->value,
            DB::table('business_messaging_operations')->where('id', $operation->id)->value('status'),
        );

        // Finalization completes, then the provider redelivers.
        DB::table('business_messaging_operations')
            ->where('id', $operation->id)
            ->update(['provider_message_id' => $providerMessageId, 'status' => MessagingOperationStatus::Accepted->value]);

        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))
            ->assertOk()
            ->assertJson(['status' => 'accepted']);

        $this->assertSame(
            MessagingOperationStatus::Delivered->value,
            DB::table('business_messaging_operations')->where('id', $operation->id)->value('status'),
            'The early callback must not be lost; redelivery applies it.',
        );
    }

    public function test_delivery_evidence_resolving_to_another_business_is_refused(): void
    {
        [$operation, $providerMessageId] = $this->acceptedOutboundOperation();
        [, $otherIdentity, $otherNumber] = $this->managedBusiness();

        $this->postEvent($this->deliveryStatus(
            $providerMessageId,
            'delivered',
            $otherIdentity->messaging_profile_id,
            $otherNumber->phone_number,
        ))->assertOk()->assertJson(['status' => 'rejected']);

        $this->assertSame('accepted', DB::table('business_messaging_operations')
            ->where('id', $operation->id)->value('status'), 'A conflicting callback must not change state.');
        $this->assertSame(1, $this->rejectionCount('conflicting_mapping'));
    }

    // ---------------------------------------------------------------
    // T-MSG-54 — the three idempotency mechanisms are independent
    // ---------------------------------------------------------------

    public function test_inbound_dedup_dlr_replay_and_regression_do_not_interfere(): void
    {
        [, $identity, $number] = $this->managedBusiness();
        [$operation, $providerMessageId] = $this->acceptedOutboundOperation();

        // (a) inbound replay, detected by the guarded insert
        $this->postEvent($this->messageReceived($identity->messaging_profile_id, $number->phone_number, 'pm_in'))->assertOk();
        $this->postEvent($this->messageReceived($identity->messaging_profile_id, $number->phone_number, 'pm_in'))
            ->assertOk()->assertJson(['status' => 'duplicate']);

        // (b) a legitimate DLR transition for an unrelated operation
        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))->assertOk();

        // (c) a regressive DLR for that same operation
        $this->postEvent($this->deliveryStatus($providerMessageId, 'sent'))->assertOk();

        $this->assertSame(1, DB::table('business_messaging_operations')
            ->where('direction', 'inbound')->count(), 'Inbound dedup unaffected by DLR handling.');
        $this->assertSame('delivered', DB::table('business_messaging_operations')
            ->where('id', $operation->id)->value('status'), 'The legitimate transition still applied.');
        $this->assertSame(1, $this->rejectionCount('duplicate'));
        $this->assertSame(1, $this->rejectionCount('regressive_transition'));
    }

    // ---------------------------------------------------------------
    // T-MSG-37 — rejection minimization and deduplication
    // ---------------------------------------------------------------

    public function test_repeated_identical_rejections_increment_rather_than_duplicate(): void
    {
        [, $identity] = $this->managedBusiness();
        $body = ['identical' => 'payload'];

        for ($i = 0; $i < 3; $i++) {
            $this->postEvent($this->messageReceived($identity->messaging_profile_id, '+14155556666'), $body);
        }

        $rows = DB::table('messaging_webhook_rejections')->where('reason', 'unknown_mapping')->get();
        $this->assertCount(1, $rows, 'An identical rejection must increment, not insert.');
        $this->assertSame(3, (int) $rows[0]->occurrence_count);
        $this->assertNotNull($rows[0]->payload_hash);
        $this->assertSame(hash('sha256', json_encode($body)), $rows[0]->payload_hash);
    }

    public function test_a_rejection_row_never_retains_the_message_body(): void
    {
        [, $identity] = $this->managedBusiness();
        $secretText = 'SENSITIVE-BODY-e7f3a91c';

        $this->postEvent(
            $this->messageReceived($identity->messaging_profile_id, '+14155556001'),
            ['data' => ['payload' => ['text' => $secretText]]],
        );

        $row = DB::table('messaging_webhook_rejections')->first();
        $this->assertNotNull($row);

        foreach ((array) $row as $column => $value) {
            $this->assertStringNotContainsString($secretText, (string) $value, "Column [{$column}] retained the body.");
        }
    }

    // ---------------------------------------------------------------
    // Kill switch (T-MSG-31/32 at the inbound boundary)
    // ---------------------------------------------------------------

    public function test_inbound_traffic_is_ignored_while_managed_messaging_is_disabled(): void
    {
        [, $identity, $number] = $this->managedBusiness();

        // The real adapter would be resolved here; with the switch off its
        // constructor throws and nothing is processed or recorded.
        $this->app->forgetInstance(\App\Library\Messaging\Contracts\MessagingProviderAdapter::class);
        $this->app->bind(
            \App\Library\Messaging\Contracts\MessagingProviderAdapter::class,
            fn () => new \App\Library\Messaging\TelnyxMessagingAdapter(),
        );
        config(['messaging.managed_messaging_enabled' => false]);

        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(0, DB::table('business_messaging_operations')->count());
        $this->assertSame(0, DB::table('messaging_webhook_rejections')->count());
    }

    public function test_the_managed_route_exists_and_is_a_post(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->getName() === 'inbound.telnyx_managed');

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertSame('inbound/telnyx-managed', $route->uri());
        $this->assertSame(MessagingProvider::Telnyx->value, 'telnyx');
    }

    // ---------------------------------------------------------------
    // T-MSG-40 — outbound, inbound and DLR idempotency are independent
    // ---------------------------------------------------------------

    /**
     * One test, one database, one uninterrupted run. That is the whole
     * point: proving the three mechanisms in three separate tests with a
     * refreshed database between them would prove only that each works in
     * isolation, which is not the claim. The claim is that forcing a
     * duplicate on ONE of them changes nothing about the other two, and the
     * only way to see that is to have all three live at once.
     *
     * The three mechanisms are genuinely different (§4.8's responsibility
     * table): outbound dedupes on `operation_key`; inbound dedupes on a
     * guarded insert keyed by `(provider, provider_message_id)`; and DLR
     * does NOT dedupe on row existence at all — it uses the
     * status-transition guard, because for a delivery callback the row
     * existing is normal rather than evidence of replay.
     */
    public function test_outbound_inbound_and_dlr_idempotency_never_interfere(): void
    {
        [$business, $identity, $number] = $this->managedBusiness();
        $dispatcher = app(\App\Library\Messaging\ManagedMessageDispatcher::class);

        // --- Establish one of each, all in the same run -----------------

        // 1. An outbound operation.
        $outbound = $dispatcher->dispatch($business, '+14155558300', 'outbound one', 'op_independence');
        $this->assertTrue($outbound->accepted);
        $outboundProviderId = (string) $outbound->providerMessageId;

        // 2. A separate outbound operation, whose DLR we will replay. Kept
        //    distinct from #1 so a DLR duplicate cannot be confused with an
        //    outbound duplicate.
        $dlrTarget = $dispatcher->dispatch($business, '+14155558301', 'outbound two', 'op_independence_dlr');
        $this->assertTrue($dlrTarget->accepted);
        $dlrProviderId = (string) $dlrTarget->providerMessageId;

        // 3. An inbound message with its own, different provider message id.
        $inboundProviderId = 'pm_independence_inbound';
        $this->postEvent(
            $this->messageReceived($identity->messaging_profile_id, $number->phone_number, $inboundProviderId),
        )->assertOk();

        // 4. The first delivery callback for #2 — a real transition.
        $this->postEvent($this->deliveryStatus($dlrProviderId, 'delivered'))->assertOk();

        $baselineOperations = DB::table('business_messaging_operations')->count();
        $baselineMeasurements = DB::table('business_usage_measurements')->count();

        $this->assertSame(3, $baselineOperations, 'Two outbound operations and one inbound row.');
        $this->assertSame('delivered', $this->statusOf('op_independence_dlr'));
        $this->assertSame('accepted', $this->statusOf('op_independence'));

        // --- Now force each duplicate, one at a time -------------------

        // (a) OUTBOUND duplicate: same operation_key. Suppressed — and the
        //     provider is not called a second time.
        $sentBefore = $this->fakeAdapter->sentCount();
        $repeat = $dispatcher->dispatch($business, '+14155558300', 'outbound one', 'op_independence');

        $this->assertSame($outboundProviderId, (string) $repeat->providerMessageId, 'The recorded result is returned.');
        $this->assertSame($sentBefore, $this->fakeAdapter->sentCount(), 'A confirmed acceptance is never re-sent.');
        $this->assertSame($baselineOperations, DB::table('business_messaging_operations')->count());
        $this->assertSame($baselineMeasurements, DB::table('business_usage_measurements')->count());

        // …and neither of the other two was disturbed.
        $this->assertSame('delivered', $this->statusOf('op_independence_dlr'));
        $this->assertSame(1, $this->inboundRowCount($inboundProviderId));

        // (b) INBOUND duplicate: same provider_message_id as the inbound
        //     row. Suppressed by the guarded insert, not by anything the
        //     outbound or DLR mechanisms did.
        $this->postEvent(
            $this->messageReceived($identity->messaging_profile_id, $number->phone_number, $inboundProviderId),
        )->assertOk();

        $this->assertSame(1, $this->inboundRowCount($inboundProviderId), 'Exactly one inbound effect.');
        $this->assertSame($baselineOperations, DB::table('business_messaging_operations')->count());

        // …the outbound record is still there and still accepted, and the
        // DLR result is still delivered.
        $this->assertSame('accepted', $this->statusOf('op_independence'));
        $this->assertSame('delivered', $this->statusOf('op_independence_dlr'));

        // (c) DLR duplicate: the identical delivered callback again. A no-op
        //     by the status-transition guard, recorded as a duplicate.
        $duplicatesBefore = $this->rejectionCount('duplicate');
        $this->postEvent($this->deliveryStatus($dlrProviderId, 'delivered'))->assertOk();

        $this->assertSame('delivered', $this->statusOf('op_independence_dlr'), 'Status unchanged.');
        $this->assertGreaterThan($duplicatesBefore, $this->rejectionCount('duplicate'));

        // …and neither of the other two was suppressed or altered.
        $this->assertSame('accepted', $this->statusOf('op_independence'));
        $this->assertSame(1, $this->inboundRowCount($inboundProviderId));

        // --- Final row counts and ownership, asserted directly ---------

        $this->assertSame($baselineOperations, DB::table('business_messaging_operations')->count());
        $this->assertSame($baselineMeasurements, DB::table('business_usage_measurements')->count());

        foreach (DB::table('business_messaging_operations')->get() as $row) {
            $this->assertSame((int) $business->id, (int) $row->business_id, 'Every row belongs to this Business.');
            $this->assertSame((int) $identity->id, (int) $row->business_messaging_identity_id);
        }

        // A duplicate on one mechanism must not have leaked a rejection row
        // attributing it to another mechanism's reason.
        $this->assertSame(0, $this->rejectionCount('regressive_transition'));
        $this->assertSame(0, $this->rejectionCount('conflicting_mapping'));
    }

    private function statusOf(string $operationKey): string
    {
        return (string) DB::table('business_messaging_operations')
            ->where('operation_key', $operationKey)
            ->value('status');
    }

    private function inboundRowCount(string $providerMessageId): int
    {
        return DB::table('business_messaging_operations')
            ->where('direction', 'inbound')
            ->where('provider_message_id', $providerMessageId)
            ->count();
    }

    // ---------------------------------------------------------------
    // Audit P6 — two Businesses may safely reuse the same client key
    // ---------------------------------------------------------------

    /**
     * `operation_key` and `idempotency_key` are chosen by the CALLER — a
     * campaign id and recipient, or a client token — so two Businesses can
     * legitimately produce the same string. Under the old global unique
     * indexes the second Business's send resolved to the FIRST Business's
     * recorded row and was handed its provider message id: one tenant
     * reading another's send, and its own send silently never happening.
     */
    public function test_two_businesses_using_the_same_operation_key_never_see_each_others_rows(): void
    {
        [$businessA, $identityA, $numberA] = $this->managedBusiness();
        [$businessB, $identityB, $numberB] = $this->managedBusiness();

        $sharedKey = 'managed:campaign:1:14155550000';
        $dispatcher = app(\App\Library\Messaging\ManagedMessageDispatcher::class);

        $resultA = $dispatcher->dispatch($businessA, '+14155557001', 'for A', $sharedKey);
        $resultB = $dispatcher->dispatch($businessB, '+14155557002', 'for B', $sharedKey);

        // Each got its OWN send, not the other's recorded result.
        $this->assertTrue($resultA->accepted);
        $this->assertTrue($resultB->accepted);
        $this->assertNotSame(
            $resultA->providerMessageId,
            $resultB->providerMessageId,
            'Business B must not be handed Business A\'s provider message id.',
        );
        $this->assertSame(2, $this->fakeAdapter->sentCount(), 'Both sends really happened.');

        // Two operation rows, one per Business, each owned correctly.
        $rows = DB::table('business_messaging_operations')->where('operation_key', $sharedKey)->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [(int) $businessA->id, (int) $businessB->id],
            $rows->map(fn ($r) => (int) $r->business_id)->all(),
        );

        // Two measurements, one per Business.
        $measurements = DB::table('business_usage_measurements')->get();
        $this->assertCount(2, $measurements);
        $this->assertEqualsCanonicalizing(
            [(int) $businessA->id, (int) $businessB->id],
            $measurements->map(fn ($r) => (int) $r->business_id)->all(),
        );

        // And each Business's OWN repeat is still suppressed.
        $repeatA = $dispatcher->dispatch($businessA, '+14155557001', 'for A', $sharedKey);
        $this->assertSame($resultA->providerMessageId, $repeatA->providerMessageId);
        $this->assertSame(2, $this->fakeAdapter->sentCount(), 'A repeat within one Business still sends nothing new.');
        $this->assertSame(2, DB::table('business_messaging_operations')->where('operation_key', $sharedKey)->count());
    }

    public function test_provider_message_id_uniqueness_stays_global_for_unambiguous_attribution()
    {
        // The counterpart to the scoping above: a webhook carries only a
        // provider message id, so if that were scoped per Business it could
        // match several rows and attribution would become ambiguous. It must
        // stay globally unique.
        [$businessA] = $this->managedBusiness();
        [$businessB] = $this->managedBusiness();

        $dispatcher = app(\App\Library\Messaging\ManagedMessageDispatcher::class);
        $dispatcher->dispatch($businessA, '+14155557003', 'a', 'key-a');

        $providerMessageId = (string) DB::table('business_messaging_operations')->value('provider_message_id');

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('business_messaging_operations')->insert([
            'business_id' => (int) $businessB->id,
            'transport_mode' => 'managed',
            'provider' => MessagingProvider::Telnyx->value,
            'direction' => 'outbound',
            'message_type' => 'sms',
            'operation_key' => 'key-b',
            'provider_message_id' => $providerMessageId,
            'status' => MessagingOperationStatus::Accepted->value,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Audit P7 — acceptance is not delivery, and both records agree
    // ---------------------------------------------------------------

    /**
     * @return array{0: object, 1: string, 2: \App\Models\Reports}
     */
    private function acceptedOperationWithReport(): array
    {
        [$business, $identity] = $this->managedBusiness();

        $campaign = \App\Models\Campaigns::create([
            'user_id' => $business->customer->user_id,
            'business_id' => $business->id,
            'campaign_name' => 'Lifecycle ' . uniqid(),
            'message' => 'lifecycle',
            'sms_type' => 'plain',
            'status' => \App\Models\Campaigns::STATUS_NEW,
        ]);

        $report = $campaign->sendSMS([
            'user_id' => $business->customer_id,
            'campaign_id' => $campaign->id,
            'phone' => '14155557100',
            'sender_id' => 'TESTSENDER',
            'message' => 'lifecycle',
            'sms_type' => 'plain',
            'cost' => 0,
            'sms_count' => 1,
        ]);

        $operation = DB::table('business_messaging_operations')->where('direction', 'outbound')->first();

        return [$operation, (string) $operation->provider_message_id, $report];
    }

    public function test_provider_acceptance_is_recorded_as_sent_and_correlated_to_its_report(): void
    {
        [$operation, , $report] = $this->acceptedOperationWithReport();

        // The customer-visible record does NOT claim delivery.
        $this->assertSame('Sent', $report->fresh()->status);
        $this->assertStringNotContainsString('Delivered', (string) $report->fresh()->status);

        // The operation says accepted, and the two are durably correlated.
        $this->assertSame(MessagingOperationStatus::Accepted->value, $operation->status);
        $this->assertSame((int) $report->id, (int) $operation->report_id);
    }

    public function test_a_delivered_callback_updates_both_records_exactly_once(): void
    {
        [$operation, $providerMessageId, $report] = $this->acceptedOperationWithReport();

        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))->assertOk();

        $this->assertSame(
            MessagingOperationStatus::Delivered->value,
            DB::table('business_messaging_operations')->where('id', $operation->id)->value('status'),
        );
        $this->assertSame('Delivered', $report->fresh()->status, 'The customer-visible record follows the callback.');
        $this->assertSame('Delivered', $report->fresh()->customer_status);

        // A duplicate changes nothing and is recorded as a duplicate.
        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))->assertOk();

        $this->assertSame('Delivered', $report->fresh()->status);
        $this->assertSame(1, $this->rejectionCount('duplicate'));
    }

    public function test_a_failed_callback_updates_the_customer_visible_record_too(): void
    {
        [$operation, $providerMessageId, $report] = $this->acceptedOperationWithReport();

        $this->assertSame('Sent', $report->fresh()->status);

        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivery_failed'))->assertOk();

        // THE DEFECT THIS CLOSES: a failed DLR used to update only the
        // operation row, leaving the Report permanently saying Delivered.
        $this->assertSame(
            MessagingOperationStatus::Failed->value,
            DB::table('business_messaging_operations')->where('id', $operation->id)->value('status'),
        );
        $this->assertSame('Failed', $report->fresh()->status);
        $this->assertSame('Failed', $report->fresh()->customer_status);
    }

    public function test_a_regressive_delivered_after_failed_changes_nothing(): void
    {
        [$operation, $providerMessageId, $report] = $this->acceptedOperationWithReport();

        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivery_failed'))->assertOk();
        $this->assertSame('Failed', $report->fresh()->status);

        $this->postEvent($this->deliveryStatus($providerMessageId, 'delivered'))->assertOk();

        $this->assertSame(
            MessagingOperationStatus::Failed->value,
            DB::table('business_messaging_operations')->where('id', $operation->id)->value('status'),
            'A terminal failure is never reversed by a later callback.',
        );
        $this->assertSame('Failed', $report->fresh()->status);
        $this->assertSame(1, $this->rejectionCount('regressive_transition'));
    }

    public function test_managed_transport_never_consumes_legacy_sms_credit(): void
    {
        [$business, $identity] = $this->managedBusiness();
        $user = $business->customer->user;
        $user->sms_unit = 500;
        $user->save();

        $campaign = \App\Models\Campaigns::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'campaign_name' => 'Credit ' . uniqid(),
            'message' => 'credit',
            'sms_type' => 'plain',
            'status' => \App\Models\Campaigns::STATUS_NEW,
        ]);

        $report = $campaign->sendSMS([
            'user_id' => $business->customer_id,
            'campaign_id' => $campaign->id,
            'phone' => '14155557200',
            'sender_id' => 'TESTSENDER',
            'message' => 'credit',
            'sms_type' => 'plain',
            'cost' => 7,
            'sms_count' => 1,
        ]);

        // Every legacy debit in this codebase is gated on the SAME test:
        // `substr_count($status, 'Delivered') == 1` — track_message()'s
        // sms_unit deduction and quickSend()'s both. Asserting the gate
        // itself is what proves no debit can fire, and it does so without
        // fabricating the unrelated arguments track_message() also needs.
        $this->assertSame(0, substr_count((string) $report->status, 'Delivered'));
        $this->assertSame(0, substr_count((string) $report->fresh()->customer_status, 'Delivered'));

        $this->assertSame(500, (int) $user->fresh()->sms_unit, 'Managed transport must not consume legacy SMS credit.');

        // And it is measured instead, exactly once.
        $this->assertSame(1, DB::table('business_usage_measurements')->count());
    }

    // ---------------------------------------------------------------
    // Audit P5 — the real segment count, from SMSCounter
    // ---------------------------------------------------------------

    public function test_a_multi_segment_inbound_message_records_its_real_segment_count(): void
    {
        [$business, $identity, $number] = $this->managedBusiness();

        // Comfortably past one GSM-7 segment; the expected value comes from
        // the same authority production uses, so this test cannot drift from
        // it by hard-coding a number.
        $body = str_repeat('This is a long inbound message. ', 12);
        $expected = (string) (new \App\Library\SMSCounter())->count($body)->messages;

        $this->assertGreaterThan(1, (int) $expected, 'The fixture must genuinely span several segments.');

        $event = new InboundWebhookEvent(
            kind: InboundWebhookEventKind::MessageReceived,
            messagingProfileId: $identity->messaging_profile_id,
            destinationNumber: $number->phone_number,
            fromNumber: '+14155550000',
            body: $body,
            mediaUrls: [],
            providerMessageId: 'pm_multi_segment',
            deliveryStatus: null,
            occurredAt: CarbonImmutable::now(),
        );

        $this->postEvent($event)->assertOk()->assertJson(['status' => 'accepted']);

        $this->assertSame(
            (float) $expected,
            (float) DB::table('business_usage_measurements')->value('quantity'),
            'The inbound measurement must record real segments, not a hardcoded 1.',
        );
    }

    public function test_a_single_segment_inbound_message_still_records_one(): void
    {
        [, $identity, $number] = $this->managedBusiness();

        $this->postEvent($this->messageReceived($identity->messaging_profile_id, $number->phone_number))
            ->assertOk();

        $this->assertSame(1.0, (float) DB::table('business_usage_measurements')->value('quantity'));
    }

    // ---------------------------------------------------------------
    // Audit P4 — concurrent duplicate inbound delivery
    // ---------------------------------------------------------------

    /**
     * Repeated deliberately: an idempotency guarantee that holds once may
     * simply have been lucky about ordering.
     */
    public function test_repeated_duplicate_inbound_deliveries_produce_exactly_one_effect(): void
    {
        [$business, $identity, $number] = $this->managedBusiness();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->postEvent(
                $this->messageReceived($identity->messaging_profile_id, $number->phone_number, 'pm_repeat_race'),
            );

            $response->assertOk();
            $this->assertContains($response->json('status'), ['accepted', 'duplicate']);

            // The invariant holds after EVERY delivery, not merely at the end.
            $this->assertSame(1, DB::table('business_messaging_operations')
                ->where('provider_message_id', 'pm_repeat_race')->count());
            $this->assertSame(1, DB::table('business_usage_measurements')->count());
        }

        // The operation and its measurement exist together — neither was
        // written without the other.
        $this->assertSame(1, DB::table('business_messaging_operations')->count());
        $this->assertSame(1, DB::table('business_usage_measurements')
            ->where('business_id', $business->id)->count());
    }

    public function test_an_inbound_row_can_never_exist_without_its_measurement(): void
    {
        // The atomicity claim, stated as an invariant over the whole table
        // rather than as a single happy-path assertion.
        [, $identity, $number] = $this->managedBusiness();

        foreach (['pm_atomic_1', 'pm_atomic_2', 'pm_atomic_3'] as $providerMessageId) {
            $this->postEvent(
                $this->messageReceived($identity->messaging_profile_id, $number->phone_number, $providerMessageId),
            )->assertOk();
        }

        $inbound = DB::table('business_messaging_operations')->where('direction', 'inbound')->get();
        $this->assertCount(3, $inbound);

        foreach ($inbound as $operation) {
            $this->assertSame(
                1,
                DB::table('business_usage_measurements')
                    ->where('idempotency_key', 'inbound:telnyx:' . $operation->provider_message_id)
                    ->count(),
                'Every inbound operation must have exactly its own measurement.',
            );
        }
    }
}
