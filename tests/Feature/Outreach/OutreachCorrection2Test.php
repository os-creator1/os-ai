<?php

namespace Tests\Feature\Outreach;

use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\PhoneNumbers;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Senderid;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * B1 Business-Scoped Outreach / CRM — Correction 2: complete originator
 * tamper closure.
 *
 * Correction 1 fixed several sender/phone-number/sending-server tamper
 * paths, but human mechanical re-review found that validateCampaignBuilder()
 * and checkQuickSendValidation() still had branches where, for an explicit
 * selected Business, a submitted SenderID/PhoneNumber was never positively
 * proven to belong to that Business:
 *
 *  1. validateCampaignBuilder()'s two phone_number branches (sender_id_
 *     verification == 'yes', and view_numbers + phone_number) scanned a
 *     Business-scoped cursor for CAPABILITY mismatches only — a foreign
 *     number that doesn't exist in the Business simply never appears in
 *     the cursor and is silently accepted.
 *  2. validateCampaignBuilder()'s final fallback branch accepted a
 *     submitted sender_id/phone_number with no ownership check at all.
 *  3. checkQuickSendValidation()'s final `else if (isset($input['sender_id']))`
 *     fallback did the same.
 *
 * This file proves all three are now closed via two small, reusable,
 * Business-aware validation helpers, while every legacy (no explicit
 * Business) path keeps its original behavior unchanged.
 */
