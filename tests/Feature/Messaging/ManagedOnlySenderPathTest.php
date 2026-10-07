<?php

namespace Tests\Feature\Messaging;

use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Messaging\BusinessSmsSendingPath;
use App\Library\Messaging\DTO\LocationSendContext;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\Senderid;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * V1 messaging final acceptance — a Business whose ONLY sender is the managed
 * number it bought in Settings -> Text messaging (provisioning writes an
 * identity and a number, never a Senderid or a phone_numbers row) must still
 * resolve a sending path, or Automations, Booking and Document texts refuse to
 * send for exactly the Businesses V1 is built for. Every other suite hid this
 * by also giving the Business a legacy Senderid.
 */
class ManagedOnlySenderPathTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use CreatesMessagingFixtures;
    use BuildsWorkflows;
    use BuildsActionWorkflows;

    private const NUMBER = '+14155550199';

    private const PERSON = '14155552671';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->bindFakeAdapter();

        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
    }

    /** An entitled, sendable Business whose legacy sender is REMOVED: managed number only. */
    private function managedOnlyTenant(string $number = self::NUMBER)
    {
        [$customer, $business] = $this->entitledTenant();
        $this->sendableChannel($business); // plan + subscription + coverage the send core needs…
        Senderid::query()->where('business_id', $business->id)->delete(); // …but NOT a legacy sender
        CustomerBasedSendingServer::query()->where('business_id', $business->id)->delete();

        $this->attachNumber($this->attachIdentity($business), $number, true);

        $customer->user->sms_unit = 1000;
        $customer->user->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        return $business->fresh();
    }

    public function test_a_managed_only_business_resolves_a_sending_path(): void
    {
        $business = $this->managedOnlyTenant();

        $this->assertSame(0, Senderid::query()->where('business_id', $business->id)->count(), 'Precondition: no legacy sender exists.');

        $path = app(BusinessSmsSendingPath::class)->resolve($business);

        $this->assertIsArray($path, 'A managed number is a sending path.');
        $this->assertSame(self::NUMBER, $path['originator']);
        $this->assertNull($path['sending_server'], 'Managed sends never carry a legacy server.');
    }

    public function test_a_managed_only_business_resolves_a_location_aware_sending_path(): void
    {
        $business = $this->managedOnlyTenant();

        $path = app(BusinessSmsSendingPath::class)->resolveForLocation($business, new LocationSendContext(null, false));

        $this->assertIsArray($path, 'Not "no_business_sending_path".');
        $this->assertNull($path['sending_server']);
    }

    public function test_a_business_with_no_sender_of_any_kind_still_fails_closed(): void
    {
        [, $business] = $this->entitledTenant();

        $this->assertNull(app(BusinessSmsSendingPath::class)->resolve($business));
        $this->assertSame('no_business_sending_path', app(BusinessSmsSendingPath::class)->resolveForLocation($business, new LocationSendContext(null, false)));
    }

    public function test_an_automation_text_reaches_the_provider_and_the_conversation_for_a_managed_only_business(): void
    {
        $business = $this->managedOnlyTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->smsStep('Thanks for booking!'), $this->endStep()], name: 'Thanks');
        $contact = $this->contact($business, $this->contactGroup($business), self::PERSON);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame('succeeded', DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->where('node_type', 'send_sms')->value('status'));
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        Http::assertNothingSent();

        $box = ChatBox::query()->where('business_id', $business->id)->where('to', self::PERSON)->sole();
        $this->assertSame('Thanks for booking!', DB::table('chat_box_messages')->where('box_id', $box->id)->sole()->message);
    }

    public function test_another_businesses_managed_number_is_never_authorized_as_this_businesss_sender(): void
    {
        $mine = $this->managedOnlyTenant('+14155550199');
        $theirs = $this->managedOnlyTenant('+14155550288');

        $validate = fn ($business, string $number) => app(CampaignRepository::class)->checkQuickSendValidation([
            'business_id' => (int) $business->id,
            'user_id' => (int) $business->customer_id,
            'message' => 'hi', 'sms_type' => 'plain', 'originator' => 'sender_id', 'sender_id' => $number,
        ])->getData();

        $this->assertSame('success', $validate($mine, '+14155550199')->status, 'Its own managed number is accepted.');
        $this->assertSame('error', $validate($mine, '+14155550288')->status, 'Another Business\'s managed number is refused.');
        $this->assertSame('success', $validate($theirs, '+14155550288')->status);
    }
}
