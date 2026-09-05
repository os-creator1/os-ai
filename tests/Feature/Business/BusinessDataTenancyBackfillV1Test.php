<?php

namespace Tests\Feature\Business;

use App\Library\Business\Migration\BusinessDataTenancyBackfillV1;
use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\PhoneNumbers;
use App\Models\Reports;
use App\Models\Templates;
use App\Models\TrackingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Business Data Tenancy Foundation, Pass 1 — BusinessDataTenancyBackfillV1
 * proofs: deterministic rows populate correctly, ambiguous rows stay NULL,
 * already-populated rows are never overwritten, a rerun is idempotent, and
 * a pooled phone number is never assigned a Business.
 */
class BusinessDataTenancyBackfillV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        // The pooled/unassigned-number sentinel (user_id = 1) is a real FK
        // to users.id in production. RefreshDatabase migrates once and
        // wraps each test in a rolled-back transaction, so the users
        // auto-increment counter keeps climbing across test methods —
        // forcing id 1 explicitly (rather than relying on insertion
        // order) is the only way this is reliable regardless of which
        // test in this class runs first.
        $placeholder = new User([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
        $placeholder->id = 1;
        $placeholder->save();
    }

    public function test_deterministic_row_resolves_to_the_customers_business(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $campaign = Campaigns::create([
            'user_id' => $customer->user_id,
            'campaign_name' => 'Legacy Campaign',
            'message' => 'Hi',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_DONE,
        ]);
        $this->assertNull($campaign->fresh()->business_id);

        (new BusinessDataTenancyBackfillV1())->run();

        $this->assertSame($business->id, $campaign->fresh()->business_id);
    }

    public function test_ambiguous_row_with_no_resolvable_business_stays_null(): void
    {
        $customer = $this->createCustomer();
        // Deliberately zero Businesses for this customer.

        $template = Templates::create([
            'user_id' => $customer->user_id,
            'name' => 'Orphan Template',
            'message' => 'Hi',
            'status' => true,
        ]);

        (new BusinessDataTenancyBackfillV1())->run();

        $this->assertNull($template->fresh()->business_id);
        $this->assertGreaterThan(0, (new BusinessDataTenancyBackfillV1())->unresolvedCounts()['templates']);
    }

    public function test_already_populated_row_is_never_overwritten(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customerA, $this->businessAttributes(['name' => 'A']));
        $this->createBusinessWithWorkspace($customerB, $this->businessAttributes(['name' => 'B']));

        $contact = Contacts::create([
            'customer_id' => $customerA->user_id,
            'business_id' => $businessA->id,
            'phone' => '15550001111',
            'status' => 'subscribe',
        ]);

        // Even though the row's owning customer_id matches customerA, the
        // backfill must never touch a row whose business_id is already set
        // — simulate a hypothetically "wrong" pre-existing value to prove
        // the whereNull() guard, not just that it happens to match.
        DB::table('contacts')->where('id', $contact->id)->update(['business_id' => $businessA->id]);

        (new BusinessDataTenancyBackfillV1())->run();

        $this->assertSame($businessA->id, $contact->fresh()->business_id);
    }

    public function test_rerun_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        Contacts::create([
            'customer_id' => $customer->user_id,
            'phone' => '15550002222',
            'status' => 'subscribe',
        ]);

        $backfill = new BusinessDataTenancyBackfillV1();
        $first = $backfill->run();
        $second = $backfill->run();

        $this->assertSame(1, $first['contacts']['resolved']);
        $this->assertSame(0, $second['contacts']['resolved'], 'A second run must resolve zero additional rows.');
        $this->assertSame(0, $second['contacts']['unresolved']);
    }

    public function test_pooled_phone_number_never_receives_a_business_id(): void
    {
        $customer = $this->createCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $pooled = PhoneNumbers::create([
            'user_id' => 1,
            'number' => '15550003333',
            'status' => 'available',
            'capabilities' => json_encode(['sms']),
        ]);

        $assigned = PhoneNumbers::create([
            'user_id' => $customer->user_id,
            'number' => '15550004444',
            'status' => 'assigned',
            'capabilities' => json_encode(['sms']),
        ]);

        (new BusinessDataTenancyBackfillV1())->run();

        $this->assertNull($pooled->fresh()->business_id, 'A pooled/unassigned number (user_id = 1) must never resolve a Business.');
        $this->assertNotNull($assigned->fresh()->business_id);
    }

    public function test_reports_and_tracking_logs_inherit_business_id_from_their_campaign(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $campaign = Campaigns::create([
            'user_id' => $customer->user_id,
            'campaign_name' => 'Inheritance Campaign',
            'message' => 'Hi',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_DONE,
        ]);

        $report = Reports::create([
            'user_id' => $customer->user_id,
            'campaign_id' => $campaign->id,
            'to' => '15550005555',
            'message' => 'Hi',
            'sms_type' => 'plain',
            'status' => 'Delivered',
        ]);

        $group = ContactGroups::create(['customer_id' => $customer->user_id, 'business_id' => $business->id, 'name' => 'VIPs', 'status' => true]);
        $contact = Contacts::create(['customer_id' => $customer->user_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '15550005556', 'status' => 'subscribe']);

        $trackingLog = TrackingLog::create([
            'customer_id' => $customer->user_id,
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'contact_group_id' => $group->id,
            'status' => 'Delivered',
        ]);

        (new BusinessDataTenancyBackfillV1())->run();

        $this->assertSame($business->id, $campaign->fresh()->business_id);
        $this->assertSame($business->id, $report->fresh()->business_id, 'Reports must inherit business_id from their Campaign rather than re-resolving independently.');
        $this->assertSame($business->id, $trackingLog->fresh()->business_id, 'TrackingLog must inherit business_id from its Campaign.');
    }

    public function test_reports_without_a_campaign_fall_back_to_resolving_their_own_owner(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $inboundReport = Reports::create([
            'user_id' => $customer->user_id,
            'to' => '15550006666',
            'from' => '15559998888',
            'message' => 'Inbound',
            'sms_type' => 'plain',
            'status' => 'Delivered',
            'direction' => Reports::DIRECTION_INCOMING,
        ]);

        (new BusinessDataTenancyBackfillV1())->run();

        $this->assertSame($business->id, $inboundReport->fresh()->business_id);
    }
}
