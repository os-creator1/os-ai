<?php

namespace Tests\Feature\Conversations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Migration\Ghl\GhlImportPlanner;
use App\Library\Migration\Ghl\GhlImportWriter;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/** The real Conversations screen, signed in as the owner, showing GoHighLevel history: inbound/outbound, original times, provenance. */
class ImportedHistoryScreenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_the_conversations_screen_shows_imported_history_with_original_times_and_provenance_and_sends_nothing(): void
    {
        User::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa'.uniqid().'@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Harbor Lane');
        $customer->user->forceFill(['email_verified_at' => now()])->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();
        $this->actingAs($customer->user);
        $location = $business->primaryLocation()->first() ?? app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['name' => 'Main', 'service_mode' => 'service_area', 'address_line_1' => '1 Test St', 'city' => 'Denver', 'region' => 'CO', 'postal_code' => '80202', 'country_code' => 'US', 'public_address' => false]);

        $msg = fn (string $id, string $type, string $dir, string $at, array $x = []) => $x + ['id' => $id, 'messageType' => $type, 'direction' => $dir, 'dateAdded' => $at, 'body' => "text {$id}", 'contactId' => 'C1'];
        $plan = (new GhlImportPlanner())->plan([
            'location' => ['id' => 'L'],
            'contacts' => [['id' => 'C1', 'firstNameRaw' => 'Ann', 'lastNameRaw' => 'Lee', 'email' => 'ann@example.test', 'phone' => '(720) 555-0101', 'dateAdded' => '2026-01-02T00:00:00.000Z']],
            'conversations' => [['id' => 'V1', 'contactId' => 'C1']],
            'messages' => ['V1' => [
                $msg('M1', 'TYPE_SMS', 'outbound', '2026-03-01T17:00:00.000Z', ['body' => 'Hello from the old system']),
                $msg('M2', 'TYPE_SMS', 'inbound', '2026-03-01T17:05:00.000Z', ['body' => 'Can you do June 12?']),
                $msg('M3', 'TYPE_SMS', 'outbound', '2026-03-02T17:00:00.000Z', ['body' => 'Workflow text that never delivered', 'status' => 'failed']),
                $msg('M4', 'TYPE_EMAIL', 'outbound', '2026-03-03T17:00:00.000Z', ['body' => '<p>Your quote</p>', 'meta' => ['email' => ['subject' => 'Quote for June 12']]]),
                $msg('M5', 'TYPE_CALL', 'inbound', '2026-03-04T17:00:00.000Z', ['status' => 'completed', 'meta' => ['call' => ['duration' => 42]]]),
            ]],
        ]);

        Queue::fake();
        Mail::fake();
        Http::fake();
        Bus::fake();
        app(GhlImportWriter::class)->import($plan, $business, $location, '+17205028306', false);

        $box = ChatBox::query()->where('business_id', $business->id)->firstOrFail();

        $this->get(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]))->assertOk();

        $response = $this->postJson(route('customer.workspaces.businesses.conversations.timeline', [$workspace->uid, $business->uid, $box->uid]))
            ->assertOk()->assertJson(['status' => 'success', 'title' => 'Ann Lee']);
        $timeline = $response->json('timeline');

        $this->assertMatchesRegularExpression('/data-direction="outbound".*Hello from the old system/s', $timeline);
        $this->assertMatchesRegularExpression('/data-direction="inbound".*Can you do June 12\?/s', $timeline);
        $this->assertStringContainsString('Quote for June 12', $timeline);
        $this->assertStringContainsString('Imported from GoHighLevel · Email', $timeline);
        $this->assertStringContainsString('Imported from GoHighLevel · SMS', $timeline);
        $this->assertStringContainsString('Originally: failed', $timeline, 'A message GHL failed to deliver is labelled, not shown as delivered.');
        $this->assertStringContainsString('Inbound call — completed (42s)', $timeline);
        $this->assertStringNotContainsString('data-role="timeline-message-retry"', $timeline, 'Nothing imported can be retried or resent.');

        // Order is the original chronology.
        $this->assertLessThan(strpos($timeline, 'Can you do June 12?'), strpos($timeline, 'Hello from the old system'));
        $this->assertLessThan(strpos($timeline, 'Quote for June 12'), strpos($timeline, 'Can you do June 12?'));

        $panel = $response->json('context');
        $this->assertStringContainsString('Ann Lee', $panel);

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
    }
}
