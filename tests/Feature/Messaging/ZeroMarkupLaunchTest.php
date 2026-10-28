<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\NumberRenewalOutcome;
use App\Library\Messaging\ChargeCarrierFee;
use App\Library\Messaging\NumberLifecycleManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Notifications\Messaging\NumberReleaseNoticeNotification;
use App\Notifications\Messaging\NumberRenewalWarningNotification;
use App\Notifications\Messaging\NumberSuspendedNotification;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Zero-markup launch rules for what happens AFTER a number is bought:
 * each rental renewal passes the number's own recorded carrier price
 * through (times the owner's multiplier), a real carrier charge can be
 * billed explicitly and exactly once, and the unpaid-number notices say
 * what the carrier keeps billing while a number is held.
 */
class ZeroMarkupLaunchTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesMessagingFixtures;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
    }

    private function admin(): User
    {
        return User::create(['first_name' => 'Ops', 'last_name' => 'Admin', 'email' => 'ops' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
    }

    private function meter(string $key, string $rateMicro): void
    {
        $actor = $this->admin()->id;
        $wallet = app(UsageWalletManager::class);

        if (! DB::table('usage_meters')->where('meter_key', $key)->exists()) {
            app(UsageMeterRepository::class)->create([
                'meter_key' => $key, 'feature_key' => PlatformFeature::MessagingTransport->value, 'business_id' => null,
                'currency_id' => $this->currencyId, 'description' => 'Pass-through fixture meter (test-only).', 'updated_by_user_id' => $actor,
            ]);
        }

        $wallet->setActiveRate($key, $rateMicro, '0', 'per 1.00 of carrier cost', $this->currencyId, $actor, 'Test rate.');
        $wallet->activateMetering($key, $actor, 'Test metering.');
    }

    private function fundedBusiness(int $balanceMicro): Business
    {
        $business = $this->makeBusiness();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => $balanceMicro]);

        return $business;
    }

    private function balance(Business $business): int
    {
        return (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro');
    }

    private function numberDue(Business $business, ?int $monthlyCostMicro)
    {
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber());
        DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'next_renewal_at' => now()->subDay(), 'monthly_cost_micro' => $monthlyCostMicro, 'cost_currency' => $monthlyCostMicro === null ? null : 'USD',
        ]);

        return $number->fresh();
    }

    public function test_a_renewal_passes_through_the_numbers_own_recorded_price_at_the_owner_multiplier_exactly_once(): void
    {
        $this->meter(NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, '1000000'); // zero markup
        $business = $this->fundedBusiness(10_000_000);
        $number = $this->numberDue($business, 2_350_000); // this number rents at 2.35, not any flat rate
        $manager = app(NumberLifecycleManager::class);

        $this->assertSame(NumberRenewalOutcome::Succeeded, $manager->attemptRenewal($number));
        $this->assertSame(10_000_000 - 2_350_000, $this->balance($business));

        $this->assertSame(NumberRenewalOutcome::AlreadyProcessed, $manager->attemptRenewal($number->fresh()->forceFill(['next_renewal_at' => $number->next_renewal_at])));
        $this->assertSame(10_000_000 - 2_350_000, $this->balance($business));
    }

    public function test_a_markup_multiplier_is_applied_only_if_the_owner_sets_one(): void
    {
        $this->meter(NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, '1100000'); // +10%, owner's explicit choice
        $business = $this->fundedBusiness(10_000_000);

        app(NumberLifecycleManager::class)->attemptRenewal($this->numberDue($business, 2_000_000));

        $this->assertSame(10_000_000 - 2_200_000, $this->balance($business));
    }

    public function test_a_number_with_no_recorded_carrier_price_is_never_charged_a_guess(): void
    {
        $this->meter(NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, '1000000');
        $business = $this->fundedBusiness(10_000_000);
        $number = $this->numberDue($business, null);

        $this->assertSame(NumberRenewalOutcome::NotConfigured, app(NumberLifecycleManager::class)->attemptRenewal($number));

        $this->assertSame(10_000_000, $this->balance($business));
        $this->assertSame(BusinessMessagingNumberStatus::Active, $number->fresh()->status);
    }

    public function test_an_insufficient_renewal_suspends_with_a_fourteen_day_grace_and_releases_nothing(): void
    {
        $this->assertSame(14, (int) config('messaging.number_renewal_grace_days'));
        $this->meter(NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL, '1000000');
        $business = $this->fundedBusiness(500_000);
        $number = $this->numberDue($business, 1_000_000);

        $this->assertSame(NumberRenewalOutcome::InsufficientFunds, app(NumberLifecycleManager::class)->attemptRenewal($number));

        $fresh = $number->fresh();
        $this->assertSame(BusinessMessagingNumberStatus::Suspended, $fresh->status);
        $this->assertSame(14, (int) round($fresh->suspended_at->diffInDays($fresh->grace_expires_at, true)));
        $this->assertNull($fresh->released_at);
        $this->assertSame(500_000, $this->balance($business));
    }

    public function test_the_unpaid_number_notices_explain_what_the_carrier_keeps_billing(): void
    {
        $suspended = (new NumberSuspendedNotification('+18885550100', now()->addDays(14)))->toMail(new \stdClass());
        $release = (new NumberReleaseNoticeNotification('+18885550100'))->toMail(new \stdClass());
        $warning = (new NumberRenewalWarningNotification('+18885550100', now()->addDays(5), ['Number rental: USD 1.00 per month']))->toMail(new \stdClass());

        $this->assertStringContainsString('carrier keeps billing us', implode(' ', $suspended->introLines));
        $this->assertStringContainsString('does not happen automatically', implode(' ', $suspended->introLines));
        $this->assertStringContainsString('carrier keeps billing us', implode(' ', $release->introLines));
        $this->assertStringContainsString('port it out', implode(' ', $release->introLines));
        $this->assertContains('Number rental: USD 1.00 per month', $warning->introLines);
        $this->assertStringContainsString('passed through at cost', implode(' ', $warning->introLines));
    }

    // -----------------------------------------------------------------
    // Explicit, idempotent billing of a real carrier charge.
    // -----------------------------------------------------------------

    public function test_a_real_carrier_charge_is_billed_exactly_at_cost_and_only_once(): void
    {
        $this->meter(ChargeCarrierFee::FEATURE, '1000000');
        $business = $this->fundedBusiness(50_000_000);
        $fees = app(ChargeCarrierFee::class);

        $this->assertSame('charged', $fees->charge($business, 15_000_000, 'telnyx-invoice-line-1')['status']);
        $this->assertSame(35_000_000, $this->balance($business));

        $this->assertSame('already_charged', $fees->charge($business, 15_000_000, 'telnyx-invoice-line-1')['status']);
        $this->assertSame(35_000_000, $this->balance($business));

        // The charge sits on THIS Business's ledger only.
        $other = $this->fundedBusiness(50_000_000);
        $this->assertSame(50_000_000, $this->balance($other));
    }

    public function test_a_carrier_charge_the_wallet_cannot_pay_is_refused_and_never_creates_hidden_debt(): void
    {
        $this->meter(ChargeCarrierFee::FEATURE, '1000000');
        $business = $this->fundedBusiness(5_000_000);

        $outcome = app(ChargeCarrierFee::class)->charge($business, 15_000_000, 'telnyx-invoice-line-2');

        $this->assertSame('refused', $outcome['status']);
        $this->assertSame(5_000_000, $this->balance($business));
        $this->assertSame(0, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('debt_balance_micro'));
    }

    public function test_an_unconfigured_pass_through_price_charges_nothing_and_the_command_demands_authorization(): void
    {
        $business = $this->fundedBusiness(50_000_000);

        $this->assertSame('not_configured', app(ChargeCarrierFee::class)->charge($business, 15_000_000, 'op-x')['status']);

        $nonAdmin = User::create(['first_name' => 'C', 'last_name' => 'U', 'email' => 'c' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer']);
        $this->artisan('messaging:charge-carrier-fee', ['business' => $business->id, 'carrier-cost-micro' => '15000000', '--operation-id' => 'op-y', '--reason' => 'review', '--actor-user-id' => (string) $nonAdmin->id])->assertFailed();
        $this->artisan('messaging:charge-carrier-fee', ['business' => $business->id, 'carrier-cost-micro' => '15000000', '--reason' => 'review', '--actor-user-id' => (string) $this->admin()->id])->assertFailed();

        $this->assertSame(50_000_000, $this->balance($business));
    }

    public function test_the_command_dry_run_charges_nothing_and_a_real_run_charges_once(): void
    {
        $this->meter(ChargeCarrierFee::FEATURE, '1000000');
        $business = $this->fundedBusiness(50_000_000);
        $args = ['business' => $business->id, 'carrier-cost-micro' => '15000000', '--operation-id' => 'telnyx-review-7', '--reason' => 'Campaign review fee', '--actor-user-id' => (string) $this->admin()->id];

        $this->artisan('messaging:charge-carrier-fee', $args + ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(50_000_000, $this->balance($business));

        $this->artisan('messaging:charge-carrier-fee', $args)->assertSuccessful();
        $this->artisan('messaging:charge-carrier-fee', $args)->expectsOutputToContain('already charged')->assertSuccessful();
        $this->assertSame(35_000_000, $this->balance($business));
    }
}
