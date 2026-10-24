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
    private const LVM_REGISTRATION = 9_000_000; // 9.00 one-time Low Volume Mixed registration
    private const LVM_CAMPAIGN = 2_000_000;     // 2.00 monthly Low Volume Mixed campaign

    private $business;
    private $workspace;

    /** Mutable mocked carrier answers, read at request time. */
    private int $orderStatus = 200;
    private string $tfStatus = 'In Progress';
    private string $campaignStatus = 'TCR_PENDING';
    private bool $lvmEligible = true;
    private int $campaignCreateStatus = 200;
    /** @var array<string, mixed> the mocked qualify-by-usecase answer (root-level UsecaseMetadata) */
    private array $qualification = ['usecase' => 'LOW_VOLUME', 'monthlyFee' => 1.5, 'quarterlyFee' => 4.5, 'annualFee' => 18, 'minSubUsecases' => 2, 'maxSubUsecases' => 5, 'mnoMetadata' => []];

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
            'ten_dlc_low_volume_registration' => ['retail_micro' => (string) self::LVM_REGISTRATION, 'cost_micro' => '0', 'unit' => 'per registration'],
            'ten_dlc_low_volume_campaign_renewal' => ['retail_micro' => (string) self::LVM_CAMPAIGN, 'cost_micro' => '0', 'unit' => 'per month'],
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
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000', 'opt_in_image_url' => 'https://example.com/opt-in.png', 'brand_vertical' => 'ENTERTAINMENT',
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
            'api.telnyx.com/v2/messaging_tollfree/verification/requests/*' => fn () => Http::response(['id' => '8b2f3a52-2a62-4f4e-a3f1-5d3c7a9c1e10', 'verificationRequestId' => 'a6e0-fixture', 'verificationStatus' => $this->tfStatus, 'businessName' => 'Sunset Photo Booths LLC']),
            'api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['id' => 'tfv_flow_1', 'verificationRequestId' => 'vr_flow_1', 'verificationStatus' => 'In Progress', 'businessName' => 'Sunset Photo Booths LLC']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['brandId' => 'brand_flow_1', 'tcrBrandId' => 'BXYZ', 'displayName' => 'Sunset Photo Booths LLC', 'identityStatus' => 'UNVERIFIED', 'status' => 'REGISTRATION_PENDING', 'mock' => false]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => fn () => $this->campaignCreateStatus === 200
                ? Http::response(['campaignId' => 'camp_flow_1', 'brandId' => 'brand_flow_1', 'usecase' => 'LOW_VOLUME', 'subUsecases' => ['CUSTOMER_CARE', 'MARKETING'], 'description' => 'd', 'mock' => false, 'termsAndConditions' => true, 'campaignStatus' => 'TCR_PENDING', 'submissionStatus' => 'CREATED'])
                : Http::response(['errors' => [['code' => '10002', 'title' => 'Invalid sample']]], $this->campaignCreateStatus),
            'api.telnyx.com/v2/10dlc/campaign/*' => fn () => Http::response(['campaignId' => 'camp_flow_1', 'brandId' => 'brand_flow_1', 'usecase' => 'CUSTOMER_CARE', 'subUsecases' => [], 'description' => 'd', 'mock' => false, 'termsAndConditions' => true, 'campaignStatus' => $this->campaignStatus, 'status' => 'ACTIVE', 'submissionStatus' => 'CREATED']),
            'api.telnyx.com/v2/10dlc/campaignBuilder/brand/*' => fn () => $this->lvmEligible
                ? Http::response($this->qualification)
                : Http::response(['errors' => [['code' => '10016', 'title' => 'Brand not eligible']]], 422),
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
        $this->campaignStatus = 'MNO_PROVISIONED';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $registration = BusinessMessagingRegistration::where('business_id', $this->business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::Approved, $registration->status);

        $balanceBeforeNumber = $this->balance();
        $search = $this->search('local', '512');
        $this->order($search)->assertSessionHas('status', 'success');
        $this->assertSame($balanceBeforeNumber - self::PURCHASE, $this->balance());

        // Recurring. The registration price already covered the first three
        // campaign months, so a renewal inside that window charges rental only.
        $number = BusinessMessagingNumber::query()->firstOrFail();
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['next_renewal_at' => now()->subDay()]);
        $balanceBeforeRenewal = $this->balance();

        $manager = app(NumberLifecycleManager::class);
        $this->assertSame('succeeded', $manager->attemptRenewal($number->fresh())->value);
        $this->assertSame($balanceBeforeRenewal - self::RENTAL, $this->balance());

        // After the prepaid months, a renewal charges rental AND the monthly
        // campaign cost (separate reservations), and a replay never charges twice.
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['submitted_at' => now()->subMonths(4)]);
        // A later billing period (a different due date, as in production).
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['next_renewal_at' => now()->subDays(2)]);
        $balanceBeforeFull = $this->balance();

        $this->assertSame('succeeded', $manager->attemptRenewal($number->fresh())->value);
        $this->assertSame($balanceBeforeFull - self::RENTAL - self::CAMPAIGN, $this->balance());
        $manager->attemptRenewal($number->fresh());
        $this->assertSame($balanceBeforeFull - self::RENTAL - self::CAMPAIGN, $this->balance());
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
        $this->campaignStatus = 'MNO_PROVISIONED';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->order($this->search('local', '512'));

        $number = BusinessMessagingNumber::query()->firstOrFail();
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['submitted_at' => now()->subMonths(4)]);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['next_renewal_at' => now()->subDay()]);
        // Enough for the rental alone, not for rental + campaign.
        $this->fund(self::RENTAL + 1);

        $this->assertSame('insufficient_funds', app(NumberLifecycleManager::class)->attemptRenewal($number->fresh())->value);

        $this->assertSame(self::RENTAL + 1, $this->balance());
        $this->assertSame('suspended', $number->fresh()->status->value);
    }

    /** @return array{0: BusinessMessagingNumber, 1: NumberLifecycleManager} an approved local number whose prepaid months have ended */
    private function approvedLocalNumberPastPrepaid(): array
    {
        $this->telnyx();
        $this->fund(30_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        $this->post($this->url('registration.submit'));
        $this->campaignStatus = 'MNO_PROVISIONED';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->order($this->search('local', '512'));

        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['submitted_at' => now()->subMonths(4)]);
        $number = BusinessMessagingNumber::query()->firstOrFail();
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['next_renewal_at' => now()->subDay()]);

        return [$number, app(NumberLifecycleManager::class)];
    }

    public function test_a_renewal_never_silently_skips_a_required_campaign_charge_whose_price_was_removed(): void
    {
        $this->configureRates();
        [$number, $manager] = $this->approvedLocalNumberPastPrepaid();

        // The owner's campaign price disappears (meter deactivated) after the purchase.
        DB::table('usage_meters')->where('meter_key', NumberLifecycleManager::FEATURE_TEN_DLC_CAMPAIGN_RENEWAL)->update(['is_metered' => 0]);
        $balance = $this->balance();

        $this->assertSame('not_configured', $manager->attemptRenewal($number->fresh())->value);

        $this->assertSame($balance, $this->balance());
        $this->assertSame('active', $number->fresh()->status->value);
    }

    public function test_the_renewal_warning_lists_every_recurring_charge_including_the_campaign_cost(): void
    {
        $this->configureRates();
        [$number, $manager] = $this->approvedLocalNumberPastPrepaid();

        $labels = $manager->recurringChargeLabels($number->fresh());

        $this->assertSame(['Number rental: USD 2.00 per month', '10DLC campaign: USD 1.50 per month'], $labels);

        // Inside the prepaid window the campaign cost is not part of the renewal.
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['submitted_at' => now()]);
        $this->assertSame(['Number rental: USD 2.00 per month'], $manager->recurringChargeLabels($number->fresh()));
    }

    public function test_a_number_cannot_be_bought_unless_its_monthly_rental_price_is_configured_too(): void
    {
        $this->configureRates();
        DB::table('usage_meters')->where('meter_key', NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL)->update(['is_metered' => 0]);
        $this->fund(10_000_000);
        $this->telnyx();

        $search = $this->search('toll_free');
        $this->assertStringContainsString('data-configured="0"', $search->getContent());

        $this->order($search)->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'pricing'));
        $this->assertSame(0, $this->posted('/number_orders'));
        $this->assertSame(10_000_000, $this->balance());
    }

    private function lowVolumePayload(array $overrides = []): array
    {
        return $this->payload(array_merge([
            'use_case' => 'low_volume_mixed',
            'message_purposes' => ['care', 'marketing'],
            'campaign_description' => 'Sunset Photo Booths sends booking confirmations and event reminders, and occasional seasonal promotions, to people who opted in.',
            'sample_message_1' => 'Sunset Photo Booths: your photo booth booking is confirmed for Saturday. Reply STOP to opt out.',
            'sample_message_2' => 'Sunset Photo Booths: our seasonal specials are here, 15% off packages this month. Reply STOP to opt out.',
            'consent_confirmations' => array_keys(
                RegistrationTemplateCatalog::CONSENT_ITEMS + RegistrationTemplateCatalog::LOW_VOLUME_CONSENT_ITEMS
            ),
        ], $overrides));
    }

    public function test_low_volume_mixed_end_to_end_uses_its_own_prices_payload_and_billing(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(40_000_000);

        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->lowVolumePayload())->assertSessionHas('status', 'success');

        // The page shows the Low Volume Mixed prices, not the standard ones.
        $page = $this->get($this->url('show'))->getContent();
        $this->assertStringContainsString('Low Volume Mixed', $page);
        $this->assertStringContainsString('USD 9.00', $page);
        $this->assertStringContainsString('USD 2.00', $page);

        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');

        // Charged the Low Volume Mixed registration price exactly once — not the standard one.
        $this->assertSame(40_000_000 - self::LVM_REGISTRATION, $this->balance());
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/10dlc/campaignBuilder') && ! str_contains($request->url(), '/brand/')
            && $request->method() === 'POST'
            && $request['usecase'] === 'LOW_VOLUME' && $request['subUsecases'] === ['CUSTOMER_CARE', 'MARKETING']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/10dlc/campaignBuilder/brand/brand_flow_1/usecase/LOW_VOLUME'));

        // Duplicate click: refused, nothing more charged or sent.
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(40_000_000 - self::LVM_REGISTRATION, $this->balance());

        // Approve, buy, then renewals: the prepaid window charges rental only; afterwards
        // the Low Volume Mixed monthly price (never the standard one).
        $this->campaignStatus = 'MNO_PROVISIONED';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->order($this->search('local', '512'));
        $number = BusinessMessagingNumber::query()->firstOrFail();
        $manager = app(NumberLifecycleManager::class);

        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['next_renewal_at' => now()->subDay()]);
        $before = $this->balance();
        $manager->attemptRenewal($number->fresh());
        $this->assertSame($before - self::RENTAL, $this->balance());

        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['submitted_at' => now()->subMonths(4)]);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update(['next_renewal_at' => now()->subDays(2)]);
        $before = $this->balance();
        $manager->attemptRenewal($number->fresh());
        $this->assertSame($before - self::RENTAL - self::LVM_CAMPAIGN, $this->balance());
    }

    public function test_low_volume_mixed_is_refused_when_telnyx_says_the_brand_is_not_eligible_and_never_charges(): void
    {
        $this->configureRates();
        $this->lvmEligible = false;
        $this->telnyx();
        $this->fund(40_000_000);

        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->lowVolumePayload());
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');

        $this->assertSame(40_000_000, $this->balance());
        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(1, $this->posted('/10dlc/brand'));

        // Eligibility later granted: retry reuses the brand (no second brand) and charges once.
        $this->lvmEligible = true;
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000 - self::LVM_REGISTRATION, $this->balance());
    }

    public function test_low_volume_mixed_needs_its_own_configured_prices_and_never_falls_back_to_the_standard_ones(): void
    {
        $this->configureRates();
        DB::table('usage_meters')->where('meter_key', 'messaging_10dlc_low_volume_registration')->update(['is_metered' => 0]);
        $this->telnyx();
        $this->fund(40_000_000);

        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->lowVolumePayload());
        $page = $this->get($this->url('show'))->getContent();
        $this->assertStringContainsString("this campaign type isn't set up", str_replace('&#039;', "'", $page));

        $this->post($this->url('registration.submit'))->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'pricing'));
        $this->assertSame(40_000_000, $this->balance());
        $this->assertSame(0, $this->posted('/10dlc/brand'));
    }

    public function test_low_volume_mixed_validation_requires_real_purposes_samples_and_consent_for_each(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(40_000_000);
        $this->post($this->url('registration.start-local-verification'));

        // No purposes chosen, missing attestation.
        $this->post($this->url('registration.update'), $this->lowVolumePayload(['message_purposes' => [], 'consent_confirmations' => array_keys(RegistrationTemplateCatalog::CONSENT_ITEMS)]));
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $page = str_replace('&#039;', "'", $this->get($this->url('show'))->getContent());
        $this->assertStringContainsString('only for a business that really sends both', $page);
        $this->assertStringContainsString('small sender', $page);

        // Both purposes chosen but only promotional samples: the care sample is missing.
        $this->post($this->url('registration.update'), $this->lowVolumePayload(['sample_message_1' => 'Sunset Photo Booths: 20% off specials today. Reply STOP to opt out.']));
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000, $this->balance());

        // A single purpose is never "mixed": refused, not relabelled to get a cheaper fee.
        $this->post($this->url('registration.update'), $this->lowVolumePayload(['message_purposes' => ['care']]));
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertStringContainsString('only for a business that really sends both', str_replace('&#039;', "'", $this->get($this->url('show'))->getContent()));
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000, $this->balance());
    }

    public function test_low_volume_mixed_is_offered_for_local_but_not_for_toll_free(): void
    {
        $this->configureRates();
        $this->post($this->url('registration.start-local-verification'));
        $this->assertStringContainsString('value="low_volume_mixed"', $this->get($this->url('show'))->getContent());
    }

    // -----------------------------------------------------------------
    // Navigation: the customer is never trapped in local registration.
    // -----------------------------------------------------------------

    public function test_local_registration_has_exits_at_the_top_and_stepping_back_keeps_every_detail(): void
    {
        $this->configureRates();
        $this->telnyx();

        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());

        $html = $this->get($this->url('show'))->getContent();
        $this->assertStringContainsString('data-role="back-to-number-choices"', $html);
        $this->assertStringContainsString('data-role="choose-toll-free-instead"', $html);
        // The exit sits above the long form, not below it.
        $this->assertLessThan(strpos($html, 'name="legal_business_name"'), strpos($html, 'data-role="back-to-number-choices"'));

        // Step back: the ORIGINAL Local / Toll-free choice returns (no redirect loop to the form).
        $this->post($this->url('registration.leave-local-setup'))->assertRedirect($this->url('show'))->assertSessionHas('status', 'success');
        $choice = $this->get($this->url('show'))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="toll-free-number-card"', $choice);
        $this->assertStringContainsString('data-role="local-number-card"', $choice);
        $this->assertStringNotContainsString('name="legal_business_name"', $choice);

        // Nothing was deleted: the details are still saved.
        $row = BusinessMessagingRegistration::where('business_id', $this->business->id)->first();
        $this->assertSame('Sunset Photo Booths LLC', $row->legal_business_name);
        $this->assertSame('12-3456789', $row->ein);
        $this->assertNull($row->local_setup_started_at);

        // Refresh / direct navigation stay on the choice screen; the exit is POST-only.
        $this->get($this->url('show'))->assertOk()->assertSee('data-role="local-number-card"', false);
        $this->assertContains($this->get($this->url('registration.leave-local-setup'))->getStatusCode(), [404, 405]);

        // Local again: the form is restored with the saved details.
        $this->post($this->url('registration.start-local-verification'));
        $this->get($this->url('show'))->assertOk()->assertSee('Sunset Photo Booths LLC')->assertSee('12-3456789');
    }

    public function test_choose_toll_free_instead_enters_the_normal_toll_free_purchase_flow(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(20_000_000);

        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());

        $this->post($this->url('registration.leave-local-setup'), ['then' => 'toll_free'])->assertSessionHas('status', 'success');
        $page = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$this->workspace->uid, $this->business->uid, 'focus' => 'toll_free']))->getContent();
        $this->assertStringContainsString('data-role="toll-free-focus-banner"', $page);
        $this->assertStringNotContainsString('name="legal_business_name"', $page);

        // Local -> toll-free -> local -> toll-free before any submission, details intact throughout.
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.leave-local-setup'), ['then' => 'toll_free']);
        $this->order($this->search('toll_free'))->assertSessionHas('status', 'success');

        $registration = BusinessMessagingRegistration::where('business_id', $this->business->id)->first();
        $this->assertSame('Sunset Photo Booths LLC', $registration->legal_business_name);
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame('toll_free', $registration->fresh()->number_type->value);
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(20_000_000 - self::PURCHASE, $this->balance());
    }

    public function test_a_submitted_local_registration_cannot_be_walked_away_from_and_the_page_explains_why(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(30_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $balance = $this->balance();

        $this->post($this->url('registration.leave-local-setup'))->assertSessionHas('status', 'error')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'submitted to the carrier') && str_contains($m, 'paid for'));

        $this->assertSame(MessagingRegistrationStatus::Pending, BusinessMessagingRegistration::where('business_id', $this->business->id)->first()->status);
        $this->assertSame($balance, $this->balance());
        // Still the registration screen: the commitment is not hidden.
        $this->get($this->url('show'))->assertOk()->assertSee('Sunset Photo Booths LLC');
    }

    /** @return array{0: string} submits a fully valid Low Volume Mixed registration and returns the flash message */
    private function submitLowVolume(): string
    {
        $this->post($this->url('registration.submit'));

        return (string) session('message');
    }

    private function startLowVolume(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(40_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->lowVolumePayload());
    }

    public function test_declared_purposes_outside_the_carrier_limits_are_rejected_never_truncated(): void
    {
        $this->startLowVolume();

        // The carrier allows fewer purposes than declared: reject, never drop one.
        $this->qualification['maxSubUsecases'] = 1;
        $message = $this->submitLowVolume();
        $this->assertStringContainsString('at most 1', $message);
        $this->assertStringContainsString('none of your purposes was dropped', $message);

        // The carrier needs more than were declared: reject, never invent one.
        $this->qualification['maxSubUsecases'] = 5;
        $this->qualification['minSubUsecases'] = 3;
        $this->assertStringContainsString('at least 3', $this->submitLowVolume());

        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(40_000_000, $this->balance());

        // Within limits: both purposes are sent exactly as declared.
        $this->qualification['minSubUsecases'] = 2;
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/10dlc/campaignBuilder')
            && $request['subUsecases'] === ['CUSTOMER_CARE', 'MARKETING']);
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000 - self::LVM_REGISTRATION, $this->balance());
    }

    public function test_missing_or_unexpected_qualification_fields_are_refused_conservatively(): void
    {
        $this->startLowVolume();

        foreach ([
            ['usecase' => 'LOW_VOLUME'],                                                   // limits missing
            ['usecase' => 'LOW_VOLUME', 'minSubUsecases' => '2', 'maxSubUsecases' => 'five'], // wrong types
            ['usecase' => 'LOW_VOLUME', 'minSubUsecases' => -1, 'maxSubUsecases' => 5],     // nonsensical
            ['usecase' => 'MIXED', 'minSubUsecases' => 2, 'maxSubUsecases' => 5],           // not the use case we asked about
            ['data' => ['minSubUsecases' => 2, 'maxSubUsecases' => 5, 'usecase' => 'LOW_VOLUME']], // not the documented root-level shape
        ] as $answer) {
            $this->qualification = $answer;
            $this->assertStringContainsString("couldn't confirm the carrier's rules", str_replace('&#039;', "'", $this->submitLowVolume()));
        }

        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(40_000_000, $this->balance());
    }

    public function test_a_carrier_that_refuses_the_campaign_after_qualification_releases_the_funds_and_a_retry_reuses_the_brand(): void
    {
        $this->startLowVolume();
        $this->campaignCreateStatus = 422;

        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(40_000_000, $this->balance());

        $this->campaignCreateStatus = 200;
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000 - self::LVM_REGISTRATION, $this->balance());
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(40_000_000 - self::LVM_REGISTRATION, $this->balance());
    }
}
