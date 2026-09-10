<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Messaging\MessagingProvider;
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

    public function test_an_unknown_provider_message_id_fails_closed(): void
    {
        $this->managedBusiness();

        $this->postEvent($this->deliveryStatus('pm_never_seen', 'delivered'))
            ->assertOk()
            ->assertJson(['status' => 'unattributed']);

        $this->assertSame(1, $this->rejectionCount('unknown_mapping'));
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
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
}
