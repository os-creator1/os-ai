<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Assistant\AssistantActionService;
use App\Library\Assistant\AssistantContext;
use App\Models\AssistantActionProposal;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * PR #460 review (round 3), P2: the preview of `create_appointment` promised an email / text confirmation whenever the booking type
 * had those channels switched on. The assistant's authenticated booking path schedules NO customer message (only the public booking
 * flow does), so the preview must describe what really happens: the appointment is booked, nobody is contacted automatically.
 *
 * For each notification configuration (email only, text only, both, neither) the preview is read from the real propose flow, and the
 * approval is then run to prove that what it said is what happened.
 */
class AssistantAppointmentPreviewTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    /** @return array<string, array{bool, bool}> */
    public static function configurations(): array
    {
        return [
            'email only' => [true, false],
            'text only' => [false, true],
            'email and text' => [true, true],
            'neither' => [false, false],
        ];
    }

    /** @dataProvider configurations */
    public function test_the_preview_never_promises_a_customer_message_and_matches_what_approval_does(bool $email, bool $sms): void
    {
        Mail::fake();
        Notification::fake();
        $this->bootAssistant();

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Preview Venue', 'Preview Account');
        $this->authenticateAs($customer);
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        DB::table('contacts')->insert([
            'uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'location_id' => $location->id, 'group_id' => $this->contactGroup($business)->id, 'phone' => '15557770000', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $types = DB::table('booking_types')->where('business_location_id', $location->id);
        $this->assertGreaterThan(0, (clone $types)->count());
        $types->update(['notify_email' => $email, 'notify_sms' => $sms]);

        $this->modelSays(['action' => 'propose', 'tool' => 'create_appointment', 'args' => ['contact' => '15557770000', 'date' => '2026-09-14', 'time' => '10:00'], 'message' => 'Here is the booking.']);
        $reply = $this->lastReply($this->say($workspace, $business, 'Book an appointment for Monday at 10 for 15557770000')->assertOk());

        $preview = $reply['proposal']['preview'] ?? $reply['proposal'];
        $effects = implode("\n", $preview['effects']);

        $this->assertSame([], $preview['recipients'], 'Nobody is contacted by this action, so nobody is listed as a recipient.');
        $this->assertStringContainsString('added to your calendar', $effects, 'The booking itself is still described.');
        $this->assertDoesNotMatchRegularExpression('/(sends|will (get|receive|be sent)) (the customer )?(a |an )?(confirmation|email|text)/i', str_replace('will NOT be sent', '', $effects), 'No promise of a confirmation.');
        $this->assertStringNotContainsString('by email', $effects);
        $this->assertStringNotContainsString('text message', $effects);

        if ($email || $sms) {
            $this->assertStringContainsString('not for bookings made here', $effects);
            $this->assertStringContainsString('will NOT be sent a confirmation or reminder automatically', $effects);
        } else {
            $this->assertStringContainsString('No confirmation or reminder is sent to the customer by this action.', $effects);
        }

        // Preview == reality: approving books the appointment and contacts nobody.
        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $customerContext = $shell->currentContext($customer->user);
        $entitlements = $shell->currentMenuEntitlements($customerContext);
        $ctx = new AssistantContext($workspace, $business, $customer->user, $customerContext, $entitlements,
            app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($customerContext, $customer->user, $entitlements, [$workspace->uid, $business->uid], $location->uid),
            [$workspace->uid, $business->uid]);
        $proposal = AssistantActionProposal::query()->where('uid', $reply['proposal']['uid'])->firstOrFail();

        $this->assertSame('completed', app(AssistantActionService::class)->approve($ctx, $proposal->uid)['state']);
        $this->assertSame(1, DB::table('appointments')->count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('appointment_notifications')->count());
    }
}
