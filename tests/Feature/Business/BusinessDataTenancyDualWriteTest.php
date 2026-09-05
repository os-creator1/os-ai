<?php

namespace Tests\Feature\Business;

use App\Library\Business\LegacyBusinessResolver;
use App\Models\Blacklists;
use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\Country;
use App\Models\Currency;
use App\Models\CustomerBasedSendingServer;
use App\Models\Keywords;
use App\Models\PhoneNumbers;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Reports;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Subscription;
use App\Models\Templates;
use App\Models\TrackingLog;
use App\Models\User;
use App\Repositories\Contracts\BlacklistsRepository;
use App\Repositories\Contracts\CampaignRepository;
use App\Repositories\Contracts\ContactsRepository;
use App\Repositories\Contracts\KeywordRepository;
use App\Repositories\Contracts\PhoneNumberRepository;
use App\Repositories\Contracts\SenderIDRepository;
use App\Repositories\Contracts\TemplatesRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Business Data Tenancy Foundation, Pass 1 — proves the actual production
 * write paths (not just the resolver in isolation) populate business_id
 * for each of the 11 authorized domains. Reads are untouched in this pass
 * (Pass 2's job) — every assertion here is on the written row only.
 */
class BusinessDataTenancyDualWriteTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        // The pooled/unassigned sentinel (user_id = 1, shared by
        // phone_numbers and keywords) is a real FK to users.id; force id 1
        // explicitly since RefreshDatabase's auto-increment counter is not
        // reset per test method.
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

    public function test_template_store_populates_business_id(): void
    {
        [$customer, $business] = $this->tenant();

        $template = app(TemplatesRepository::class)->store([
            'name' => 'Welcome',
            'message' => 'Hi there',
            'user_id' => $customer->user_id,
            'user_type' => 'customer',
        ]);

        $this->assertSame($business->id, $template->fresh()->business_id);
    }

    public function test_contact_group_store_populates_business_id(): void
    {
        [$customer, $business] = $this->tenant();

        $group = app(ContactsRepository::class)->store([
            'name' => 'VIPs',
            'user_id' => $customer->user_id,
        ]);

        $this->assertSame($business->id, $group->fresh()->business_id);
    }

    public function test_contact_store_inherits_business_id_from_its_group(): void
    {
        [$customer, $business] = $this->tenant();
        $group = ContactGroups::create(['customer_id' => $customer->user_id, 'business_id' => $business->id, 'name' => 'VIPs', 'status' => true]);

        $response = app(ContactsRepository::class)->storeContact($group, [
            'phone' => '14155552680',
            'first_name' => 'A',
            'last_name' => 'B',
        ]);

        $this->assertSame('success', $response->getData()->status ?? null);
        $contact = \App\Models\Contacts::where('phone', '14155552680')->first();
        $this->assertNotNull($contact);
        $this->assertSame($business->id, $contact->business_id);
    }

    public function test_blacklist_store_populates_business_id(): void
    {
        [$customer, $business] = $this->tenant();
        $this->actingAs($customer->user);

        app(BlacklistsRepository::class)->store([
            'number' => '15550008888',
            'delimiter' => ',',
            'reason' => 'test',
        ]);

        $blacklist = Blacklists::where('number', '15550008888')->first();
        $this->assertNotNull($blacklist);
        $this->assertSame($business->id, $blacklist->business_id);
    }

    public function test_assigned_phone_number_store_populates_business_id(): void
    {
        [$customer, $business] = $this->tenant();

        $number = app(PhoneNumberRepository::class)->store([
            'user_id' => $customer->user_id,
            'number' => '15550009999',
            'status' => 'assigned',
            'capabilities' => ['sms'],
            'price' => '0',
            'billing_cycle' => 'custom',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
        ], []);

        $this->assertSame($business->id, $number->fresh()->business_id);
    }

    public function test_released_phone_number_clears_business_id(): void
    {
        [$customer, $business] = $this->tenant();
        $this->actingAs($customer->user);

        $number = PhoneNumbers::create([
            'user_id' => $customer->user_id,
            'business_id' => $business->id,
            'number' => '15550010000',
            'status' => 'assigned',
            'capabilities' => json_encode(['sms']),
        ]);

        app(PhoneNumberRepository::class)->release($number, $number->uid);

        $this->assertNull($number->fresh()->business_id, 'Releasing a number back to the pool must clear business_id.');
        $this->assertSame(1, $number->fresh()->user_id);
    }

    public function test_sender_id_bulk_assignment_populates_business_id(): void
    {
        [$customer, $business] = $this->tenant();

        app(SenderIDRepository::class)->store([
            'user_id' => [$customer->user_id],
            'sender_id' => 'TESTID',
            'status' => 'active',
            'price' => '0',
            'billing_cycle' => 'custom',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
        ], []);

        $senderid = Senderid::where('sender_id', 'TESTID')->where('user_id', $customer->user_id)->first();
        $this->assertNotNull($senderid);
        $this->assertSame($business->id, $senderid->business_id);
    }

    public function test_customer_based_sending_server_creation_populates_business_id(): void
    {
        // Mirrors Admin\CustomerController@updateCustomerBasedSendingServer's
        // exact CustomerBasedSendingServer::create() shape (no dedicated
        // repository exists for this table).
        [$customer, $business] = $this->tenant();
        $server = SendingServer::create(['name' => 'Test Gateway', 'settings' => 'twilio', 'status' => true, 'plain' => true]);

        $row = CustomerBasedSendingServer::create([
            'user_id' => $customer->user_id,
            'business_id' => app(LegacyBusinessResolver::class)->resolveForCustomer((int) $customer->user_id)?->id,
            'sending_server' => $server->id,
        ]);

        $this->assertSame($business->id, $row->fresh()->business_id);
    }

    public function test_keyword_release_clears_business_id(): void
    {
        [$customer, $business] = $this->tenant();
        $this->actingAs($customer->user);

        $keyword = Keywords::create([
            'user_id' => $customer->user_id,
            'business_id' => $business->id,
            'title' => 'HELLO',
            'keyword_name' => 'hello',
            'status' => 'assigned',
        ]);

        app(KeywordRepository::class)->release($keyword, $keyword->uid);

        $this->assertNull($keyword->fresh()->business_id);
        $this->assertSame(1, $keyword->fresh()->user_id);
    }

    public function test_keyword_update_reassignment_populates_business_id(): void
    {
        [$customer, $business] = $this->tenant();

        $keyword = Keywords::create([
            'user_id' => 1,
            'business_id' => null,
            'title' => 'HELLO',
            'keyword_name' => 'hello',
            'status' => 'available',
        ]);

        app(KeywordRepository::class)->update($keyword, [
            'user_id' => $customer->user_id,
            'status' => 'assigned',
        ], []);

        $this->assertSame($business->id, $keyword->fresh()->business_id);
    }

    public function test_campaign_builder_populates_business_id_on_the_created_campaign(): void
    {
        [$customer, $business, $fixture] = $this->sendableTenant();
        $this->actingAs($customer->user);
        $group = ContactGroups::create(['customer_id' => $customer->user_id, 'business_id' => $business->id, 'name' => 'VIPs', 'status' => true]);
        \App\Models\Contacts::create(['customer_id' => $customer->user_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '4155552671', 'status' => 'subscribe']);
        Senderid::create(['user_id' => $customer->user_id, 'business_id' => $business->id, 'sender_id' => 'TESTSENDER', 'status' => 'active']);

        $result = app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Dual Write Campaign',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'originator' => 'sender_id',
            'sender_id' => ['TESTSENDER'],
            'plan_id' => $fixture['plan']->id,
        ]);

        $campaign = Campaigns::where('campaign_name', 'Dual Write Campaign')->first();
        $this->assertNotNull($campaign, 'campaignBuilder() must have created the campaign before any later eligibility check runs.');
        $this->assertSame($business->id, $campaign->business_id);
    }

    public function test_report_and_tracking_log_inherit_business_id_from_the_campaign_they_belong_to(): void
    {
        [$customer, $business] = $this->tenant();
        $campaign = Campaigns::create([
            'user_id' => $customer->user_id,
            'business_id' => $business->id,
            'campaign_name' => 'Parent',
            'message' => 'Hi',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_SENDING,
        ]);
        $campaign->setRelation('user', $customer->user);
        $server = SendingServer::create(['name' => 'Test Gateway', 'settings' => 'twilio', 'status' => true, 'plain' => true]);
        $group = ContactGroups::create(['customer_id' => $customer->user_id, 'business_id' => $business->id, 'name' => 'VIPs', 'status' => true]);
        $subscriber = \App\Models\Contacts::create(['customer_id' => $customer->user_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '15550005557', 'status' => 'subscribe']);

        $response = new Reports();
        $response->id = 'ext-1';
        $response->status = 'Delivered';
        $response->sms_count = 1;
        $response->cost = 0;

        $campaign->track_message($response, $subscriber, $server);

        $log = TrackingLog::where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($business->id, $log->business_id, 'track_message() must write the parent Campaign\'s business_id onto the TrackingLog row.');
    }

    /**
     * @return array{0: \App\Models\Customer, 1: \App\Models\Business}
     */
    private function tenant(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        return [$customer, $business];
    }

    /**
     * @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: array}
     */
    private function sendableTenant(): array
    {
        [$customer, $business] = $this->tenant();

        $country = Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Dual Write Test Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
        ]);

        PlansCoverageCountries::create([
            'plan_id' => $plan->id,
            'country_id' => $country->id,
            'status' => true,
            'options' => json_encode(['plain' => true, 'mms' => true, 'plain_sms' => 0.05, 'mms_sms' => 0.10]),
        ]);

        Subscription::create([
            'user_id' => $customer->user_id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);

        $customer->user->sms_unit = 1000;
        $customer->user->save();

        return [$customer, $business, ['country' => $country, 'plan' => $plan]];
    }
}
