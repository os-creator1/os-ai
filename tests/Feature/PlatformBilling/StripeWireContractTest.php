<?php

namespace Tests\Feature\PlatformBilling;

use App\Library\PlatformBilling\CheckoutOptions;
use App\Library\PlatformBilling\SchedulePhase;
use App\Library\PlatformBilling\StripeApiPlatformGateway;
use Carbon\CarbonImmutable;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * THE REAL gateway + THE REAL stripe-php SDK, with only the HTTP transport
 * replaced by a recorder. This proves exactly which parameters MotionGrove puts
 * on the wire (no network, no key). It cannot prove Stripe ACCEPTS them — that
 * is the test-mode acceptance run (docs §4).
 */
class StripeWireContractTest extends TestCase
{
    /** @var array<int, array{method: string, url: string, params: array<string, mixed>}> */
    private array $wire = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_wire_contract_only']);
        $test = $this;
        $trialEnd = CarbonImmutable::now()->addDays(10)->getTimestamp();
        $start = CarbonImmutable::now()->subDay()->getTimestamp();

        ApiRequestor::setHttpClient(new class($test, $trialEnd, $start) implements ClientInterface {
            public function __construct(private object $test, private int $trialEnd, private int $start)
            {
            }

            public function request($method, $absUrl, $headers, $params, $hasFile)
            {
                $this->test->record($method, $absUrl, (array) $params);
                $path = parse_url($absUrl, PHP_URL_PATH);

                $subscription = [
                    'id' => 'sub_1', 'object' => 'subscription', 'customer' => 'cus_1', 'status' => 'trialing',
                    'trial_end' => $this->trialEnd, 'start_date' => $this->start, 'schedule' => null, 'cancel_at_period_end' => false,
                    'metadata' => [], 'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'price' => ['id' => 'price_a'], 'current_period_start' => $this->start, 'current_period_end' => $this->trialEnd]]],
                ];
                $body = match (true) {
                    str_ends_with($path, '/v1/subscriptions/sub_1') => $subscription,
                    $path === '/v1/subscription_schedules' => ['id' => 'sub_sched_1', 'object' => 'subscription_schedule', 'status' => 'active', 'phases' => [['start_date' => $this->start]]],
                    str_starts_with($path, '/v1/subscription_schedules/') => ['id' => 'sub_sched_1', 'object' => 'subscription_schedule', 'status' => 'active', 'phases' => []],
                    $path === '/v1/prices' => ['id' => 'price_new', 'object' => 'price', 'active' => true, 'currency' => 'usd', 'unit_amount' => 7900, 'type' => 'recurring', 'livemode' => false, 'product' => 'prod_1', 'recurring' => ['interval' => 'day', 'interval_count' => 28]],
                    $path === '/v1/checkout/sessions' => ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_1', 'status' => 'open', 'client_reference_id' => 'uid-1'],
                    default => ['id' => 'x', 'object' => 'unknown'],
                };

                return [json_encode($body), 200, []];
            }
        });
        $this->wire = [];
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function record(string $method, string $url, array $params): void
    {
        $this->wire[] = ['method' => strtoupper($method), 'url' => $url, 'params' => $params];
    }

    /** @return array<int, array{method: string, url: string, params: array<string, mixed>}> */
    private function requestsTo(string $method, string $pathSuffix): array
    {
        return array_values(array_filter($this->wire, fn (array $r): bool => $r['method'] === $method && str_ends_with((string) parse_url($r['url'], PHP_URL_PATH), $pathSuffix)));
    }

    public function test_the_recurring_price_is_created_as_day_by_28(): void
    {
        (new StripeApiPlatformGateway())->createRecurringPrice('prod_1', 7900, 'USD', 'day', 28, 'Growth', ['app_scope' => 'platform_plan'], 'platform-price:abc');

        $params = $this->requestsTo('POST', '/v1/prices')[0]['params'];

        $this->assertSame(7900, $params['unit_amount']);
        $this->assertSame('usd', $params['currency']);
        $this->assertSame(['interval' => 'day', 'interval_count' => 28], $params['recurring']);
        $this->assertSame('prod_1', $params['product']);
    }