class OutreachCorrection2Test extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Campaign Builder, sender_id_verification == 'yes': a foreign
    // Business's PhoneNumber must be rejected, not silently accepted
    // because it never appeared in the capability scan.
    // -----------------------------------------------------------------

    public function test_campaign_builder_verified_phone_number_rejects_a_foreign_business_number(): void
    {
        [$tenant, $businessA, $fixture] = $this->sendableTenant();
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        PhoneNumbers::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'number' => '15550002222', 'status' => 'assigned', 'capabilities' => json_encode(['sms'])]);
        $group = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $businessA->id, 'name' => 'A Group', 'status' => true]);

        $result = app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Verified Phone Tamper',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'message' => 'Hello',
            'originator' => 'phone_number',
            'phone_number' => ['15550002222'],
            'plan_id' => $fixture['plan']->id,
            'business_id' => $businessA->id,
            'user_id' => $tenant->user_id,
        ]);

        $this->assertSame('error', $result->getData()->status);
        $this->assertDatabaseMissing('campaigns', ['campaign_name' => 'Verified Phone Tamper']);
    }

    // -----------------------------------------------------------------
    // Positive control — Business A's own phone number still passes the
    // sender_id_verification == 'yes' branch.
    // -----------------------------------------------------------------

    public function test_campaign_builder_verified_phone_number_accepts_the_selected_businesss_own_number(): void
    {
        [$tenant, $businessA, $fixture] = $this->sendableTenant();

        PhoneNumbers::create(['user_id' => $tenant->user_id, 'business_id' => $businessA->id, 'number' => '15550001111', 'status' => 'assigned', 'capabilities' => json_encode(['sms'])]);
        $group = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $businessA->id, 'name' => 'A Group', 'status' => true]);

        $result = app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Verified Phone Own',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'message' => 'Hello',
            'originator' => 'phone_number',
            'phone_number' => ['15550001111'],
            'plan_id' => $fixture['plan']->id,
            'business_id' => $businessA->id,
            'user_id' => $tenant->user_id,
        ]);

        // No contacts exist in the group yet, so the response fails
        // later at "contact not found" rather than sender/number
        // validation — proving the originator check itself passed.
        $this->assertNotSame(__('locale.sender_id.sender_id_invalid', ['sender_id' => '15550001111']), $result->getData()->message);
    }

    // -----------------------------------------------------------------
    // 2. Campaign Builder, sender_id_verification != 'yes' + view_numbers:
    // a foreign Business's PhoneNumber must be rejected.
    // -----------------------------------------------------------------

    public function test_campaign_builder_view_numbers_phone_number_rejects_a_foreign_business_number(): void
    {
        [$tenant, $businessA, $fixture] = $this->sendableTenant(['sender_id_verification' => 'no']);
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        PhoneNumbers::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'number' => '15550003333', 'status' => 'assigned', 'capabilities' => json_encode(['sms'])]);

        // AccountRepository::hasPermission() reads Session::get('permissions')
        // first, regardless of which $user object is passed — matching
        // this app's existing customer-permission session convention.
        $this->withSession(['permissions' => collect(['view_numbers'])]);

        $result = app(CampaignRepository::class)->validateCampaignBuilder($tenant->user, [
            'sms_type' => 'plain',
            'originator' => 'phone_number',
            'phone_number' => ['15550003333'],
            'business_id' => $businessA->id,
        ]);

        $this->assertSame('error', $result->getData()->status);
    }

    // -----------------------------------------------------------------
    // Positive control — Business A's own phone number passes the
    // view_numbers branch.
    // -----------------------------------------------------------------

    public function test_campaign_builder_view_numbers_phone_number_accepts_the_selected_businesss_own_number(): void
    {
        [$tenant, $businessA] = $this->sendableTenant(['sender_id_verification' => 'no']);
        PhoneNumbers::create(['user_id' => $tenant->user_id, 'business_id' => $businessA->id, 'number' => '15550004444', 'status' => 'assigned', 'capabilities' => json_encode(['sms'])]);

        $this->withSession(['permissions' => collect(['view_numbers'])]);

        $result = app(CampaignRepository::class)->validateCampaignBuilder($tenant->user, [
            'sms_type' => 'plain',
            'originator' => 'phone_number',
            'phone_number' => ['15550004444'],
            'business_id' => $businessA->id,
        ]);

        $this->assertSame('success', $result->getData()->status);
    }

    // -----------------------------------------------------------------
    // 3. Campaign Builder fallback branch (neither sender_id_verification
    // == 'yes' nor view_numbers + phone_number applies): a foreign
    // Business's SenderID must be rejected.
    // -----------------------------------------------------------------

    public function test_campaign_builder_fallback_rejects_a_foreign_business_sender_id(): void
    {
        [$tenant, $businessA] = $this->sendableTenant(['sender_id_verification' => 'no']);
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        Senderid::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'sender_id' => 'ONLY_B', 'status' => 'active']);

        $result = app(CampaignRepository::class)->validateCampaignBuilder($tenant->user, [
            'sms_type' => 'plain',
            'originator' => 'sender_id',
            'sender_id' => ['ONLY_B'],
            'business_id' => $businessA->id,
        ]);

        $this->assertSame('error', $result->getData()->status);
    }

    // -----------------------------------------------------------------
    // 4. Campaign Builder fallback branch: a foreign Business's
    // PhoneNumber must be rejected.
    // -----------------------------------------------------------------

    public function test_campaign_builder_fallback_rejects_a_foreign_business_phone_number(): void
    {
        [$tenant, $businessA] = $this->sendableTenant(['sender_id_verification' => 'no']);
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        PhoneNumbers::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'number' => '15550005555', 'status' => 'assigned', 'capabilities' => json_encode(['sms'])]);

        $result = app(CampaignRepository::class)->validateCampaignBuilder($tenant->user, [
            'sms_type' => 'plain',
            'originator' => 'phone_number',
            'phone_number' => ['15550005555'],
            'business_id' => $businessA->id,
        ]);

        $this->assertSame('error', $result->getData()->status);
    }

    // -----------------------------------------------------------------
    // Positive control — Business A's own SenderID/PhoneNumber still
    // pass the fallback branch.
    // -----------------------------------------------------------------

    public function test_campaign_builder_fallback_accepts_the_selected_businesss_own_resources(): void
    {
        [$tenant, $businessA] = $this->sendableTenant(['sender_id_verification' => 'no']);
        Senderid::create(['user_id' => $tenant->user_id, 'business_id' => $businessA->id, 'sender_id' => 'OWN_A', 'status' => 'active']);
        PhoneNumbers::create(['user_id' => $tenant->user_id, 'business_id' => $businessA->id, 'number' => '15550006666', 'status' => 'assigned', 'capabilities' => json_encode(['sms'])]);

        $senderResult = app(CampaignRepository::class)->validateCampaignBuilder($tenant->user, [
            'sms_type' => 'plain',
            'originator' => 'sender_id',
            'sender_id' => ['OWN_A'],
            'business_id' => $businessA->id,
        ]);
        $this->assertSame('success', $senderResult->getData()->status);

        $numberResult = app(CampaignRepository::class)->validateCampaignBuilder($tenant->user, [
            'sms_type' => 'plain',
            'originator' => 'phone_number',
            'phone_number' => ['15550006666'],
            'business_id' => $businessA->id,
        ]);
        $this->assertSame('success', $numberResult->getData()->status);
    }

    // -----------------------------------------------------------------
    // 5. Quick Send fallback branch (checkQuickSendValidation()'s final
    // `else if (isset($input['sender_id']))`): a foreign Business's
    // SenderID must be rejected.
    // -----------------------------------------------------------------

    public function test_quick_send_fallback_rejects_a_foreign_business_sender_id(): void
    {
        [$tenant, $businessA] = $this->sendableTenant(['sender_id_verification' => 'no']);
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        Senderid::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'sender_id' => 'QS_ONLY_B', 'status' => 'active']);

        $result = app(CampaignRepository::class)->checkQuickSendValidation([
            'sms_type' => 'plain',
            'message' => 'Hello',
            'sender_id' => 'QS_ONLY_B',
            'business_id' => $businessA->id,
            'user_id' => $tenant->user_id,
        ]);

        $this->assertSame('error', $result->getData()->status);
    }

    // -----------------------------------------------------------------
    // Positive control — Business A's own SenderID still passes the
    // Quick Send fallback branch.
    // -----------------------------------------------------------------

    public function test_quick_send_fallback_accepts_the_selected_businesss_own_sender_id(): void
    {
        [$tenant, $businessA] = $this->sendableTenant(['sender_id_verification' => 'no']);
        Senderid::create(['user_id' => $tenant->user_id, 'business_id' => $businessA->id, 'sender_id' => 'QS_OWN_A', 'status' => 'active']);

        $result = app(CampaignRepository::class)->checkQuickSendValidation([
            'sms_type' => 'plain',
            'message' => 'Hello',
            'sender_id' => 'QS_OWN_A',
            'business_id' => $businessA->id,
            'user_id' => $tenant->user_id,
        ]);

        $this->assertSame('success', $result->getData()->status);
    }

    // -----------------------------------------------------------------
    // 7. Legacy (no explicit Business) behavior is unchanged: the
    // fallback branches keep accepting a submitted value without an
    // ownership check, exactly as before this correction.
    // -----------------------------------------------------------------

    public function test_legacy_campaign_builder_fallback_keeps_accepting_unverified_sender_id(): void
    {
        [$tenant] = $this->sendableTenant(['sender_id_verification' => 'no']);

        $result = app(CampaignRepository::class)->validateCampaignBuilder($tenant->user, [
            'sms_type' => 'plain',
            'originator' => 'sender_id',
            'sender_id' => ['ANY_UNVERIFIED_SENDER'],
        ]);

        $this->assertSame('success', $result->getData()->status);
    }

    public function test_legacy_quick_send_fallback_keeps_accepting_an_unverified_sender_id(): void
    {
        [$tenant] = $this->sendableTenant(['sender_id_verification' => 'no']);
        $this->actingAs($tenant->user);

        $result = app(CampaignRepository::class)->checkQuickSendValidation([
            'sms_type' => 'plain',
            'message' => 'Hello',
            'sender_id' => 'ANY_UNVERIFIED_SENDER',
        ]);

        $this->assertSame('success', $result->getData()->status);
    }

    public function test_legacy_campaign_builder_verified_phone_number_still_scopes_by_user_id_only(): void
    {
        [$tenant] = $this->sendableTenant();
        [$otherTenant] = $this->sendableTenant();

        PhoneNumbers::create(['user_id' => $otherTenant->user_id, 'number' => '15550007777', 'status' => 'assigned', 'capabilities' => json_encode(['sms'])]);
        PhoneNumbers::create(['user_id' => $tenant->user_id, 'number' => '15550008888', 'status' => 'assigned', 'capabilities' => json_encode(['sms'])]);

        // Legacy behavior (pre-Correction-2, unchanged): the capability
        // scan is scoped by user_id and a foreign user's number simply
        // never matches — the ORIGINAL, still-current legacy semantics.
        $result = app(CampaignRepository::class)->validateCampaignBuilder($tenant->user, [
            'sms_type' => 'plain',
            'originator' => 'phone_number',
            'phone_number' => ['15550008888'],
        ]);

        $this->assertSame('success', $result->getData()->status);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Business, 2: array{country: Country, plan: Plan}}
     */
    private function sendableTenant(array $planOptionOverrides = []): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());

        $fixture = $this->givePlanAndSubscription($tenant, $planOptionOverrides);
        $tenant->user->sms_unit = 1000;
        $tenant->user->save();

        return [$tenant, $business, $fixture];
    }

    /**
     * @return array{country: Country, plan: Plan}
     */
    private function givePlanAndSubscription(Customer $customer, array $planOptionOverrides = []): array
    {
        $country = Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Correction 2 Test Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode($planOptionOverrides),
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

        return ['country' => $country, 'plan' => $plan];
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }
}
