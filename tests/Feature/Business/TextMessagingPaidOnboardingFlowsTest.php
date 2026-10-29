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
    use \Tests\Support\CompletesTenDlcStages;

    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    // Carrier cost passes through at a 1.00 multiplier (zero markup): the number's quote is
    // upfront 0.25 + monthly 1.00, so the first charge is 1.25 and rental renews at 1.00.
    private const PURCHASE = 1_250_000;
    private const RENTAL = 1_000_000;
    private const BRAND = 4_500_000;                // carrier brand fee, charged when the brand is created
    private const REGISTRATION = 30_000_000;        // 3 prepaid months of a standard campaign
    private const CAMPAIGN = 10_000_000;            // standard campaign, monthly after the prepaid months
    private const LVM_REGISTRATION = 4_500_000;     // 3 prepaid months of Low Volume Mixed
    private const LVM_CAMPAIGN = 1_500_000;         // Low Volume Mixed, monthly after the prepaid months

    private $business;
    private $workspace;

    /** Mutable mocked carrier answers, read at request time. */
    private int $orderStatus = 200;
    private string $tfStatus = 'In Progress';
    private string $campaignStatus = 'TCR_PENDING';
    private bool $lvmEligible = true;
    private int $brandGetHttp = 200;
    /** ok | 429 | 500 | timeout | malformed — how the mocked campaign status lookup behaves. */
    private string $campaignGetMode = 'ok';
    private string $brandIdentity = 'VERIFIED';
    private string $brandRegistrationStatus = 'OK';
    private ?int $qualificationHttp = null;
    private int $brandCreateStatus = 200;
    /** The mocked carrier quote for the number (cost_information strings). */
    private array $numberCost = ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD'];
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
            'number_purchase' => ['retail_micro' => '1000000', 'cost_micro' => '0', 'unit' => 'per 1.00 of carrier cost'],
            'number_rental_renewal' => ['retail_micro' => '1000000', 'cost_micro' => '0', 'unit' => 'per 1.00 of carrier cost'],
            'ten_dlc_brand_registration' => ['retail_micro' => (string) self::BRAND, 'cost_micro' => '0', 'unit' => 'per brand'],
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
            'privacy_policy_url' => 'https://sunsetbooths.example/privacy', 'terms_url' => 'https://sunsetbooths.example/sms-terms', 'support_contact' => 'help@fixture.example', 'support_contact_confirmed' => '1', 'support_contact_confirmed_for' => 'help@fixture.example', 'support_contact_confirmed_at' => '2026-01-01 00:00:00',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000', 'opt_in_image_url' => 'https://example.com/opt-in.png', 'brand_vertical' => 'ENTERTAINMENT',
            'consent_confirmations' => array_keys(RegistrationTemplateCatalog::CONSENT_ITEMS),
        ], $overrides);
    }

    private function telnyx(): void
    {
        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => fn () => Http::response(['data' => [['record_type' => 'available_phone_number', 'phone_number' => '+18885550123', 'best_effort' => false, 'cost_information' => $this->numberCost, 'features' => [['name' => 'sms']]]]]),
            'api.telnyx.com/v2/messaging_profiles' => Http::response(['data' => ['id' => 'mp_flow_1']]),
            'api.telnyx.com/v2/number_orders' => fn () => $this->orderStatus === 200
                ? Http::response(['data' => ['phone_numbers' => [['id' => 'pn_flow_1']]]])
                : Http::response(['errors' => []], $this->orderStatus),
            'api.telnyx.com/v2/messaging_tollfree/verification/requests/*' => fn () => Http::response(['id' => '8b2f3a52-2a62-4f4e-a3f1-5d3c7a9c1e10', 'verificationRequestId' => 'a6e0-fixture', 'verificationStatus' => $this->tfStatus, 'businessName' => 'Sunset Photo Booths LLC']),
            'api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['id' => 'tfv_flow_1', 'verificationRequestId' => 'vr_flow_1', 'verificationStatus' => 'In Progress', 'businessName' => 'Sunset Photo Booths LLC']),
            'api.telnyx.com/v2/10dlc/brand/*' => fn () => $this->brandGetHttp === 200
                ? Http::response(['brandId' => 'brand_flow_1', 'tcrBrandId' => 'BB84JS8', 'identityStatus' => $this->brandIdentity, 'status' => $this->brandRegistrationStatus])
                : Http::response(['errors' => [['code' => '20001', 'title' => 'busy']]], $this->brandGetHttp),
            'api.telnyx.com/v2/10dlc/brand' => fn () => $this->brandCreateStatus === 200
                ? Http::response(['brandId' => 'brand_flow_1', 'tcrBrandId' => 'BXYZ', 'displayName' => 'Sunset Photo Booths LLC', 'identityStatus' => 'UNVERIFIED', 'status' => 'REGISTRATION_PENDING', 'mock' => false])
                : Http::response(['errors' => [['code' => '10015', 'title' => 'Invalid ein 12-3456789', 'source' => ['pointer' => '/body/ein']]]], $this->brandCreateStatus, ['x-request-id' => 'req-brand-1']),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => fn () => $this->campaignCreateStatus === 200
                ? Http::response(['campaignId' => 'camp_flow_1', 'brandId' => 'brand_flow_1', 'usecase' => 'LOW_VOLUME', 'subUsecases' => ['CUSTOMER_CARE', 'MARKETING'], 'description' => 'd', 'mock' => false, 'termsAndConditions' => true, 'campaignStatus' => 'TCR_PENDING', 'submissionStatus' => 'CREATED'])
                : Http::response(['errors' => [['code' => '10002', 'title' => 'Invalid sample']]], $this->campaignCreateStatus),
            'api.telnyx.com/v2/10dlc/campaign/*' => fn () => $this->campaignGetMode !== 'ok' ? $this->campaignGetOutage() : Http::response(['campaignId' => 'camp_flow_1', 'brandId' => 'brand_flow_1', 'usecase' => 'CUSTOMER_CARE', 'subUsecases' => [], 'description' => 'd', 'mock' => false, 'termsAndConditions' => true, 'campaignStatus' => $this->campaignStatus, 'status' => 'ACTIVE', 'submissionStatus' => 'CREATED']),
            'api.telnyx.com/v2/10dlc/campaignBuilder/brand/*' => fn () => $this->qualificationHttp !== null
                ? Http::response(['errors' => [['code' => '10001', 'title' => 'x']]], $this->qualificationHttp)
                : ($this->lvmEligible
                ? Http::response($this->qualification)
                : Http::response(['errors' => [['code' => '10016', 'title' => 'Brand not eligible']]], 422)),
            '*' => Http::response(['data' => []]),
        ]);
    }

    private function campaignGetOutage()
    {
        return match ($this->campaignGetMode) {
            'timeout' => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
            'malformed' => Http::response(['unexpected' => 'shape'], 200),
            default => Http::response(['errors' => [['code' => '20001', 'title' => 'busy']]], (int) $this->campaignGetMode),
        };
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
        $this->assertStringContainsString('USD 1.25', $html);
        $this->assertStringContainsString('USD 1.00 per month', $html);
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
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $registration = BusinessMessagingRegistration::where('business_id', $this->business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->status);
        $this->assertSame('tfv_flow_1', $registration->provider_registration_id);
        $this->assertSame($balanceAfterPurchase, $this->balance());
        $this->assertSame($reservationsAfterPurchase, DB::table('business_usage_reservations')->count());
        $this->assertSame(1, $this->posted('/messaging_tollfree/verification/requests'));

        // Duplicate verification click: refused, no second request.
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(1, $this->posted('/messaging_tollfree/verification/requests'));

        // Rejected by the carrier, then corrected and resubmitted: a fresh
        // (still free) request, never silently reusing the rejected one.
        $this->tfStatus = 'Rejected';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->assertSame(MessagingRegistrationStatus::Rejected, $registration->fresh()->status);

        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');
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
        // Two charges at two times, never one combined "34.50".
        $this->assertStringContainsString('data-role="fee-due-now"', $page);
        $this->assertStringContainsString('USD 4.50', $page);
        $this->assertStringContainsString('data-role="fee-due-at-campaign"', $page);
        $this->assertStringContainsString('USD 30.00', $page);
        $this->assertStringNotContainsString('USD 34.50', $page);
        $this->assertStringContainsString('USD 10.00', $page);

        // Missing funds: refused before any carrier request.
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'enough funds'));
        $this->assertSame(0, $this->posted('/10dlc/brand'));

        // Funded: charged exactly once; brand and campaign each created once.
        $this->fund(60_000_000);
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame(60_000_000 - self::BRAND - self::REGISTRATION, $this->balance());
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));

        // Duplicate click: refused, no second fee or request.
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(60_000_000 - self::BRAND - self::REGISTRATION, $this->balance());
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
        $first = $this->order($search);
        $first->assertSessionHas('status', 'error');
        $text = (string) session('message');
        $this->assertStringContainsString('awaiting confirmation from the carrier', $text);
        $this->assertStringNotContainsString('have not been charged', $text, 'A kept charge must never be described as no charge.');
        $this->order($search)->assertSessionHas('status', 'error');

        $this->assertSame(1, $this->posted('/number_orders'));
        $this->assertSame(10_000_000 - self::PURCHASE, $this->balance(), 'an unconfirmed order may be billing: the charge is kept, not absorbed');
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
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');

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
        $this->fund(60_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        $this->twoStageSubmit($this->url('registration.submit'));
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
        $this->fund(60_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        $this->twoStageSubmit($this->url('registration.submit'));
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

        $this->assertSame(['Number rental: USD 1.00 per month', '10DLC campaign: USD 10.00 per month'], $labels);

        // Inside the prepaid window the campaign cost is not part of the renewal.
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['submitted_at' => now()]);
        $this->assertSame(['Number rental: USD 1.00 per month'], $manager->recurringChargeLabels($number->fresh()));
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
        $this->assertStringContainsString('USD 4.50', $page);
        $this->assertStringContainsString('data-role="fee-due-at-campaign"', $page);
        $this->assertStringContainsString('USD 1.50', $page);

        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');

        // Charged the Low Volume Mixed registration price exactly once — not the standard one.
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/10dlc/campaignBuilder') && ! str_contains($request->url(), '/brand/')
            && $request->method() === 'POST'
            && $request['usecase'] === 'LOW_VOLUME' && $request['subUsecases'] === ['CUSTOMER_CARE', 'MARKETING']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/10dlc/campaignBuilder/brand/brand_flow_1/usecase/LOW_VOLUME'));

        // Duplicate click: refused, nothing more charged or sent.
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());

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
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');

        $this->assertSame(40_000_000 - self::BRAND, $this->balance()); // the carrier brand exists, so its fee stays earned
        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(1, $this->posted('/10dlc/brand'));

        // Eligibility later granted: retry reuses the brand (no second brand) and charges once.
        $this->lvmEligible = true;
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
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

        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'pricing'));
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
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $page = str_replace('&#039;', "'", $this->get($this->url('show'))->getContent());
        $this->assertStringContainsString('only for a business that really sends both', $page);
        $this->assertStringContainsString('small sender', $page);

        // Both purposes chosen but only promotional samples: the care sample is missing.
        $this->post($this->url('registration.update'), $this->lowVolumePayload(['sample_message_1' => 'Sunset Photo Booths: 20% off specials today. Reply STOP to opt out.']));
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000, $this->balance());

        // A single purpose is never "mixed": refused, not relabelled to get a cheaper fee.
        $this->post($this->url('registration.update'), $this->lowVolumePayload(['message_purposes' => ['care']]));
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
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

    public function test_low_volume_form_discloses_brand_prepayment_and_possible_review_fee_without_charging_or_enabling_standard_mixed(): void
    {
        $this->configureRates();
        config(['messaging.ten_dlc_mixed_enabled' => false]);
        $before = $this->balance();
        $this->telnyx();
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());

        $html = str_replace('&#039;', "'", $this->get($this->url('show'))->getContent());
        $this->assertStringContainsString('value="low_volume_mixed"', $html);
        $this->assertStringNotContainsString('value="mixed"', $html);
        $this->assertStringContainsString('data-role="possible-review-fee"', $html);
        $this->assertStringContainsString('USD 15.00 per carrier review, if sent for downstream review; additional review fees may apply on resubmission', $html);
        $this->assertStringContainsString('data-role="fee-due-now"', $html);
        $this->assertStringContainsString('carrier brand fee — USD 4.50', $html);
        $this->assertStringContainsString('data-role="fee-due-at-campaign"', $html);
        $this->assertStringContainsString('not part of the amount charged when you submit', $html);

        $preview = app(\App\Library\Messaging\MessagingChargePreview::class)->forLocalRegistration($this->business, 'low_volume_mixed');
        $this->assertSame(15_000_000, $preview['possible_review_fee']['micro']);
        $this->assertSame(self::BRAND + self::LVM_REGISTRATION, $preview['one_time']['micro']);
        $this->assertSame($before, $this->balance());
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
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame('toll_free', $registration->fresh()->number_type->value);
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(20_000_000 - self::PURCHASE, $this->balance());
    }

    public function test_a_submitted_local_registration_cannot_be_walked_away_from_and_the_page_explains_why(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(60_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');
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
        $this->twoStageSubmit($this->url('registration.submit'));

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

    public function test_low_volume_qualification_failures_after_brand_creation_distinguish_the_committed_brand_fee_from_the_released_campaign_fee(): void
    {
        $this->startLowVolume();

        // Stage 1 already created and charged the brand, so a refusal at stage 2 never claims a new brand fee.
        $this->lvmEligible = false;
        $refused = $this->submitLowVolume();
        $this->assertStringContainsString('no new brand fee was charged', $refused);
        $this->assertStringContainsString('campaign fee was not charged', $refused);
        $this->assertStringNotContainsString('Nothing was charged', $refused);

        // Malformed limits on the retry reuse the saved brand: no new brand fee is claimed.
        $this->lvmEligible = true;
        $this->qualification['minSubUsecases'] = 'unknown';
        $malformed = $this->submitLowVolume();
        $this->assertStringContainsString('no new brand fee was charged', $malformed);
        $this->assertStringContainsString('campaign fee was not charged', $malformed);
        $this->assertStringNotContainsString('Nothing was charged', $malformed);

        // The brand fee is the only money taken; the campaign hold was released; no second brand.
        $this->assertSame(40_000_000 - self::BRAND, $this->balance());
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(0, (int) DB::table('business_usage_reservations')->where('business_id', $this->business->id)->where('status', 'held')->count());

        // Retry on the SAVED brand: a refusal now must not claim a new brand fee.
        $this->lvmEligible = false;
        $retry = $this->submitLowVolume();
        $this->assertStringContainsString('no new brand fee was charged', $retry);
        $this->assertStringContainsString('campaign fee was not charged', $retry);
        $this->assertSame(40_000_000 - self::BRAND, $this->balance());
    }

    public function test_declared_purposes_outside_the_carrier_limits_are_rejected_never_truncated(): void
    {
        $this->startLowVolume();

        // The carrier allows fewer purposes than declared: reject, never drop one.
        $this->qualification['maxSubUsecases'] = 1;
        $message = $this->submitLowVolume();
        $this->assertStringContainsString('at most 1', $message);
        $this->assertStringContainsString('None of your purposes was dropped', $message);
        $this->assertStringContainsString('no new brand fee was charged', $message);
        $this->assertStringContainsString('campaign fee was not charged', $message);
        $this->assertStringNotContainsString('Nothing was charged', $message);

        // The carrier needs more than were declared: reject, never invent one.
        $this->qualification['maxSubUsecases'] = 5;
        $this->qualification['minSubUsecases'] = 3;
        $this->assertStringContainsString('at least 3', $this->submitLowVolume());

        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(40_000_000 - self::BRAND, $this->balance()); // the carrier brand exists, so its fee stays earned

        // Within limits: both purposes are sent exactly as declared.
        $this->qualification['minSubUsecases'] = 2;
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/10dlc/campaignBuilder')
            && $request['subUsecases'] === ['CUSTOMER_CARE', 'MARKETING']);
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
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
        $this->assertSame(40_000_000 - self::BRAND, $this->balance()); // the carrier brand exists, so its fee stays earned
    }

    public function test_a_carrier_that_refuses_the_campaign_after_qualification_releases_the_funds_and_a_retry_reuses_the_brand(): void
    {
        $this->startLowVolume();
        $this->campaignCreateStatus = 422;

        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(40_000_000 - self::BRAND, $this->balance()); // the carrier brand exists, so its fee stays earned

        $this->campaignCreateStatus = 200;
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
    }

    private function captureWarnings(): \ArrayObject
    {
        $logged = new \ArrayObject();
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use ($logged) {
            $logged[] = [(string) $message, (array) $context];
        });
        \Illuminate\Support\Facades\Log::shouldReceive('error', 'info', 'notice', 'debug')->andReturnNull();

        return $logged;
    }

    public function test_a_carrier_brand_rejection_is_reported_as_a_provider_failure_with_no_charge_and_detail_only_in_the_log(): void
    {
        $this->startLowVolume();
        $this->brandCreateStatus = 422;
        $logged = $this->captureWarnings();

        $message = $this->submitLowVolume();

        $this->assertStringContainsString('Step 1 of 2', $message);
        $this->assertStringContainsString('carrier could not accept this step', $message);
        $this->assertStringContainsString('Your saved details remain', $message);
        $this->assertStringNotContainsString('not something you need to correct', $message);
        $this->assertStringNotContainsString('need to correct', $message);
        $this->assertStringContainsString('nothing was charged', $message);
        $this->assertStringNotContainsString('review your details', $message);
        $this->assertStringNotContainsString('10015', $message);
        $this->assertStringNotContainsString('/body/ein', $message);
        $this->assertSame(40_000_000, $this->balance());
        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));

        $dump = json_encode($logged->getArrayCopy(), JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('"http_status":422', $dump);
        $this->assertStringContainsString('10015', $dump);
        $this->assertStringContainsString('/body/ein', $dump);
        $this->assertStringContainsString('req-brand-1', $dump);
        $this->assertStringNotContainsString('12-3456789', $dump);
        $this->assertStringNotContainsString('Invalid ein', $dump);

        // Provider recovers: the same submission goes through and is charged once.
        $this->brandCreateStatus = 200;
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
    }

    public function test_a_carrier_campaign_rejection_reports_the_saved_brand_and_exact_fee_status(): void
    {
        $this->startLowVolume();
        $this->campaignCreateStatus = 422;

        $first = $this->submitLowVolume();
        $this->assertStringContainsString('Step 2 of 2', $first);
        $this->assertStringContainsString('brand is registered and saved', $first);
        $this->assertStringContainsString('its fee will not be charged again', $first);
        $this->assertStringContainsString('campaign fee was not charged', $first);
        $this->assertStringNotContainsString('review your details', $first);
        $this->assertSame(40_000_000 - self::BRAND, $this->balance());

        // Retry on the saved brand: no new brand fee is claimed or taken.
        $retry = $this->submitLowVolume();
        $this->assertStringContainsString('its fee will not be charged again', $retry);
        $this->assertSame(40_000_000 - self::BRAND, $this->balance());
        $this->assertSame(1, $this->posted('/10dlc/brand'));
    }

    public function test_a_missing_or_unconfirmed_support_contact_blocks_submission_before_any_charge_and_is_never_the_brand_contact(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(40_000_000);
        $this->post($this->url('registration.start-local-verification'));

        foreach ([['support_contact' => '', 'support_contact_confirmed' => '1'], ['support_contact' => 'help@fixture.example', 'support_contact_confirmed' => null, 'support_contact_confirmed_for' => ''], ['support_contact' => 'not a contact', 'support_contact_confirmed' => '1']] as $overrides) {
            $this->post($this->url('registration.update'), $this->payload($overrides));
            $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
        }

        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000, $this->balance());
        $row = BusinessMessagingRegistration::where('business_id', $this->business->id)->first();
        $this->assertNotSame('hi@sunsetbooths.example', $row->support_contact); // never silently the brand contact
    }

    public function test_a_saved_brand_with_a_failed_campaign_is_shown_as_brand_registered_and_priced_without_the_paid_brand_fee(): void
    {
        $this->startLowVolume();
        $this->campaignCreateStatus = 422;
        $this->twoStageSubmit($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(40_000_000 - self::BRAND, $this->balance());

        $html = str_replace('&#039;', "'", $this->get($this->url('show'))->assertOk()->getContent());

        // The unified progress screen shows the real saved state of both stages.
        $this->assertStringContainsString('data-role="registration-progress"', $html);
        $this->assertMatchesRegularExpression('#data-role="stage-brand"\s+data-state="verified"#', $html);
        $this->assertMatchesRegularExpression('#data-role="stage-campaign"\s+data-state="issue"#', $html);
        $this->assertStringContainsString('Business verified', $html);
        $this->assertStringContainsString('Submission issue', $html);
        $this->assertStringContainsString('data-role="refresh-status"', $html);
        $this->assertStringContainsString('Last carrier status check', $html);

        // No contradictory wording or actions once the carrier holds a brand.
        $this->assertStringNotContainsString('Nothing has been charged or sent to the carrier yet', $html);
        $this->assertStringNotContainsString('data-role="back-to-number-choices"', $html);
        $this->assertStringNotContainsString('data-role="choose-toll-free-instead"', $html);
        $this->assertStringNotContainsString('One more step', $html);
        $this->assertStringContainsString('data-role="fee-already-paid"', $html);
        $this->assertStringContainsString('is not due again', $html);

        // The upfront preview is only the campaign prepayment.
        $preview = app(\App\Library\Messaging\MessagingChargePreview::class)->forLocalRegistration($this->business, 'low_volume_mixed');
        $this->assertTrue($preview['one_time']['brand_already_paid']);
        $this->assertSame(self::LVM_REGISTRATION, $preview['one_time']['micro']);
        $this->assertStringContainsString('no new brand fee', $preview['one_time']['detail']);

    }

    public function test_changing_a_confirmed_support_contact_without_reaffirming_it_leaves_the_new_one_unconfirmed(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->post($this->url('registration.start-local-verification'));

        $row = fn () => BusinessMessagingRegistration::where('business_id', $this->business->id)->first();
        $confirmed = ['support_contact' => 'help@fixture.example', 'support_contact_confirmed' => '1', 'support_contact_confirmed_for' => 'help@fixture.example'];

        $this->post($this->url('registration.update'), $this->payload($confirmed));
        $this->assertNotNull($row()->support_contact_confirmed_at);
        $firstConfirmedAt = $row()->support_contact_confirmed_at;

        // Unchanged and still ticked: stays confirmed (same confirmation).
        $this->post($this->url('registration.update'), $this->payload($confirmed));
        $this->assertEquals($firstConfirmedAt, $row()->support_contact_confirmed_at);

        // Edited while the stale tick is still posted (no deliberate re-confirmation).
        $this->post($this->url('registration.update'), $this->payload(['support_contact' => 'new@fixture.example'] + $confirmed));
        $this->assertSame('new@fixture.example', $row()->support_contact);
        $this->assertNull($row()->support_contact_confirmed_at);

        // Deliberately reaffirmed for the new value.
        $this->post($this->url('registration.update'), $this->payload(['support_contact' => 'new@fixture.example', 'support_contact_confirmed' => '1', 'support_contact_confirmed_for' => 'new@fixture.example']));
        $this->assertNotNull($row()->support_contact_confirmed_at);

        // The page carries the handshake and clears the tick when the contact is edited.
        $html = $this->get($this->url('show'))->getContent();
        $this->assertStringContainsString('name="support_contact_confirmed_for"', $html);
        $this->assertStringContainsString("$('#support_contact').on('input'", $html);
    }

    // -----------------------------------------------------------------
    // Two-stage 10DLC lifecycle: business verification, then campaign.
    // -----------------------------------------------------------------

    private function refreshUrl(): string
    {
        return $this->url('registration.refresh');
    }

    private function row(): BusinessMessagingRegistration
    {
        return BusinessMessagingRegistration::where('business_id', $this->business->id)->firstOrFail();
    }

    /** The 60-second refresh floor is about customers hammering the button, not about the tests. */
    private function refreshNow(): void
    {
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['brand_status_checked_at' => null, 'campaign_status_checked_at' => null]);
        $this->post($this->refreshUrl());
    }

    private function showHtml(): string
    {
        return str_replace('&#039;', "'", $this->get($this->url('show'))->assertOk()->getContent());
    }

    public function test_a_new_brand_moves_from_processing_to_verified_and_only_then_unlocks_an_authorized_campaign(): void
    {
        $this->startLowVolume();
        $this->brandIdentity = 'UNVERIFIED';

        // Stage 1: one brand, one fee, then the progress screen (never an editable form).
        $this->post($this->url('registration.submit'))->assertRedirect($this->url('show'))->assertSessionHas('status', 'success');
        $this->assertSame(40_000_000 - self::BRAND, $this->balance());
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $this->row()->status);
        $this->assertSame('processing', $this->row()->brand_status);
        $this->assertNull($this->row()->provider_campaign_id);
        $this->assertSame('BXYZ', $this->row()->provider_brand_tcr_id); // saved from the brand response

        $html = $this->showHtml();
        $this->assertStringContainsString('Business verification in progress', $html);
        $this->assertMatchesRegularExpression('#data-role="stage-campaign"\s+data-state="not_started"#', $html);
        $this->assertStringNotContainsString('Failed', $html);
        $this->assertStringNotContainsString('data-role="back-to-number-choices"', $html);
        $this->assertStringNotContainsString('data-role="submit-campaign"', $html);

        // HTTP 200 / "OK" with an UNVERIFIED identity is NOT verification.
        $this->refreshNow();
        $this->assertSame('processing', $this->row()->brand_status);
        $this->assertNotNull($this->row()->provider_brand_tcr_id);

        // The campaign stage refuses to start, without any carrier call or charge.
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])->assertSessionHas('status', 'info');
        $this->assertSame(0, $this->posted('/campaignBuilder'));
        $this->assertSame(40_000_000 - self::BRAND, $this->balance());

        // The carrier verifies the business.
        $this->brandIdentity = 'VERIFIED';
        $this->refreshNow();
        $this->assertSame('verified', $this->row()->brand_status);
        $html = $this->showHtml();
        $this->assertStringContainsString('Business verified', $html);
        $this->assertMatchesRegularExpression('#data-role="stage-campaign"\s+data-state="ready"#', $html);
        $this->assertStringContainsString('data-role="campaign-authorization"', $html);

        // Never submitted automatically: the customer must authorize the fees.
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(0, $this->posted('/campaignBuilder'));

        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])->assertRedirect($this->url('show'))
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'Campaign submitted — awaiting carrier review'));
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
        $this->assertSame('camp_flow_1', $this->row()->provider_campaign_id); // saved before the UI says submitted
        $this->assertSame(MessagingRegistrationStatus::Pending, $this->row()->status);

        $html = $this->showHtml();
        $this->assertMatchesRegularExpression('#data-role="stage-campaign"\s+data-state="under_review"#', $html);
        $this->assertStringContainsString('Campaign submitted — awaiting carrier review', $html);
        $this->assertStringNotContainsString('One more step', $html);
        $this->assertStringNotContainsString('data-role="choose-toll-free-instead"', $html);
        $this->assertStringNotContainsString('Nothing has been charged or sent to the carrier yet', $html);

        // Replays never create a second brand or campaign, nor charge again.
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])->assertSessionHas('status', 'error');
        $this->refreshNow();
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
    }

    public function test_an_eligibility_400_before_verification_is_a_temporary_issue_never_permanent_ineligibility(): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));
        // The page believed the brand verified, but the carrier still says it is not.
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['brand_status' => 'verified']);
        $this->brandIdentity = 'UNVERIFIED';
        $this->qualificationHttp = 400;

        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'yet') && ! str_contains($m, 'not eligible'));
        $row = $this->row();
        $this->assertFalse($row->campaign_issue_permanent);
        $this->assertSame('processing', $row->brand_status);        // sent back to verification, not failed
        $this->assertNull($row->provider_campaign_id);
        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(40_000_000 - self::BRAND, $this->balance()); // the campaign hold was released

        // Once the carrier verifies the brand and answers normally, the same campaign goes through, charged once.
        $this->qualificationHttp = null;
        $this->brandIdentity = 'VERIFIED';
        $this->refreshNow();
        $this->assertNull($this->row()->campaign_issue);
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])->assertSessionHas('status', 'success');
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
    }

    public function test_temporary_carrier_failures_recover_without_duplicate_brands_or_charges(): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));

        // A rate limit / server error while checking the brand is not a verdict.
        foreach ([429, 500, 503] as $http) {
            $this->brandGetHttp = $http;
            $this->refreshNow();
            $this->assertSame('processing', $this->row()->brand_status, "HTTP {$http}");
        }

        $this->brandGetHttp = 200;
        $this->refreshNow();
        $this->assertSame('verified', $this->row()->brand_status);

        // A carrier outage while qualifying: temporary, the hold is released, a retry succeeds.
        foreach ([429, 503] as $http) {
            $this->qualificationHttp = $http;
            $this->post($this->url('registration.submit'), ['authorize_campaign' => '1']);
            $this->assertFalse($this->row()->campaign_issue_permanent, "HTTP {$http}");
            $this->assertNotNull($this->row()->campaign_issue);
            $this->assertSame(40_000_000 - self::BRAND, $this->balance());
        }

        $this->qualificationHttp = null;
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])->assertSessionHas('status', 'success');
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
        $this->assertNull($this->row()->campaign_issue);
    }

    public function test_an_unverified_or_failed_brand_surfaces_needs_attention_truthfully_and_can_recover(): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));

        // Carrier says registration failed: Needs attention, no raw payload shown.
        $this->brandRegistrationStatus = 'REGISTRATION_FAILED';
        $this->refreshNow();
        $this->assertSame('needs_attention', $this->row()->brand_status);
        $html = $this->showHtml();
        $this->assertMatchesRegularExpression('#data-role="stage-brand"\s+data-state="needs_attention"#', $html);
        $this->assertStringContainsString('Needs attention', $html);
        $this->assertStringNotContainsString('REGISTRATION_FAILED', $html);

        // Still merely unverified long after creation: Needs attention, with polling still able to recover.
        $this->brandRegistrationStatus = 'OK';
        $this->brandIdentity = 'UNVERIFIED';
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['brand_created_at' => now()->subDays(4), 'brand_status' => 'processing']);
        $this->refreshNow();
        $this->assertSame('overdue', $this->row()->brand_status); // slow is not rejected

        $this->brandIdentity = 'VERIFIED';
        $this->refreshNow();
        $this->assertSame('verified', $this->row()->brand_status);
        $this->assertSame(1, $this->posted('/10dlc/brand'));
    }

    public function test_the_scheduled_sweep_verifies_brands_and_tracks_campaigns_through_approval_or_rejection(): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['brand_status_checked_at' => null]);

        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->assertSame('verified', $this->row()->brand_status);
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $this->row()->status); // a brand is never polled as a campaign

        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1']);
        $this->assertSame(MessagingRegistrationStatus::Pending, $this->row()->status);

        // Rejected: specific "Needs correction", not a generic failure.
        $this->campaignStatus = 'TCR_FAILED';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->assertSame(MessagingRegistrationStatus::Rejected, $this->row()->status);
        $html = $this->showHtml();
        $this->assertStringContainsString('Needs correction', $html);
        $this->assertMatchesRegularExpression('#data-role="stage-brand"\s+data-state="verified"#', $html);

        // Approved only on the carrier-provisioned status.
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['status' => 'pending']);
        $this->campaignStatus = 'TCR_ACCEPTED';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->assertSame(MessagingRegistrationStatus::Pending, $this->row()->status);
        $this->campaignStatus = 'MNO_PROVISIONED';
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $this->assertSame(MessagingRegistrationStatus::Approved, $this->row()->status);
        $this->assertNotNull($this->row()->campaign_status_checked_at);
    }

    public function test_an_existing_pending_campaign_like_lumina_aura_is_represented_and_never_resubmitted_or_recharged(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(35_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());

        // Production-shaped record created before this lifecycle existed: no brand_status at all.
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update([
            'status' => 'pending', 'provider_brand_id' => 'brand_flow_1', 'provider_brand_tcr_id' => 'BB84JS8',
            'provider_campaign_id' => 'camp_flow_1', 'brand_status' => null, 'submitted_at' => now()->subDay(),
        ]);

        // A campaign is not proof of identity verification: the brand is shown as unconfirmed.
        $html = $this->showHtml();
        $this->assertMatchesRegularExpression('#data-role="stage-brand"\s+data-state="unconfirmed"#', $html);
        $this->assertMatchesRegularExpression('#data-role="stage-campaign"\s+data-state="under_review"#', $html);
        $this->assertStringContainsString('BB84JS8', $html);
        $this->assertStringContainsString('Refresh status', $html);
        foreach (['data-role="back-to-number-choices"', 'data-role="choose-toll-free-instead"', 'Nothing has been charged or sent to the carrier yet', 'One more step', 'data-role="submit-campaign"', 'data-role="submit-business"'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }

        // Neither submit nor refresh nor the sweep creates or charges anything, or resets the status.
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])->assertSessionHas('status', 'error');
        $this->refreshNow();
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();

        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(35_000_000, $this->balance());
        $row = $this->row();
        $this->assertSame(MessagingRegistrationStatus::Pending, $row->status);
        $this->assertSame('brand_flow_1', $row->provider_brand_id);
        $this->assertSame('camp_flow_1', $row->provider_campaign_id);
        $this->assertNotNull($row->campaign_status_checked_at);
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $this->business->id)->count());

        // The carrier's real identityStatus decides: UNVERIFIED stays in progress, VERIFIED confirms it.
        $this->assertSame('verified', $row->brand_status); // the default mock answers VERIFIED
    }

    public function test_a_legacy_campaign_with_an_unverified_brand_is_reconciled_without_any_post_or_wallet_event(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(35_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update([
            'status' => 'pending', 'provider_brand_id' => 'brand_flow_1', 'provider_campaign_id' => 'camp_flow_1',
            'provider_brand_tcr_id' => 'BB84JS8', 'brand_status' => null, 'submitted_at' => now()->subDay(),
        ]);

        // The carrier says the brand is still UNVERIFIED: not confirmed, not rejected, campaign untouched.
        $this->brandIdentity = 'UNVERIFIED';
        $this->refreshNow();
        app(BusinessMessagingRegistrationService::class)->refreshAllPending();
        $row = $this->row();
        $this->assertSame('processing', $row->brand_status);
        $this->assertSame(MessagingRegistrationStatus::Pending, $row->status);
        $html = $this->showHtml();
        $this->assertMatchesRegularExpression('#data-role="stage-brand"\s+data-state="processing"#', $html);
        $this->assertMatchesRegularExpression('#data-role="stage-campaign"\s+data-state="under_review"#', $html);
        $this->assertStringNotContainsString('Needs correction', $html);

        // Later the carrier verifies it.
        $this->brandIdentity = 'VERIFIED';
        $this->refreshNow();
        $this->assertSame('verified', $this->row()->brand_status);
        $this->assertMatchesRegularExpression('#data-role="stage-brand"\s+data-state="verified"#', $this->showHtml());

        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(35_000_000, $this->balance());
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $this->business->id)->count());
        $this->assertSame('camp_flow_1', $this->row()->provider_campaign_id);
        $this->assertSame('BB84JS8', $this->row()->provider_brand_tcr_id);
    }

    private function legacyRejectedWithPaidBrand(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(35_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update([
            'status' => 'rejected', 'provider_brand_id' => 'brand_flow_1', 'provider_campaign_id' => 'camp_flow_1',
            'brand_status' => null, 'rejected_at' => now()->subDay(), 'rejection_reason' => 'campaign sample too vague',
        ]);
    }

    public function test_editing_a_legacy_rejected_registration_never_discards_the_paid_brand_without_authorization(): void
    {
        $this->legacyRejectedWithPaidBrand();

        $this->assertStringContainsString('a new carrier brand fee applies', str_replace('&#039;', "'", $this->showHtml()));

        // Brand-defining edit without the explicit replacement authorization: refused, brand kept.
        $this->post($this->url('registration.update'), $this->payload(['legal_business_name' => 'Other Booths LLC']))->assertSessionHas('status', 'error')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'new carrier brand fee'));
        $row = $this->row();
        $this->assertSame('brand_flow_1', $row->provider_brand_id);
        $this->assertSame('Sunset Photo Booths LLC', $row->legal_business_name);
        $this->assertSame(MessagingRegistrationStatus::Rejected, $row->status);

        // An edit that does not touch brand identity (e.g. the campaign wording) keeps the brand too.
        $this->post($this->url('registration.update'), $this->payload(['campaign_description' => 'Sunset Photo Booths sends booking confirmations and reminders to customers who opted in.']))->assertSessionHas('status', 'success');
        $this->assertSame('brand_flow_1', $this->row()->provider_brand_id);
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(35_000_000, $this->balance());

        // Only with the explicit, fee-disclosed authorization is it replaced locally, still with no charge until submit.
        $this->post($this->url('registration.update'), $this->payload(['legal_business_name' => 'Other Booths LLC', 'authorize_brand_replacement' => '1']))->assertSessionHas('status', 'success');
        $this->assertNull($this->row()->provider_brand_id);
        $this->assertSame(35_000_000, $this->balance());
    }

    public function test_retrying_a_legacy_rejected_registration_reconciles_and_reuses_its_paid_brand(): void
    {
        $this->legacyRejectedWithPaidBrand();

        // Resubmitting without edits: the brand is reconciled with the carrier and reused, never re-bought.
        $this->brandIdentity = 'UNVERIFIED';
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1']);
        $row = $this->row();
        $this->assertSame('brand_flow_1', $row->provider_brand_id);
        $this->assertSame('processing', $row->brand_status);
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(35_000_000, $this->balance());

        $this->brandIdentity = 'VERIFIED';
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error'); // still needs authorization
        $this->assertNull($this->row()->provider_campaign_id);
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])->assertSessionHas('status', 'success');

        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(1, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame('brand_flow_1', $this->row()->provider_brand_id);
        $this->assertSame(35_000_000 - self::REGISTRATION, $this->balance()); // campaign prepayment only, no brand fee
    }

    public function test_the_progress_copy_does_not_promise_a_check_cadence(): void
    {
        $this->legacyRejectedWithPaidBrand();
        $html = $this->showHtml();
        $this->assertStringNotContainsString('every few minutes', $html);
    }

    public function test_a_brand_only_record_is_tracked_as_a_brand_and_never_as_a_pending_campaign(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(35_500_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->payload());
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['provider_brand_id' => 'brand_flow_1', 'brand_status' => null]);

        $this->assertMatchesRegularExpression('#data-role="stage-brand"\s+data-state="processing"#', $this->showHtml());
        $this->refreshNow();

        $row = $this->row();
        $this->assertSame('verified', $row->brand_status);
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $row->status);
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(35_500_000, $this->balance());
    }

    public function test_the_progress_screen_only_acts_on_the_signed_in_businesses_registration(): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));
        $before = $this->row()->brand_status_checked_at;

        // Another Business's workspace/business uid in the URL is refused; nothing is refreshed.
        $other = route('customer.workspaces.businesses.text-messaging.registration.refresh', [$this->workspace->uid, 'not-this-business']);
        $this->assertContains($this->post($other)->getStatusCode(), [302, 403, 404]);
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertEquals($before, $this->row()->fresh()->brand_status_checked_at);
    }

    public function test_numbers_stay_blocked_until_the_campaign_is_approved(): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));
        $this->refreshNow();
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1']);

        // Brand verified and campaign merely under review: still the registration screen, no ready/number state.
        $this->get($this->url('show'))->assertOk()->assertViewIs('customer.settings.text-messaging.states.registration');
        $this->assertSame(0, BusinessMessagingNumber::query()->count());
    }

    // -----------------------------------------------------------------
    // Review corrections for the two-stage lifecycle.
    // -----------------------------------------------------------------

    private function pendingCampaign(string $status = 'pending'): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));
        $this->refreshNow();
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1']);
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['status' => $status]);
    }

    public function test_an_inconclusive_status_lookup_never_regresses_a_confirmed_campaign_status(): void
    {
        foreach (['approved' => 'approved', 'rejected' => 'rejected', 'pending' => 'pending'] as $state => $expected) {
            DB::table('business_messaging_registrations')->delete();
            $this->pendingCampaign($state);

            foreach (['429', '500', '503', 'timeout', 'malformed'] as $mode) {
                $this->campaignGetMode = $mode;
                $this->refreshNow();
                app(BusinessMessagingRegistrationService::class)->refreshAllPending();
                $this->assertSame($expected, $this->row()->status->value, "{$state} must survive {$mode}");
            }

            $this->campaignGetMode = 'ok';
        }
    }

    public function test_an_inconclusive_refresh_is_reported_honestly_and_a_conclusive_one_as_checked(): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));

        $this->brandGetHttp = 503;
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['brand_status_checked_at' => null]);
        $this->post($this->refreshUrl())
            ->assertSessionHas('status', 'info')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, "didn't give a clear answer") && ! str_contains($m, 'Checked with the carrier') && ! str_contains($m, 'refreshed'));
        $this->assertNull($this->row()->brand_status_checked_at);

        $this->brandGetHttp = 200;
        $this->post($this->refreshUrl())->assertSessionHas('status', 'success')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'Checked with the carrier just now'));
        $this->assertNotNull($this->row()->brand_status_checked_at);
    }

    public function test_an_uncertain_qualification_refusal_is_a_temporary_issue_that_keeps_the_brand_and_charges_nothing_more(): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));
        $this->refreshNow();
        $logged = $this->captureWarnings();

        foreach ([400, 403, 422] as $http) {
            $this->qualificationHttp = $http;
            $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])
                ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'did not say why') && ! str_contains($m, 'not eligible') && ! str_contains($m, 'notified'));

            $row = $this->row();
            $this->assertFalse($row->campaign_issue_permanent, "HTTP {$http}");
            $this->assertNotNull($row->campaign_issue);
            $this->assertSame('brand_flow_1', $row->provider_brand_id);
            $this->assertNull($row->provider_campaign_id);
            $this->assertSame('low_volume_mixed', $row->use_case); // never silently switched
            $this->assertSame(40_000_000 - self::BRAND, $this->balance());
        }

        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $dump = json_encode($logged->getArrayCopy(), JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('"stage":"qualification"', $dump);
        $this->assertStringNotContainsString('12-3456789', $dump);
        $this->assertMatchesRegularExpression('#data-state="issue"#', $this->showHtml());

        // Same campaign type succeeds once the carrier answers normally.
        $this->qualificationHttp = null;
        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])->assertSessionHas('status', 'success');
        $this->assertSame(40_000_000 - self::BRAND - self::LVM_REGISTRATION, $this->balance());
    }

    public function test_only_a_configured_structured_error_code_is_confirmed_ineligibility(): void
    {
        config(['messaging.qualification_ineligible_codes' => ['10016']]);
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));
        $this->refreshNow();
        $this->lvmEligible = false; // 422 with errors[0].code 10016

        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1'])
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'not eligible for a Low Volume Mixed campaign'));
        $this->assertTrue($this->row()->campaign_issue_permanent);
        $this->assertSame(0, $this->posted('/10dlc/campaignBuilder'));
    }

    public function test_an_overdue_brand_is_not_rejected_cannot_be_edited_away_and_never_buys_a_second_brand(): void
    {
        $this->startLowVolume();
        $this->brandIdentity = 'UNVERIFIED';
        $this->post($this->url('registration.submit'));
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['brand_created_at' => now()->subDays(4)]);
        $this->refreshNow();

        $this->assertSame('overdue', $this->row()->brand_status);
        $html = $this->showHtml();
        $this->assertStringContainsString('Verification taking longer than expected', $html);
        $this->assertStringContainsString('has not rejected your business', $html);
        $this->assertStringNotContainsString('data-role="brand-replacement"', $html);

        // An ordinary edit to brand-defining details is refused and erases nothing.
        $this->post($this->url('registration.update'), $this->lowVolumePayload(['legal_business_name' => 'Renamed LLC', 'ein' => '99-9999999']))
            ->assertSessionHas('status', 'error');
        $row = $this->row();
        $this->assertSame('brand_flow_1', $row->provider_brand_id);
        $this->assertSame('Sunset Photo Booths LLC', $row->legal_business_name);

        // Even with a replacement tick: overdue is not a terminal rejection.
        $this->post($this->url('registration.update'), $this->lowVolumePayload(['legal_business_name' => 'Renamed LLC', 'authorize_brand_replacement' => '1']))
            ->assertSessionHas('status', 'error');
        $this->assertSame('brand_flow_1', $this->row()->provider_brand_id);

        $this->post($this->url('registration.submit'), ['authorize_campaign' => '1']);
        $this->assertSame(1, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000 - self::BRAND, $this->balance());

        // The carrier finally verifies it: recovers without anything being re-bought.
        $this->brandIdentity = 'VERIFIED';
        $this->refreshNow();
        $this->assertSame('verified', $this->row()->brand_status);
    }

    public function test_an_explicitly_rejected_brand_is_replaced_only_after_explicit_authorization_and_a_new_fee(): void
    {
        $this->startLowVolume();
        $this->post($this->url('registration.submit'));
        $this->brandRegistrationStatus = 'REGISTRATION_FAILED';
        $this->refreshNow();
        $this->assertSame('needs_attention', $this->row()->brand_status);

        $changed = ['legal_business_name' => 'Sunset Photo Booths Inc'];
        $this->assertStringContainsString('data-role="brand-replacement"', $this->showHtml());

        // Without the authorization nothing is replaced.
        $this->post($this->url('registration.update'), $this->lowVolumePayload($changed))->assertSessionHas('status', 'error');
        $this->assertSame('brand_flow_1', $this->row()->provider_brand_id);

        // Authorized: the rejected brand is released locally and the corrected one is a NEW paid brand.
        $this->post($this->url('registration.update'), $this->lowVolumePayload($changed + ['authorize_brand_replacement' => '1']))->assertSessionHas('status', 'success');
        $this->assertNull($this->row()->provider_brand_id);
        $this->assertNull($this->row()->brand_status);
        $this->assertSame(40_000_000 - self::BRAND, $this->balance()); // editing alone charges nothing

        $this->brandRegistrationStatus = 'OK';
        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'success');
        $this->assertSame(2, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000 - 2 * self::BRAND, $this->balance());
    }

    public function test_a_sole_proprietor_is_blocked_before_any_charge_with_accurate_guidance(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->fund(40_000_000);
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->lowVolumePayload(['entity_type' => 'sole_proprietor', 'ein' => '']));

        $html = str_replace('&#039;', "'", $this->get($this->url('show'))->getContent());
        $this->assertStringContainsString('one-time PIN text from the carrier', $html);
        $this->assertStringContainsString('Register with your business\'s EIN instead, or use a toll-free number', $html);

        $this->post($this->url('registration.submit'))->assertSessionHas('status', 'error');
        $this->assertSame(0, $this->posted('/10dlc/brand'));
        $this->assertSame(40_000_000, $this->balance());
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $this->business->id)->count());
    }

    public function test_an_existing_sole_proprietor_brand_is_told_the_truth_instead_of_polling_forever(): void
    {
        $this->configureRates();
        $this->telnyx();
        $this->post($this->url('registration.start-local-verification'));
        $this->post($this->url('registration.update'), $this->lowVolumePayload(['entity_type' => 'sole_proprietor', 'ein' => '']));
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['provider_brand_id' => 'brand_flow_1', 'brand_status' => 'processing']);

        $html = str_replace('&#039;', "'", $this->showHtml());
        $this->assertStringContainsString('need a one-time PIN text from the carrier', $html);
        $this->assertStringContainsString('may not finish on its own', $html);
    }

    public function test_provider_failure_and_overdue_messages_never_claim_unrecorded_support_action(): void
    {
        $this->startLowVolume();
        $this->brandCreateStatus = 422;
        $message = $this->submitLowVolume();
        $this->assertStringNotContainsString('notified', $message);
        $this->assertStringNotContainsString('reviewing', $message);
        $this->assertStringContainsString('contact support', $message);

        $this->brandCreateStatus = 200;
        $this->post($this->url('registration.submit'));
        $this->brandIdentity = 'UNVERIFIED';
        DB::table('business_messaging_registrations')->where('business_id', $this->business->id)->update(['brand_created_at' => now()->subDays(4)]);
        $this->refreshNow();
        $html = $this->showHtml();
        $this->assertStringNotContainsString('Support has been asked', $html);
    }

    public function test_a_price_change_between_search_and_order_requires_the_customer_to_confirm_again(): void
    {
        $this->configureRates();
        $this->fund(10_000_000);
        $this->telnyx();

        $search = $this->search('toll_free');
        $this->assertStringContainsString('USD 1.25', $search->getContent());

        // Telnyx re-prices the number after the customer saw the quote.
        $this->numberCost = ['upfront_cost' => '0.25', 'monthly_cost' => '2.00', 'currency' => 'USD'];

        $this->order($search)->assertSessionHas('status', 'error')
            ->assertSessionHas('message', fn (string $m): bool => str_contains($m, 'changed') && str_contains($m, 'confirm the new price'));
        $this->assertSame(0, $this->posted('/number_orders'));
        $this->assertSame(10_000_000, $this->balance());

        // A fresh search shows and charges the new price.
        $second = $this->search('toll_free');
        $this->assertStringContainsString('USD 2.25', $second->getContent());
        $this->order($second)->assertSessionHas('status', 'success');
        $this->assertSame(10_000_000 - 2_250_000, $this->balance());
        $this->assertSame(2_000_000, (int) BusinessMessagingNumber::query()->value('monthly_cost_micro'));
    }

    public function test_the_recorded_quote_drives_the_first_renewal_charge_and_never_charges_the_first_month_twice(): void
    {
        $this->configureRates();
        $this->fund(10_000_000);
        $this->telnyx();

        $this->order($this->search('toll_free'))->assertSessionHas('status', 'success');
        $this->assertSame(10_000_000 - self::PURCHASE, $this->balance()); // upfront 0.25 + first month 1.00

        $number = BusinessMessagingNumber::query()->firstOrFail();
        $this->assertSame([250_000, 1_000_000, 'USD'], [(int) $number->upfront_cost_micro, (int) $number->monthly_cost_micro, $number->cost_currency]);
        // The first month is already paid: renewal is not due until next month.
        $this->assertTrue($number->next_renewal_at->isFuture());
        $this->assertSame([], app(NumberLifecycleManager::class)->numbersDueForRenewal()->all());
    }
}
