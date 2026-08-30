<?php

namespace Tests\Feature\Usage;

use App\Models\PaymentProviderEvent;
use App\Repositories\Contracts\PaymentProviderEventRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §21.5 — payment_provider_events exact schema shape:
 * UNIQUE(provider, provider_event_id) is the true-duplicate guard;
 * payload_encrypted round-trips correctly through Laravel's `encrypted`
 * Eloquent cast (the first real use of this cast in the repository).
 */
class PaymentProviderEventSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_and_provider_event_id_is_unique(): void
    {
        DB::table('payment_provider_events')->insert([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_dup',
            'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => 'pi_x',
            'payload_hash' => hash('sha256', 'x'),
            'state' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('payment_provider_events')->insert([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_dup',
            'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => 'pi_y',
            'payload_hash' => hash('sha256', 'y'),
            'state' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ]);
    }

    public function test_payload_encrypted_round_trips_through_the_encrypted_cast(): void
    {
        $rawPayload = json_encode(['id' => 'evt_encrypted_test', 'type' => 'payment_intent.succeeded']);

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_encrypted_test',
            'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => 'pi_encrypted',
            'payload_encrypted' => $rawPayload,
            'payload_hash' => hash('sha256', $rawPayload),
            'state' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ]);

        // The raw database column must never contain the plaintext JSON —
        // proving the cast actually encrypts, not merely round-trips in PHP.
        $rawColumnValue = DB::table('payment_provider_events')->where('id', $event->id)->value('payload_encrypted');
        $this->assertStringNotContainsString('evt_encrypted_test', $rawColumnValue);

        $fresh = PaymentProviderEvent::query()->find($event->id);
        $this->assertSame($rawPayload, $fresh->payload_encrypted);
    }

    public function test_payload_purged_at_and_disposition_fields_survive_payload_purge(): void
    {
        $event = PaymentProviderEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_purge_test',
            'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => 'pi_purge',
            'payload_encrypted' => 'sensitive-payload',
            'payload_hash' => hash('sha256', 'sensitive-payload'),
            'state' => 'disposed',
            'attempts' => 5,
            'received_at' => now(),
            'disposed_at' => now(),
            'disposed_by_user_id' => 1,
            'disposition_note' => 'Resolved manually.',
        ]);

        $event->update(['payload_encrypted' => null, 'payload_purged_at' => now()]);

        $fresh = PaymentProviderEvent::query()->find($event->id);
        $this->assertNull($fresh->payload_encrypted);
        $this->assertNotNull($fresh->payload_purged_at);
        $this->assertSame('evt_purge_test', $fresh->provider_event_id);
        $this->assertSame(hash('sha256', 'sensitive-payload'), $fresh->payload_hash);
        $this->assertSame('Resolved manually.', $fresh->disposition_note);
    }

    private function createProcessingEvent(): int
    {
        return DB::table('payment_provider_events')->insertGetId([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.uniqid(),
            'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => 'pi_x',
            'payload_hash' => hash('sha256', 'x'),
            'state' => 'processing',
            'attempts' => 1,
            'received_at' => now(),
            'processing_started_at' => now(),
            'lease_expires_at' => now()->addMinutes(5),
        ]);
    }

    public function test_marking_processed_sets_completed_at_never_processed_at_and_clears_the_lease(): void
    {
        $this->assertNotContains('processed_at', DB::getSchemaBuilder()->getColumnListing('payment_provider_events'));

        $id = $this->createProcessingEvent();
        $repository = app(PaymentProviderEventRepository::class);

        $repository->markProcessed($id);

        $event = $repository->findById($id);
        $this->assertSame('processed', $event->state->value);
        $this->assertNotNull($event->completed_at);
        $this->assertNull($event->lease_expires_at);
    }

    public function test_marking_ignored_sets_completed_at_never_processed_at_and_clears_the_lease(): void
    {
        $this->assertNotContains('processed_at', DB::getSchemaBuilder()->getColumnListing('payment_provider_events'));

        $id = $this->createProcessingEvent();
        $repository = app(PaymentProviderEventRepository::class);

        $repository->markIgnored($id);

        $event = $repository->findById($id);
        $this->assertSame('ignored', $event->state->value);
        $this->assertNotNull($event->completed_at);
        $this->assertNull($event->lease_expires_at);
    }
}
