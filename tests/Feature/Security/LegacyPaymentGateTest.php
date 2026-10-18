<?php

namespace Tests\Feature\Security;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoices;
use App\Models\PaymentMethods;
use App\Models\Plan;
use App\Models\Senderid;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Security\Concerns\PreparesSecurityTenants;
use Tests\TestCase;

/**
 * F4: legacy (Ultimate SMS) payment success / callback / initiation routes.
 *
 * Several success handlers ("2checkout", "payu", "coinpayments", ...) never ask the provider anything: they trust the
 * query string (payment_method, user_id, sms_unit, price) and then grant credit, activate sender IDs or create paid
 * invoices. These routes are now fail-closed behind LegacyPaymentGate (config legacy_payments.enabled_gateways, empty
 * by default). The V1 payment flows (Stripe webhooks, business payments, platform/agency billing) are separate routes.
 */
class LegacyPaymentGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use PreparesSecurityTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPlaceholderSuperAdmin();
        $this->ensureRequiredAppConfigRowsExist();
    }

    private function gateway(string $type, bool $active = true): PaymentMethods
    {
        return PaymentMethods::create(['name' => $type, 'type' => $type, 'options' => json_encode(['x' => 1]), 'status' => $active]);
    }

    private function customer(): Customer
    {
        $c = $this->createCustomer();
        $c->user->email_verified_at = now();
        $c->user->save();
        $c->forceFill(['country' => 'United States'])->save();
        $currency = Currency::query()->first() ?? Currency::create(['name' => 'Test Dollar', 'code' => 'CGT', 'format' => '$', 'status' => true]);
        $plan = Plan::create(['user_id' => $c->user_id, 'name' => 'P', 'price' => 10, 'billing_cycle' => 'monthly', 'frequency_amount' => 1,
            'frequency_unit' => 'month', 'currency_id' => $currency->id, 'options' => json_encode([]), 'status' => true]);
        Subscription::create(['user_id' => $c->user_id, 'plan_id' => $plan->id, 'status' => Subscription::STATUS_ACTIVE, 'paid' => true, 'start_at' => now(), 'end_at' => null]);

        return $c->fresh();
    }

    private function actAs(Customer $c): void
    {
        $this->withSession(['permissions' => collect(['access_backend', 'create_sender_id', 'view_sender_id', 'manage_subscription', 'top_up'])]);
        $this->actingAs($c->user);
    }

    public function test_unverified_topup_success_cannot_credit_sms_units(): void
    {
        $this->gateway(PaymentMethods::TYPE_2CHECKOUT);
        $attacker = $this->customer();
        $this->actAs($attacker);
        $before = (int) User::find($attacker->user_id)->sms_unit;

        $this->call('POST', route('customer.top_up.payment_success'), [
            'payment_method' => '2checkout', 'user_id' => $attacker->user_id, 'sms_unit' => 1000000, 'price' => 1, 'total_amount' => 1, 'tax_amount' => 0,
        ]);

        $this->assertSame($before, (int) User::find($attacker->user_id)->sms_unit);
        $this->assertSame(0, Invoices::where('user_id', $attacker->user_id)->count());
    }

    public function test_unverified_topup_success_cannot_credit_another_tenant_either(): void
    {
        $this->gateway(PaymentMethods::TYPE_PAYU);
        $attacker = $this->customer();
        $victim = $this->customer();
        $this->actAs($attacker);
        $before = (int) User::find($victim->user_id)->sms_unit;

        $this->call('GET', route('customer.top_up.payment_success'), ['payment_method' => 'payu', 'user_id' => $victim->user_id, 'sms_unit' => 500, 'price' => 1, 'total_amount' => 1, 'tax_amount' => 0]);

        $this->assertSame($before, (int) User::find($victim->user_id)->sms_unit);
    }

    public function test_unverified_sender_id_success_cannot_activate_anyones_sender_id(): void
    {
        $this->gateway(PaymentMethods::TYPE_COINPAYMENTS);
        $attacker = $this->customer();
        $victim = $this->customer();
        $sid = Senderid::create(['user_id' => $victim->user_id, 'sender_id' => 'VICTIM', 'status' => 'pending', 'price' => 5, 'billing_cycle' => 'monthly',
            'frequency_amount' => 1, 'frequency_unit' => 'month', 'currency_id' => Currency::query()->first()->id]);
        $this->actAs($attacker);

        $this->call('GET', route('customer.senderid.payment_success', $sid->uid), ['payment_method' => 'coinpayments']);

        $this->assertSame('pending', $sid->fresh()->status);
        $this->assertSame(0, Invoices::where('user_id', $victim->user_id)->count());
    }

    public function test_unverified_subscription_success_cannot_create_a_paid_invoice(): void
    {
        $this->gateway(PaymentMethods::TYPE_2CHECKOUT);
        $attacker = $this->customer();
        $plan = Plan::query()->first();
        $this->actAs($attacker);

        $this->call('GET', route('customer.subscriptions.payment_success', $plan->uid), ['payment_method' => '2checkout']);

        $this->assertSame(0, Invoices::where('user_id', $attacker->user_id)->where('status', Invoices::STATUS_PAID)->count());
    }

    public function test_provider_callback_routes_are_closed_and_return_410(): void
    {
        $attacker = $this->customer();
        $this->actAs($attacker);

        foreach (['customer.callback.razorpay.top_up', 'customer.callback.sslcommerz.subscriptions', 'customer.callback.flutterwave.senderid',
                  'customer.callback.paystack', 'customer.callback.coinpayments.ipn', 'customer.callback.nowpayments.ipn', 'customer.callback.easypay'] as $name) {
            $this->call('POST', route($name), ['status' => 'success'])->assertStatus(410);
        }
    }

    public function test_initiation_routes_are_closed_so_customers_cannot_start_a_payment_that_cannot_complete(): void
    {
        $this->gateway(PaymentMethods::TYPE_PAYPAL);
        $c = $this->customer();
        $plan = Plan::query()->first();
        $this->actAs($c);

        $this->post(route('customer.subscriptions.purchase', $plan->uid), ['payment_methods' => 'paypal'])->assertRedirect(route('user.home'));
        $this->post(route('user.account.pay'), ['payment_methods' => 'paypal'])->assertRedirect(route('user.home'));
        $this->post(route('customer.payment.offline', 'top_up'), ['sms_unit' => 100, 'post_data' => 1])->assertStatus(410);
    }

    public function test_enabling_a_gateway_in_config_reopens_only_that_gateway(): void
    {
        config(['legacy_payments.enabled_gateways' => ['easypay']]);
        $c = $this->customer();
        $this->actAs($c);

        // an unlisted gateway stays closed (success route and callback) ...
        $this->call('GET', route('customer.top_up.payment_success'), ['payment_method' => '2checkout', 'user_id' => $c->user_id, 'sms_unit' => 5, 'price' => 1, 'total_amount' => 1])
            ->assertRedirect(route('user.home'));
        $this->call('POST', route('customer.callback.paystack'), [])->assertStatus(410);
        // ... a request that names no gateway is closed too ...
        $this->call('GET', route('customer.top_up.payment_success'), [])->assertRedirect(route('user.home'));
        // ... and mixing a listed gateway with an unlisted one is refused
        $this->call('GET', route('customer.top_up.payment_success'), ['payment_method' => 'easypay', 'payment_methods' => '2checkout'])->assertRedirect(route('user.home'));
        // the listed one is merely not blocked by the gate (its own handler then does its verification)
        $this->assertNotSame(410, $this->call('POST', route('customer.callback.easypay'), [])->getStatusCode());
    }

    public function test_the_admin_cannot_enable_a_closed_legacy_gateway(): void
    {
        $gateway = $this->gateway(PaymentMethods::TYPE_RAZORPAY, false);
        $admin = User::query()->where('is_admin', true)->first();
        $this->withSession(['permissions' => collect(['access backend', 'view payment_gateways'])]);
        $this->actingAs($admin);

        $this->post(route('admin.payment-gateways.active', $gateway->uid))->assertJson(['status' => 'error']);

        $this->assertFalse((bool) $gateway->fresh()->status);
    }
}
