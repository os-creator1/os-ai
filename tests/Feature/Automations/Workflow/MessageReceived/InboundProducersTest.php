<?php

namespace Tests\Feature\Automations\Workflow\MessageReceived;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Conversation\InboundMessageReceived;
use App\Events\MessageReceived as InboxBroadcast;
use App\Http\Controllers\Customer\DLRController;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\PhoneNumbers;
use App\Models\Reports;
use App\Models\SendingServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\MessageReceived\Support\BuildsInboundFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use Carbon\CarbonImmutable;

/**
 * Automations V2-F §9 — the producer, at both real inbound seams.
 *
 * The event is only as trustworthy as the attribution behind it, so these drive
 * the actual legacy callback and the actual managed webhook rather than
 * constructing events by hand, and prove the one rule that matters most: an
 * event exists only when the Business was PROVED, and it names that Business —
 * never a fallback guess.
 */
class InboundProducersTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use CreatesMessagingFixtures;
    use BuildsWorkflows;
    use BuildsInboundFixtures;

    /**
     * A Business whose own assigned number receives legacy inbound traffic.
     *
     * @return array{0: Business, 1: SendingServer, 2: PhoneNumbers}
     */
    private function legacyReceivingBusiness(?Business $business = null, string $number = self::BUSINESS_NUMBER): array
    {
        if ($business === null) {
            [, $business] = $this->entitledTenant();
        }

        $owner = User::query()->findOrFail($business->customer_id);

        $server = SendingServer::create([
            'name' => 'V2-F inbound ' . uniqid(),
            'user_id' => $owner->id,
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'two_way' => true,
            'plain' => true,
            'account_sid' => 'ACtest',
            'auth_token' => 'authtest',
        ]);

        $phone = PhoneNumbers::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'number' => $number,
            'status' => 'assigned',
            'capabilities' => json_encode(['sms']),
            'price' => 0,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'validity_date' => now()->addMonth(),
        ]);

        return [$business, $server, $phone];
    }

    // =================================================================
    // Legacy — DLRController::inboundDLR()
    // =================================================================

    public function test_the_legacy_path_emits_one_event_naming_the_receiving_numbers_business(): void
    {
        [$business, $server, $phone] = $this->legacyReceivingBusiness();

        Event::fake([InboundMessageReceived::class, InboxBroadcast::class]);

        DLRController::inboundDLR('14155554001', 'hello', $server, 0, $phone->number);

        $inbound = Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->sole();

        Event::assertDispatchedTimes(InboundMessageReceived::class, 1);
        Event::assertDispatched(InboundMessageReceived::class, fn (InboundMessageReceived $event) => $event->businessId === (int) $business->id
            && $event->senderPhone === '14155554001'
            && $event->occurrenceKey === 'report:' . $inbound->id
            && $event->inboundReportId === (int) $inbound->id);

        // The inbox broadcast is untouched: added beside, never instead of.
        Event::assertDispatched(InboxBroadcast::class);
    }

    public function test_an_unattributable_legacy_message_emits_no_domain_event(): void
    {
        [$business, $server, $phone] = $this->legacyReceivingBusiness();

        // The receiving number carries no Business: the conversation is kept
        // unfiled, and nothing may enroll off it.
        DB::table('phone_numbers')->where('id', $phone->id)->update(['business_id' => null]);

        Event::fake([InboundMessageReceived::class, InboxBroadcast::class]);

        DLRController::inboundDLR('14155554002', 'hello', $server, 0, $phone->number);

        Event::assertNotDispatched(InboundMessageReceived::class);

        // The existing inbox behaviour is unchanged.
        Event::assertDispatched(InboxBroadcast::class);
    }

    public function test_a_number_carrying_another_customers_business_emits_nothing(): void
    {
        [$business, $server, $phone] = $this->legacyReceivingBusiness();
        [, $foreign] = $this->entitledTenant();

        DB::table('phone_numbers')->where('id', $phone->id)->update(['business_id' => $foreign->id]);

        Event::fake([InboundMessageReceived::class]);

        DLRController::inboundDLR('14155554003', 'hello', $server, 0, $phone->number);

        Event::assertNotDispatched(InboundMessageReceived::class);
    }

    public function test_a_multi_business_customer_is_attributed_by_the_number_not_the_primary_business(): void
    {
        [$customer, $primary] = $this->entitledTenant();
        $secondary = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('businesses')->where('id', $primary->id)->update(['is_primary' => true]);
        DB::table('businesses')->where('id', $secondary->id)->update(['is_primary' => false]);

        // The SECONDARY Business owns the number that was messaged.
        [, $server, $phone] = $this->legacyReceivingBusiness($secondary->fresh());

        Event::fake([InboundMessageReceived::class]);

        DLRController::inboundDLR('14155554004', 'hello', $server, 0, $phone->number);

        $inbound = Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->sole();

        // The legacy Reports row still guesses the primary Business...
        $this->assertSame((int) $primary->id, (int) $inbound->business_id);

        // ...and the domain event does not inherit that guess.
        Event::assertDispatched(InboundMessageReceived::class, fn (InboundMessageReceived $event) => $event->businessId === (int) $secondary->id);
    }

    public function test_a_legacy_inbound_message_enrolls_end_to_end(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);

        [$business, $server, $phone] = $this->legacyReceivingBusiness();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155554005');

        // No event fake: the real after-commit event, the real queued listener
        // (sync in tests) and the real source.
        DLRController::inboundDLR('14155554005', 'is anyone there?', $server, 0, $phone->number);

        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->id)->sole();
        $this->assertSame((int) $contact->id, (int) $enrollment->contact_id);
        $this->assertSame(WorkflowTriggerType::MessageReceived, $enrollment->trigger_type);
    }

    // =================================================================
    // Managed — InboundWebhookAttributionResolver::persistInbound()
    // =================================================================

    private function managedMessage(string $profileId, string $destination, string $from, string $providerMessageId): InboundWebhookEvent
    {
        return new InboundWebhookEvent(
            kind: InboundWebhookEventKind::MessageReceived,
            messagingProfileId: $profileId,
            destinationNumber: $destination,
            fromNumber: $from,
            body: 'managed inbound',
            mediaUrls: [],
            providerMessageId: $providerMessageId,
            deliveryStatus: null,
            occurredAt: CarbonImmutable::now(),
        );
    }

    public function test_the_managed_path_emits_one_event_after_dual_signal_attribution(): void
    {
        $this->bindFakeAdapter();
        [$business, $identity, $number] = $this->managedBusiness();

        Event::fake([InboundMessageReceived::class]);

        $this->fakeAdapter->queueInboundWebhook($this->managedMessage($identity->messaging_profile_id, $number->phone_number, '+14155554006', 'pm_v2f_1'));
        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk()->assertJson(['status' => 'accepted']);

        $operation = DB::table('business_messaging_operations')->where('direction', 'inbound')->sole();

        Event::assertDispatchedTimes(InboundMessageReceived::class, 1);
        Event::assertDispatched(InboundMessageReceived::class, fn (InboundMessageReceived $event) => $event->businessId === (int) $business->id
            && $event->senderPhone === '+14155554006'
            && $event->occurrenceKey === 'operation:' . $operation->id
            && $event->inboundReportId === null);
    }

    public function test_a_duplicate_managed_delivery_emits_nothing_the_second_time(): void
    {
        $this->bindFakeAdapter();
        [, $identity, $number] = $this->managedBusiness();

        Event::fake([InboundMessageReceived::class]);

        foreach ([1, 2] as $_) {
            $this->fakeAdapter->queueInboundWebhook($this->managedMessage($identity->messaging_profile_id, $number->phone_number, '+14155554007', 'pm_v2f_dup'));
            $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();
        }

        Event::assertDispatchedTimes(InboundMessageReceived::class, 1);
    }

    public function test_an_unattributed_managed_message_emits_nothing(): void
    {
        $this->bindFakeAdapter();
        [, $identity] = $this->managedBusiness();

        Event::fake([InboundMessageReceived::class]);

        // Known profile, unknown number: the resolver fails closed.
        $this->fakeAdapter->queueInboundWebhook($this->managedMessage($identity->messaging_profile_id, '+14155559999', '+14155554008', 'pm_v2f_unknown'));
        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();

        Event::assertNotDispatched(InboundMessageReceived::class);
    }
}
