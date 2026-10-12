<?php

namespace Tests\Feature\PlatformBilling;

use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\SchedulePhase;
use App\Library\PlatformBilling\StripeApiPlatformGateway;
use Carbon\CarbonImmutable;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * THE REAL gateway and SDK with a recorded transport, for ONE rule: a
 * Subscription Schedule we did not create is never overwritten.
 *
 * Stripe answers a repeated create (same idempotency key) with the original
 * schedule, which is the only proof an attached schedule is ours.
 */
class ScheduleOwnershipWireTest extends TestCase
{
    /** @var array<int, array{method: string, path: string, params: array<string, mixed>}> */
    public array $wire = [];

    private function transport(?string $attachedSchedule, string $createAnswers, string $createMode = 'ok'): void
    {
        config(['services.stripe.secret' => 'sk_test_wire_contract_only']);
        $test = $this;
        $start = CarbonImmutable::now()->subDay()->getTimestamp();

        ApiRequestor::setHttpClient(new class($test, $attachedSchedule, $createAnswers, $start, $createMode) implements ClientInterface {
            public function __construct(private object $test, private ?string $attached, private string $createAnswers, private int $start, private string $createMode)
            {
            }

            public function request($method, $absUrl, $headers, $params, $hasFile)
            {
                $path = (string) parse_url($absUrl, PHP_URL_PATH);
                $this->test->wire[] = ['method' => strtoupper($method), 'path' => $path, 'params' => (array) $params];

                $subscription = [
                    'id' => 'sub_1', 'object' => 'subscription', 'customer' => 'cus_1', 'status' => 'active',
                    'start_date' => $this->start, 'schedule' => $this->attached, 'cancel_at_period_end' => false, 'metadata' => [],
                    'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'price' => ['id' => 'price_a'], 'current_period_start' => $this->start, 'current_period_end' => $this->start + 28 * 86400]]],
                ];
                $schedule = fn (string $id) => ['id' => $id, 'object' => 'subscription_schedule', 'status' => 'active', 'phases' => [['start_date' => $this->start]]];

                if ($path === '/v1/subscription_schedules' && strtoupper($method) === 'POST') {
                    if ($this->createMode === 'refuse') {
                        return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'The subscription is already attached to a schedule.']]), 400, []];
                    }

                    if ($this->createMode === 'transient') {
                        throw new \Stripe\Exception\ApiConnectionException('connection reset');
                    }
                }

                $body = match (true) {
                    $path === '/v1/subscriptions/sub_1' => $subscription,
                    $path === '/v1/subscription_schedules' => $schedule($this->createAnswers),
                    str_starts_with($path, '/v1/subscription_schedules/') => $schedule(basename($path)),
                    default => ['id' => 'x', 'object' => 'unknown'],
                };

                return [json_encode($body), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function phases(): array
    {
        $end = CarbonImmutable::now()->addDays(28);

        return [new SchedulePhase('price_a', $end), new SchedulePhase('price_b', $end->addDays(28))];
    }

    private function updates(): array
    {
        return array_values(array_filter($this->wire, fn (array $r): bool => $r['method'] === 'POST' && $r['path'] !== '/v1/subscription_schedules'));
    }

    public function test_an_unrelated_attached_schedule_is_never_overwritten(): void
    {
        // A Dashboard-made schedule is attached; the (fresh-key) create answers with a NEW schedule.
        $this->transport(attachedSchedule: 'sub_sched_dashboard', createAnswers: 'sub_sched_new');

        try {
            (new StripeApiPlatformGateway())->applyPriceSchedule('sub_1', $this->phases(), 'offer-change:uid');
            $this->fail('Expected the push to be refused.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::SCHEDULE_NOT_OURS, $e->reason);
        }

        $this->assertSame([], $this->updates(), 'no phases were written to any schedule');
    }

    public function test_a_refused_create_over_an_attached_schedule_is_a_definitive_not_ours(): void
    {
        $this->transport(attachedSchedule: 'sub_sched_dashboard', createAnswers: 'unused', createMode: 'refuse');

        try {
            (new StripeApiPlatformGateway())->applyPriceSchedule('sub_1', $this->phases(), 'offer-change:uid');
            $this->fail('Expected refusal.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::SCHEDULE_NOT_OURS, $e->reason);
        }

        $this->assertSame([], $this->updates());
    }

    public function test_a_transient_failure_is_never_read_as_a_foreign_schedule(): void
    {
        $this->transport(attachedSchedule: 'sub_sched_ours', createAnswers: 'unused', createMode: 'transient');

        try {
            (new StripeApiPlatformGateway())->applyPriceSchedule('sub_1', $this->phases(), 'offer-change:uid');
            $this->fail('Expected a failure.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::PROVIDER_FAILED, $e->reason, 'retryable, not terminal');
        }

        $this->assertSame([], $this->updates());
    }

    public function test_the_ownership_proof_is_tri_state(): void
    {
        $gateway = new StripeApiPlatformGateway();

        $this->transport(attachedSchedule: 'sub_sched_ours', createAnswers: 'sub_sched_ours');
        $this->assertSame('sub_sched_ours', $gateway->proveOwnSchedule('sub_1', 'offer-change:uid'));

        $this->transport(attachedSchedule: 'sub_sched_dashboard', createAnswers: 'sub_sched_new');
        $this->assertNull($gateway->proveOwnSchedule('sub_1', 'offer-change:uid'), 'the replay answered with a different schedule');

        $this->transport(attachedSchedule: 'sub_sched_dashboard', createAnswers: 'x', createMode: 'refuse');
        $this->assertNull($gateway->proveOwnSchedule('sub_1', 'offer-change:uid'), 'refused: definitive');

        $this->transport(attachedSchedule: 'sub_sched_ours', createAnswers: 'x', createMode: 'transient');
        $this->expectException(PlatformBillingException::class);
        $gateway->proveOwnSchedule('sub_1', 'offer-change:uid');
    }

    public function test_our_own_interrupted_schedule_is_reused_through_the_idempotent_create(): void
    {
        // The replayed create answers with the very schedule that is attached.
        $this->transport(attachedSchedule: 'sub_sched_ours', createAnswers: 'sub_sched_ours');

        (new StripeApiPlatformGateway())->applyPriceSchedule('sub_1', $this->phases(), 'offer-change:uid');

        $updates = $this->updates();
        $this->assertCount(1, $updates);
        $this->assertSame('/v1/subscription_schedules/sub_sched_ours', $updates[0]['path']);
    }
}