    public function test_a_schedule_is_attached_without_the_removed_iterations_parameter(): void
    {
        $promoEnd = CarbonImmutable::now()->addDays(84);
        $finalEnd = $promoEnd->addDays(28);

        (new StripeApiPlatformGateway())->applyPriceSchedule('sub_1', [
            new SchedulePhase('price_promo', $promoEnd),
            new SchedulePhase('price_std', $finalEnd),
        ], 'offer-schedule:uid');

        $create = $this->requestsTo('POST', '/v1/subscription_schedules')[0]['params'];
        $this->assertSame(['from_subscription' => 'sub_1'], $create);

        $update = $this->requestsTo('POST', '/v1/subscription_schedules/sub_sched_1')[0]['params'];
        $this->assertSame('release', $update['end_behavior']);
        $this->assertCount(2, $update['phases']);
        $this->assertStringNotContainsString('iterations', json_encode($update), 'Stripe removed `iterations` in 2025-09-30');
        $this->assertStringNotContainsString('coupon', json_encode($this->wire), 'promotions are Prices, never coupons');

        [$first, $last] = $update['phases'];
        $this->assertSame([['price' => 'price_promo', 'quantity' => 1]], $first['items']);
        $this->assertSame($promoEnd->getTimestamp(), $first['end_date']);
        $this->assertArrayHasKey('start_date', $first, 'the first phase keeps the live start');
        $this->assertArrayHasKey('trial_end', $first, 'a running trial is preserved');
        $this->assertSame($finalEnd->getTimestamp(), $last['end_date']);
        $this->assertArrayNotHasKey('start_date', $last);
        $this->assertSame('none', $first['proration_behavior']);
        $this->assertSame('none', $last['proration_behavior']);
        $this->assertSame($promoEnd->getTimestamp() + 28 * 86400, $last['end_date'], 'exactly one 28-day period');
    }

    public function test_checkout_always_collects_a_payment_method_and_is_time_boxed(): void
    {
        $expires = CarbonImmutable::now()->addMinutes(60)->getTimestamp();

        (new StripeApiPlatformGateway())->createSubscriptionCheckout(
            'price_a', 'uid-1', 'platform-subscription:uid-1:attempt:a', 'https://a.test/ok', 'https://a.test/no', 'b@example.test', 14, null,
            new CheckoutOptions($expires, ['app_offer_redemption' => 'r-1'], ['app_offer_redemption' => 'r-1']),
        );

        $p = $this->requestsTo('POST', '/v1/checkout/sessions')[0]['params'];

        $this->assertSame('subscription', $p['mode']);
        $this->assertSame('always', $p['payment_method_collection']);
        $this->assertSame($expires, $p['expires_at']);
        $this->assertSame(14, $p['subscription_data']['trial_period_days']);
        $this->assertSame('uid-1', $p['subscription_data']['metadata']['app_operation_id']);
        $this->assertSame('r-1', $p['subscription_data']['metadata']['app_offer_redemption']);
        $this->assertSame([['price' => 'price_a', 'quantity' => 1]], $p['line_items']);
    }

    public function test_no_trial_means_no_trial_parameters_at_all(): void
    {
        (new StripeApiPlatformGateway())->createSubscriptionCheckout('price_a', 'uid-1', 'k', 'https://a.test/ok', 'https://a.test/no', 'b@example.test', null);

        $p = $this->requestsTo('POST', '/v1/checkout/sessions')[0]['params'];

        $this->assertArrayNotHasKey('trial_period_days', $p['subscription_data']);
        $this->assertArrayNotHasKey('trial_settings', $p['subscription_data']);
    }
}
