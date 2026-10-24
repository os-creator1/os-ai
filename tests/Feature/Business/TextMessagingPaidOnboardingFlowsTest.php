<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\NumberLifecycleManager;
use App\Library\Messaging\Registration\RegistrationTemplateCatalog;
use App\Library\Usage\UsageWalletManager;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingRegistration;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Both complete customer paths over HTTP with the real Telnyx adapter and
 * mocked Telnyx responses, against the real wallet/meter services:
 * choose a number, see prices, fund, buy, register, track status.
 * Nothing here contacts Telnyx or charges a real customer.
 */
class TextMessagingPaidOnboardingFlowsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private const PURCHASE = 1_000_000;   // 1.00 one-time number purchase
    private const RENTAL = 2_000_000;     // 2.00 monthly rental
    private const REGISTRATION = 4_000_000; // 4.00 one-time 10DLC registration
    private const CAMPAIGN = 1_500_000;   // 1.50 monthly campaign

    private $business;
    private $workspace;

    /** Mutable mocked carrier answers, read at request time. */
    private int $orderStatus = 200;
    private string $tfStatus = 'Pending';
    private string $campaignStatus = 'PENDING';

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
        ]);

        [$customer, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Sunset Photo Booths');
        $this->authenticateAs($customer);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($this->business->id);
    }

    private function configureRates(): void
    {
        $admin = User::create(['first_name' => 'Owner', 'last_name' => 'Admin', 'email' => 'owner' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        config(['messaging.rates' => [
            'currency' => 'USD', 'actor_user_id' => (string) $admin->id, 'approved_by' => 'Owner, test fixture',
            'number_purchase' => ['retail_micro' => (string) self::PURCHASE, 'cost_micro' => '0', 'unit' => 'per number'],
            'number_rental_renewal' => ['retail_micro' => (string) self::RENTAL, 'cost_micro' => '0', 'unit' => 'per month'],
            'ten_dlc_registration' => ['retail_micro' => (string) self::REGISTRATION, 'cost_micro' => '0', 'unit' => 'per registration'],
            'ten_dlc_campaign_renewal' => ['retail_micro' => (string) self::CAMPAIGN, 'cost_micro' => '0', 'unit' => 'per month'],
        ]]);

        $this->artisan('messaging:sync-provisioning-rates')->assertSuccessful();
    }

    private function fund(int $micro): void
    {
        DB::table('business_usage_wallets')->where('business_id', $this->business->id)->update(['available_balance_micro' => $micro]);
    }

    private function balance(): int
    {
        return (int) DB::table('business_usage_wallets')->where('business_id', $this->business->id)->value('available_balance_micro');
    }

    private function url(string $name): string
    {
        return route('customer.workspaces.businesses.text-messaging.' . $name, [$this->workspace->uid, $this->business->uid]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'legal_business_name' => 'Sunset Photo Booths LLC', 'entity_type' => 'ein', 'ein' => '12-3456789',
            'address_line_1' => '9 Sunset Ave', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701',
            'website_url' => 'https://sunsetbooths.example', 'contact_email' => 'hi@sunsetbooths.example', 'contact_phone' => '+15125550100',
            'use_case' => 'customer_care',
            'campaign_description' => 'Sunset Photo Booths sends quote follow-ups and event reminders to customers who inquired.',
            'opt_in_method' => 'Customers tick an optional SMS box on https://sunsetbooths.example/contact before sending an inquiry.',
            'sample_message_1' => 'Sunset Photo Booths: thanks for your inquiry, your quote is on its way. Reply STOP to opt out.',
            'sample_message_2' => 'Sunset Photo Booths: reminder that your event is this weekend. Reply STOP to opt out.',
            'privacy_policy_url' => 'https://sunsetbooths.example/privacy', 'terms_url' => 'https://sunsetbooths.example/sms-terms',
            'consent_confirmations' => array_keys(RegistrationTemplateCatalog::CONSENT_ITEMS),
        ], $overrides);
    }

    private function telnyx(): void
    {
        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [['phone_number' => '+18885550123']]]),
            'api.telnyx.com/v2/messaging_profiles' => Http::response(['data' => ['id' => 'mp_flow_1']]),
            'api.telnyx.com/v2/number_orders' => fn () => $this->orderStatus === 200
                ? Http::response(['data' => ['phone_numbers' => [['id' => 'pn_flow_1']]]])
                : Http::response(['errors' => []], $this->orderStatus),
            'api.telnyx.com/v2/messaging_tollfree/verification/requests/*' => fn () => Http::response(['data' => ['verificationStatus' => $this->tfStatus]]),
            'api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_flow_1']]),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['data' => ['brandId' => 'brand_flow_1']]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['data' => ['campaignId' => 'camp_flow_1']]),
            'api.telnyx.com/v2/10dlc/campaign/*' => fn () => Http::response(['data' => ['campaignStatus' => $this->campaignStatus]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    private function posted(string $fragment): int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), $fragment))->count();
    }

    private function search(string $type, ?string $area = null): \Illuminate\Testing\TestResponse
    {
        return $this->post($this->url('number.search'), array_filter(['number_type' => $type, 'area_code' => $area]));
    }

    private function order(\Illuminate\Testing\TestResponse $search): \Illuminate\Testing\TestResponse
    {
        return $this->post($this->url('number.order'), ['candidate_token' => $search->viewData('candidateToken')]);
    }

    public function test_toll_free_end_to_end_shows_prices_blocks_without_funds_then_buys_verifies_free_and_tracks_status(): void
    {
        $this->configureRates();
        $this->telnyx();

        // Before purchase: actual one-time and monthly charges, and no funds yet.
        $search = $this->search('toll_free');
        $html = $search->getContent();
        $this->assertStringContainsString('data-role="charge-summary"', $html);
        $this->assertStringContainsString('USD 1.00', $html);
        $this->assertStringContainsString('USD 2.00', $html);
        $this->assertStringContainsString('Add funds', $html);
        $this->assertStringContainsString('Toll-free verification is $0 through Telnyx', $html);

        // Missing funds: a direct order is refused, nothing is bought or charged.
        $this->order($search)->assertSessionHas('status', 'error')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'enough funds'));
        $this->assertSame(0, $this->posted('/number_orders'));
        $this->assertSame(0, BusinessMessagingNumber::query()->count());

        // Funded: buy exactly once and pay the one-time purchase charge.
        $this->fund(20_000_000);
        $this->order($search)->assertSessionHas('status', 'success');
        $this->assertSame(20_000_000 - self::PURCHASE, $this->balance());
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
        $this->assertSame(1, $this->posted('/number_orders'));

        // A duplicate click can never buy or charge again.
        $this->order($search)->assertNotFound();
        $this->assertSame(1, $this->posted('/number_orders'));
        $this->assertSame(20_000_000 - self::PURCHASE, $this->balance());

        // Verification: free, submitted to the carrier, tracked as Pending.
        $balanceAfterPurchase = $this->balance();
        $reservationsAfterPurchase = DB::table('business_usage_reservations')->count();
        $this->post($this->url('registration.update'), $this->payload());
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $registration = BusinessMessagingRegistration::where('business_id', $this->business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->status);
        $this->assertSame('tfv_flow_1', $registration->provider_registration_id);
        $this->assertSame($balanceAfterPurchase, $this->balance());
        $this->assertSame($reservationsAfterPurchase, DB::table('business_usage_reservations')->count());
        $this->assertSame(1, $this->posted('/messaging_tollfree/verification/requests'));

        // Duplicate verification click: refused, no second request.
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(1, $this->posted('/messaging_tollfree/verification/requests'));

        // Rejected by the carrier, then corrected and resubmitted: a fresh
        // (still free) request, never silently reusing the rejected one.
        $this->tfStatus = 'Rejected';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->assertSame(MessagingRegistrationStatus::Rejected, $registration->fresh()->status);

        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->fresh()->status);
        $this->assertSame($balanceAfterPurchase, $this->balance());

        // Approved.
        $this->tfStatus = 'Verified';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->assertSame(MessagingRegistrationStatus::Approved, $registration->fresh()->status);
    }

    public function test_local_end_to_end_blocks_without_funds_charges_the_registration_once_then_buys_and_bills_monthly_rental_and_campaign(): void
    {
        $this->configureRates();
        $this->telnyx();

        $this->post($this->url('registration.start-local-verification'))->assertSessionHas('status', 'success');
        $this->post($this->url('registration.update'), $this->payload());

        $page = $this->get($this->url('show'))->getContent();
        $this->assertStringContainsString('USD 4.00', $page);
        $this->assertStringContainsString('USD 1.50', $page);

        // Missing funds: refused before any carrier request.
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'enough funds'));
        $this->assertSame(0, $this->posted('/10dlc/brand'));

        // Funded: charged exactly once; brand and campaign each created once.
        $this->fund(30_000_000);
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame(30_000_000 - self::REGISTRATION, $this->balance());
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));

        // Duplicate click: refused, no second fee or request.
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(30_000_000 - self::REGISTRATION, $this->balance());
        $this->assertSame(1, $this->posted('/10dlc/brand'));

        // Carrier approval, then the number.
        $this->campaignStatus = 'ACTIVE';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $registration = BusinessMessagingRegistration::where('business_id', $this->business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::Approved, $registration->status);

        $balanceBeforeNumber = $this->balance();
        $search = $this->search('local', '512');
        $this->order($search)->assertSessionHas('status', 'success');
        $this->assertSame($balanceBeforeNumber - self::PURCHASE, $this->balance());

        // Recurring: one renewal charges number rental AND the monthly campaign
        // cost (separate reservations), and a replay never charges twice.
        $number = BusinessMessagingNumber::query()->firstOrFail();
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['next_renewal_at' => now()->subDay()]);
        $balanceBeforeRenewal = $this->balance();

        $manager = app(NumberLifecycleManager::class);
        $this->assertSame('succeeded', $manager->attemptRenewal($number->fresh())->value);
        $this->assertSame($balanceBeforeRenewal - self::RENTAL - self::CAMPAIGN, $this->balance());
        $manager->attemptRenewal($number->fresh());
        $this->assertSame($balanceBeforeRenewal - self::RENTAL - self::CAMPAIGN, $this->balance());
    }

    public function test_a_failed_carrier_order_releases_the_funds_and_a_retry_buys_exactly_once(): void
    {
        $this->configureRates();
        $this->fund(10_000_000);
        $this->orderStatus = 422;
        $this->telnyx();

        $search = $this->search('toll_free');
        $this->order($search)->assertSessionHas('status', 'error');
        $this->assertSame(10_000_000, $this->balance());
        $this->assertSame(0, BusinessMessagingNumber::query()->count());

        $this->orderStatus = 200;
        $this->order($search)->assertSessionHas('status', 'success');
        $this->assertSame(10_000_000 - self::PURCHASE, $this->balance());
        $this->assertSame(1, BusinessMessagingNumber::query()->count());
    }

    public function test_an_unconfirmed_number_order_blocks_a_second_purchase_until_reconciled(): void
    {
        $this->configureRates();
        $this->fund(10_000_000);
        $this->orderStatus = 503;
        $this->telnyx();

        $search = $this->search('toll_free');
        $this->order($search)->assertSessionHas('status', 'error');
        $this->order($search)->assertSessionHas('status', 'error');

        $this->assertSame(1, $this->posted('/number_orders'));
        $this->assertSame(10_000_000, $this->balance());
        $this->assertSame(1, DB::table('business_messaging_provisioning_incidents')->where('stage', 'number_order_outcome_unknown')->whereNull('resolved_at')->count());
    }

    public function test_local_details_entered_first_carry_over_when_the_customer_switches_to_toll_free(): void
    {
        $this->configureRates();
        $this->fund(20_000_000);
        $this->telnyx();

        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        $this->assertSame('local', BusinessMessagingRegistration::where('business_id', $this->business->id)->first()->number_type->value);

        // Switch: buy a toll-free number instead; nothing is retyped or deleted.
        $this->order($this->search('toll_free'))->assertSessionHas('status', 'success');
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');

        $registration = BusinessMessagingRegistration::where('business_id', $this->business->id)->first();
        $this->assertSame('toll_free', $registration->number_type->value);
        $this->assertSame('Sunset Photo Booths LLC', $registration->legal_business_name);
        $this->assertSame('tfv_flow_1', $registration->provider_registration_id);
        $this->assertSame(1, $this->posted('/messaging_tollfree/verification/requests'));
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        // Only the number purchase was ever charged — no 10DLC fee.
        $this->assertSame(20_000_000 - self::PURCHASE, $this->balance());
    }

    public function test_without_configured_pricing_nothing_can_be_bought_and_the_page_says_so(): void
    {
        $this->fund(10_000_000);
        $this->telnyx();

        $search = $this->search('toll_free');
        $this->assertStringContainsString('pricing isn&#039;t set up', str_replace("'", '&#039;', $search->getContent()));

        $this->order($search)->assertSessionHas('status', 'error')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'pricing') && ! str_contains($m, 'enough funds'));
        $this->assertSame(0, $this->posted('/number_orders'));
        $this->assertSame(10_000_000, $this->balance());
    }

    public function test_a_renewal_that_cannot_cover_the_campaign_cost_charges_nothing_and_suspends_like_an_unpaid_renewal(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(30_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        $this->post($this->url('registration.submit'));
        $this->campaignStatus = 'ACTIVE';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->order($this->search('local', '512'));

        $number = BusinessMessagingNumber::query()->firstOrFail();
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['next_renewal_at' => now()->subDay()]);
        // Enough for the rental alone, not for rental + campaign.
        $this->fund(self::RENTAL + 1);

        $this->assertSame('insufficient_funds', app(NumberLifecycleManager::class)->attemptRenewal($number->fresh())->value);

        $this->assertSame(self::RENTAL + 1, $this->balance());
        $this->assertSame('suspended', $number->fresh()->status->value);
    }
}
