<?php

namespace Tests\Feature\Usage;

use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §14/§25 item 87 — a payload that reliably fails
 * every claim is reclaimed at most 5 times, then becomes permanently
 * unreclaimed and surfaces in the exhausted-events queue.
 */
class WebhookClaimExhaustionTest extends TestCase
{
    use RefreshDatabase;

    private function createEvent(array $overrides = []): int
    {
        return DB::table('payment_provider_events')->insertGetId(array_merge([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.uniqid(),
            'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => 'pi_x',
            'payload_hash' => hash('sha256', 'x'),
            'state' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ], $overrides));
    }

    public function test_attempts_5_reaches_the_bound_and_attempt_6_never_reclaims(): void
    {
        $id = $this->createEvent();
        $repository = app(PaymentProviderEventRepository::class);

        for ($i = 1; $i <= 5; $i++) {
            $claimed = $repository->claim($id, 5, 5);
            $this->assertSame(1, $claimed, "Claim attempt {$i} should succeed.");
            $repository->markFailed($id, "attempt {$i} failed");
        }

        $event = $repository->findById($id);
        $this->assertSame(5, $event->attempts);
        $this->assertSame('failed', $event->state->value);

        // The 6th claim attempt must not match any branch of the WHERE
        // clause — permanently unreclaimed by the automated worker.
        $sixthClaim = $repository->claim($id, 5, 5);
        $this->assertSame(0, $sixthClaim);

        $stillFive = $repository->findById($id);
        $this->assertSame(5, $stillFive->attempts);
    }

    public function test_exhausted_query_surfaces_only_events_at_or_past_the_bound(): void
    {
        $exhaustedId = $this->createEvent(['state' => 'failed', 'attempts' => 5]);
        $notYetId = $this->createEvent(['state' => 'failed', 'attempts' => 3]);
        $succeededId = $this->createEvent(['state' => 'processed', 'attempts' => 1]);

        $repository = app(PaymentProviderEventRepository::class);
        $exhausted = $repository->exhausted(5)->pluck('id')->all();

        $this->assertContains($exhaustedId, $exhausted);
        $this->assertNotContains($notYetId, $exhausted);
        $this->assertNotContains($succeededId, $exhausted);
    }
}
