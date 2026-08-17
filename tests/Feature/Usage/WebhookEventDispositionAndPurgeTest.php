<?php

namespace Tests\Feature\Usage;

use App\Jobs\Usage\PurgeExpiredWebhookPayloads;
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §14/§25 item 88 — an exhausted event can be
 * dispositioned only from an exhausted state; a disposed event never
 * re-enters processing; a disposed, past-retention-window event's payload
 * is purged while identity/audit metadata survives; a merely-exhausted-
 * but-undispositioned event is never purged even past the same window.
 */
class WebhookEventDispositionAndPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function createEvent(array $overrides = []): int
    {
        return DB::table('payment_provider_events')->insertGetId(array_merge([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.uniqid(),
            'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => 'pi_x',
            'payload_encrypted' => Crypt::encryptString('sensitive-payload'),
            'payload_hash' => hash('sha256', 'sensitive-payload'),
            'state' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ], $overrides));
    }

    public function test_disposition_requires_an_exhausted_state(): void
    {
        $id = $this->createEvent(['state' => 'received', 'attempts' => 0]);
        $repository = app(PaymentProviderEventRepository::class);

        $disposed = $repository->dispose($id, 1, 'Not actually exhausted.', 5);

        $this->assertSame(0, $disposed);
        $event = $repository->findById($id);
        $this->assertSame('received', $event->state->value);
    }

    public function test_disposition_succeeds_for_an_exhausted_event_and_never_re_enters_processing(): void
    {
        $id = $this->createEvent(['state' => 'failed', 'attempts' => 5]);
        $repository = app(PaymentProviderEventRepository::class);

        $disposed = $repository->dispose($id, 1, 'Resolved manually after support investigation.', 5);
        $this->assertSame(1, $disposed);

        $event = $repository->findById($id);
        $this->assertSame('disposed', $event->state->value);
        $this->assertNotNull($event->disposed_at);
        $this->assertSame(1, $event->disposed_by_user_id);
        $this->assertSame('Resolved manually after support investigation.', $event->disposition_note);

        // A disposed event never matches any claim branch again.
        $reclaimed = $repository->claim($id, 5, 5);
        $this->assertSame(0, $reclaimed);
    }

    public function test_disposed_past_retention_payload_is_purged_while_audit_metadata_survives(): void
    {
        $id = $this->createEvent(['state' => 'disposed', 'attempts' => 5, 'disposed_at' => now()->subDays(100), 'disposed_by_user_id' => 1, 'disposition_note' => 'Resolved.']);

        config(['usage_billing.webhook_event.retention_days' => 30]);
        (new PurgeExpiredWebhookPayloads())->handle(app(PaymentProviderEventRepository::class));

        $event = app(PaymentProviderEventRepository::class)->findById($id);
        $this->assertNull($event->payload_encrypted);
        $this->assertNotNull($event->payload_purged_at);
        $this->assertNotNull($event->payload_hash);
        $this->assertSame('disposed', $event->state->value);
        $this->assertSame('Resolved.', $event->disposition_note);
    }

    public function test_a_merely_exhausted_undispositioned_event_is_never_purged(): void
    {
        $id = $this->createEvent(['state' => 'failed', 'attempts' => 5, 'received_at' => now()->subDays(100)]);

        config(['usage_billing.webhook_event.retention_days' => 30]);
        (new PurgeExpiredWebhookPayloads())->handle(app(PaymentProviderEventRepository::class));

        $event = app(PaymentProviderEventRepository::class)->findById($id);
        $this->assertNotNull($event->payload_encrypted);
        $this->assertNull($event->payload_purged_at);
    }
}
