<?php

namespace Tests\Feature\Messaging;

use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\Exceptions\MessagingFundingUnavailableException;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\BusinessMessagingRegistration;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * The platform owner's activation path for the messaging provisioning
 * fees: it never invents a price, never charges anyone, and an unfunded
 * wallet still blocks the fee after activation.
 */
class ActivateMessagingProvisioningRateCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private const KEY = TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Owner', 'last_name' => 'Admin', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
    }

    private function activate(User $actor, array $extra = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('messaging:activate-provisioning-rate', array_merge([
            'feature' => '10dlc', 'retail-rate-micro' => '4000000', 'provider-cost-micro' => '2000000',
            'unit-label' => 'per registration', 'currency-code' => 'USD',
            '--actor-user-id' => (string) $actor->id, '--reason' => 'Approved by owner, test fixture.', '--yes' => true,
        ], $extra));
    }

    /** A paid local registration also needs its recurring campaign price configured. */
    private function activateCampaignRenewal(User $actor): void
    {
        $this->activate($actor, ['feature' => 'campaign-renewal', 'retail-rate-micro' => '1500000'])->assertSuccessful();
        $this->activate($actor, ['feature' => '10dlc-brand', 'retail-rate-micro' => '1000000', 'unit-label' => 'per brand'])->assertSuccessful();
    }

    private function registration(Business $business): MessagingRegistrationSubmission
    {
        $registration = BusinessMessagingRegistration::create([
            'business_id' => $business->id, 'number_type' => 'local', 'entity_type' => 'ein', 'use_case' => 'customer_care', 'region' => 'OR', 'brand_vertical' => 'ENTERTAINMENT', 'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner',
            'campaign_description' => 'Harbor Lane sends appointment reminders.', 'status' => 'not_started',
        ]);

        return MessagingRegistrationSubmission::fromModel($registration->fresh());
    }

    private function business(): Business
    {
        return $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
    }

    public function test_without_a_meter_the_fee_is_reported_as_missing_pricing_not_missing_funds(): void
    {
        Http::fake();
        $business = $this->business();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($this->registration($business));
            $this->fail('Expected funding unavailable.');
        } catch (MessagingFundingUnavailableException $e) {
            $this->assertSame(MessagingFundingUnavailableException::REASON_PRICING_NOT_CONFIGURED, $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $this->activate($this->admin(), ['--dry-run' => true, '--yes' => false])
            ->expectsOutputToContain('does not exist')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('usage_meters')->count());
        $this->assertSame(0, DB::table('business_usage_rates')->count());
    }

    public function test_it_refuses_a_zero_rate_a_missing_reason_and_a_non_admin_actor(): void
    {
        $admin = $this->admin();
        $customer = User::create(['first_name' => 'C', 'last_name' => 'U', 'email' => 'c' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer']);

        $this->activate($admin, ['retail-rate-micro' => '0'])->assertFailed();
        $this->activate($admin, ['--reason' => ''])->assertFailed();
        $this->activate($customer)->assertFailed();

        $this->assertSame(0, DB::table('usage_meters')->count());
    }

    public function test_a_one_time_price_alone_is_not_enough_the_recurring_price_must_be_configured_too(): void
    {
        $this->activate($this->admin())->assertSuccessful();
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 50_000_000]);
        Http::fake();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($this->registration($business));
            $this->fail('Expected funding unavailable.');
        } catch (MessagingFundingUnavailableException $e) {
            $this->assertSame(MessagingFundingUnavailableException::REASON_PRICING_NOT_CONFIGURED, $e->reason);
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    public function test_activation_creates_one_metered_platform_meter_and_is_idempotent(): void
    {
        $admin = $this->admin();

        $this->activate($admin)->assertSuccessful();
        $this->activate($admin)->expectsOutputToContain('nothing to do')->assertSuccessful();
        $this->activate($admin, ['retail-rate-micro' => '5000000'])->assertFailed();

        $meter = DB::table('usage_meters')->where('meter_key', self::KEY)->first();
        $this->assertSame(1, DB::table('usage_meters')->where('meter_key', self::KEY)->count());
        $this->assertNull($meter->business_id);
        $this->assertSame(1, (int) $meter->is_metered);
        $this->assertSame(1, DB::table('business_usage_rates')->where('meter_key', self::KEY)->count());
        $this->assertSame('4000000', (string) DB::table('business_usage_rates')->where('meter_key', self::KEY)->value('retail_rate_micro'));
    }

    public function test_after_activation_an_unfunded_wallet_is_blocked_as_insufficient_funds_and_a_funded_one_is_charged_exactly_once(): void
    {
        $admin = $this->admin();
        $this->activate($admin)->assertSuccessful();
        $this->activateCampaignRenewal($admin);

        $unfunded = $this->business();
        Http::fake([
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['data' => ['brandId' => 'brand_cfg_1']]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['data' => ['campaignId' => 'camp_cfg_1']]),
        ]);

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($this->registration($unfunded));
            $this->fail('Expected insufficient funds.');
        } catch (MessagingInsufficientFundsException) {
            // expected: pricing is configured, the customer's balance is the issue
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $unfunded->id)->count());

        $funded = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($funded->id);
        DB::table('business_usage_wallets')->where('business_id', $funded->id)->update(['available_balance_micro' => 10_000_000]);

        app(TelnyxProvisioningAdapter::class)->submitRegistration($this->registration($funded));

        $this->assertSame(['committed', 'committed'], DB::table('business_usage_reservations')->where('business_id', $funded->id)->pluck('status')->all());
        // 10.00 - 4.00 prepaid campaign months - 1.00 brand fee.
        $this->assertSame(5_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $funded->id)->value('available_balance_micro'));
    }

    public function test_a_wallet_in_a_different_currency_is_reported_as_a_wallet_configuration_problem(): void
    {
        $admin = $this->admin();
        $this->activate($admin)->assertSuccessful();
        $this->activateCampaignRenewal($admin);
        $eur = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => 'E', 'status' => true]);
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['currency_id' => $eur->id]);
        Http::fake();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($this->registration($business));
            $this->fail('Expected funding unavailable.');
        } catch (MessagingFundingUnavailableException $e) {
            $this->assertSame(MessagingFundingUnavailableException::REASON_WALLET_CONFIGURATION, $e->reason);
        }

        Http::assertNothingSent();
    }
}
