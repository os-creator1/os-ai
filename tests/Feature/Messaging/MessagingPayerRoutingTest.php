<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Messaging\NumberLifecycleManager;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Usage\Concerns\AgencyRebillFixtures;
use Tests\TestCase;

/**
 * Whose wallet a managed number purchase lands on. Every paid messaging
 * effect goes through UsageWalletManager::reserve(), so it inherits the
 * canonical payer rules (an Agency-funded client under standing consent is
 * charged on the CLIENT Business's own ledger; an Agency's own Business on
 * its own); these tests pin that for the new per-number pass-through
 * charges and prove nothing leaks onto another Business's ledger. BYO
 * Telnyx never reaches this billing at all (see ByoMessageDispatcher).
 */
class MessagingPayerRoutingTest extends TestCase
{
    use AgencyRebillFixtures;
    use RefreshDatabase;

    private function adminId(): int
    {
        return User::create(['first_name' => 'Ops', 'last_name' => 'Admin', 'email' => 'ops' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin'])->id;
    }

    private function passThroughMeters(): void
    {
        $actor = $this->adminId();
        $wallet = app(UsageWalletManager::class);
        $currency = \App\Models\Currency::query()->first()->id;

        foreach ([TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE, NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL] as $key) {
            if (! DB::table('usage_meters')->where('meter_key', $key)->exists()) {
                app(UsageMeterRepository::class)->create(['meter_key' => $key, 'feature_key' => PlatformFeature::MessagingTransport->value, 'business_id' => null, 'currency_id' => $currency, 'description' => 'Pass-through fixture (test-only).', 'updated_by_user_id' => $actor]);
                $wallet->setActiveRate($key, '1000000', '0', 'per 1.00 of carrier cost', $currency, $actor, 'Test.');
                $wallet->activateMetering($key, $actor, 'Test.');
            }
        }

        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [['phone_number' => '+18885550190', 'cost_information' => ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD']]]]),
            'api.telnyx.com/v2/messaging_profiles' => Http::response(['data' => ['id' => 'mp_payer']]),
            'api.telnyx.com/v2/number_orders' => Http::response(['data' => ['phone_numbers' => [['id' => 'pn_payer', 'status' => 'success']]]]),
        ]);
    }

    private function buy(Business $business): void
    {
        app(TelnyxProvisioningAdapter::class)->provisionNumber($business, new AvailableNumberCandidate('+18885550190', PhoneNumberType::TollFree, '+18885550190', 250_000, 1_000_000, 'USD'));
    }

    private function ledgerBusinessIds(): array
    {
        return DB::table('business_usage_ledger_entries')->distinct()->pluck('business_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_an_agency_funded_client_purchase_is_charged_on_the_clients_own_ledger_only(): void
    {
        $m = $this->rebilledClient();
        [, $agencyBusiness] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency HQ', 'Agency HQ Account');
        $this->fund($m['business'], 10_000_000);
        $this->fund($agencyBusiness, 10_000_000);
        $this->passThroughMeters();

        $this->buy($m['business']);

        $this->assertSame(10_000_000 - 1_250_000, (int) $this->walletRow($m['business'])->available_balance_micro);
        $this->assertSame(10_000_000, (int) $this->walletRow($agencyBusiness)->available_balance_micro, "The Agency's own Business wallet is never touched by a client's purchase.");
        $this->assertSame([(int) $m['business']->id], $this->ledgerBusinessIds());
    }

    public function test_an_agency_owned_business_pays_from_its_own_wallet(): void
    {
        [, $agencyBusiness] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency HQ', 'Agency HQ Account');
        $m = $this->rebilledClient();
        $this->fund($agencyBusiness, 10_000_000);
        $this->fund($m['business'], 10_000_000);
        $this->passThroughMeters();

        $this->buy($agencyBusiness);

        $this->assertSame(10_000_000 - 1_250_000, (int) $this->walletRow($agencyBusiness)->available_balance_micro);
        $this->assertSame(10_000_000, (int) $this->walletRow($m['business'])->available_balance_micro);
        $this->assertSame([(int) $agencyBusiness->id], $this->ledgerBusinessIds());
    }

    public function test_an_unfunded_client_is_refused_as_a_plain_shortfall_before_any_order_is_placed(): void
    {
        $m = $this->rebilledClient();
        $this->passThroughMeters();

        try {
            $this->buy($m['business']);
            $this->fail('Expected a funding refusal.');
        } catch (MessagingInsufficientFundsException $e) {
            $this->assertSame('insufficient_balance', $e->denialReason);
        }

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame([], $this->ledgerBusinessIds() === [] ? [] : array_diff($this->ledgerBusinessIds(), [(int) $m['business']->id]));
    }
}
