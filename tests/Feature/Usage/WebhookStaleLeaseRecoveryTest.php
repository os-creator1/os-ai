<?php

namespace Tests\Feature\Usage;

use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §14/§25 item 86 — a stale `processing` lease is
 * reclaimed, bounded by attempts < 5.
 */
class WebhookStaleLeaseRecoveryTest extends TestCase
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

    public function test_a_fresh_received_event_is_claimed(): void
    {
        $id = $this->createEvent();
        $repository = app(PaymentProviderEventRepository::class);

        $claimed = $repository->claim($id, 5, 5);

        $this->assertSame(1, $claimed);
        $event = $repository->findById($id);
        $this->assertSame('processing', $event->state->value);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->lease_expires_at);
    }

    public function test_a_stale_processing_lease_under_the_attempt_bound_is_reclaimed(): void
    {
        // Seeded relative to MySQL's own clock (DB::raw NOW()), never
        // PHP's now() — the claim SQL itself compares lease_expires_at
        // against MySQL's NOW(), and this test environment's MySQL/PHP
        // clocks are not guaranteed to agree.
        $id = $this->createEvent([
            'state' => 'processing',
            'attempts' => 1,
            'processing_started_at' => DB::raw('NOW() - INTERVAL 10 MINUTE'),
            'lease_expires_at' => DB::raw('NOW() - INTERVAL 5 MINUTE'), // already expired
        ]);
        $repository = app(PaymentProviderEventRepository::class);

        $claimed = $repository->claim($id, 5, 5);

        $this->assertSame(1, $claimed);
        $event = $repository->findById($id);
        $this->assertSame(2, $event->attempts);
    }

    public function test_a_non_expired_processing_lease_is_not_reclaimed(): void
    {
        $id = $this->createEvent([
            'state' => 'processing',
            'attempts' => 1,
            'processing_started_at' => DB::raw('NOW()'),
            'lease_expires_at' => DB::raw('NOW() + INTERVAL 5 MINUTE'), // still active
        ]);
        $repository = app(PaymentProviderEventRepository::class);

        $claimed = $repository->claim($id, 5, 5);

        $this->assertSame(0, $claimed);
        $event = $repository->findById($id);
        $this->assertSame(1, $event->attempts);
    }

    public function test_a_failed_event_under_the_bound_is_reclaimed(): void
    {
        $id = $this->createEvent(['state' => 'failed', 'attempts' => 2]);
        $repository = app(PaymentProviderEventRepository::class);

        $claimed = $repository->claim($id, 5, 5);

        $this->assertSame(1, $claimed);
        $event = $repository->findById($id);
        $this->assertSame('processing', $event->state->value);
        $this->assertSame(3, $event->attempts);
    }
}
