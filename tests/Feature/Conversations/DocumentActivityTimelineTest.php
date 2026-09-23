<?php

namespace Tests\Feature\Conversations;

use App\Library\Timeline\ContactActivityTimeline;
use App\Library\Timeline\Sources\DocumentActivitySource;
use App\Library\Timeline\TimelineSubject;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Conversations\Concerns\CreatesTimelineFixtures;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §12.G — Payments & Contracts joins the
 * Conversations activity timeline exactly the way every other domain does:
 * by implementing TimelineSource. No DocumentViewed, no invented event —
 * only the durable lifecycle timestamps and payment/refund rows §10 already
 * names, read past-tense.
 */
class DocumentActivityTimelineTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesTimelineFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 15:00:00'));
    }

    public function test_the_document_lifecycle_and_payment_and_refund_read_past_tense_oldest_first(): void
    {
        $tenant = $this->sendableTenant();
        $box = $this->conversationWith($tenant['business'], $tenant['contact']->phone);

        $documentId = $this->document($tenant['business'], $tenant['location'], $tenant['contact'], [
            'kind' => 'proposal',
            'sent_at' => Carbon::parse('2026-09-01 09:00:00'),
            'signed_at' => Carbon::parse('2026-09-02 09:00:00'),
            'paid_at' => Carbon::parse('2026-09-04 09:00:00'),
        ]);

        $connectionId = $this->connection($tenant['business']);
        $versionId = $this->version($documentId);
        $scheduleItemId = $this->scheduleItem($versionId);
        $paymentId = $this->payment($tenant['business'], $documentId, $scheduleItemId, $connectionId, [
            'status' => 'succeeded',
            'succeeded_at' => Carbon::parse('2026-09-03 09:00:00'),
        ]);
        $this->refund($tenant['business'], $paymentId, [
            'status' => 'succeeded',
            'succeeded_at' => Carbon::parse('2026-09-05 09:00:00'),
        ]);

        $page = app(ContactActivityTimeline::class)->forConversation($tenant['business'], $box, $box->resolveDisplayContact($tenant['business']));

        $this->assertSame([
            'activity: Proposal sent',
            'activity: Proposal signed',
            'activity: Payment received',
            'activity: Proposal paid',
            'activity: Payment refunded',
            'activity: Added to contacts',
        ], $this->describe($page));
    }

    public function test_an_expired_or_voided_document_reads_that_way_too(): void
    {
        $tenant = $this->sendableTenant();
        $box = $this->conversationWith($tenant['business'], $tenant['contact']->phone);

        $this->document($tenant['business'], $tenant['location'], $tenant['contact'], [
            'kind' => 'proposal',
            'sent_at' => Carbon::parse('2026-09-01 09:00:00'),
            'expired_at' => Carbon::parse('2026-09-10 09:00:00'),
        ]);
        $this->document($tenant['business'], $tenant['location'], $tenant['contact'], [
            'kind' => 'invoice',
            'sent_at' => Carbon::parse('2026-09-02 09:00:00'),
            'voided_at' => Carbon::parse('2026-09-11 09:00:00'),
        ]);

        $page = app(ContactActivityTimeline::class)->forConversation($tenant['business'], $box, $box->resolveDisplayContact($tenant['business']));

        $this->assertSame([
            'activity: Proposal sent',
            'activity: Invoice sent',
            'activity: Proposal expired',
            'activity: Invoice voided',
            'activity: Added to contacts',
        ], $this->describe($page));
    }

    public function test_contact_keyed_activity_needs_exactly_one_contact_on_the_number_same_as_every_other_source(): void
    {
        $tenant = $this->sendableTenant();
        $this->namedContact($tenant['business'], $tenant['contact']->phone, 'Second', 'Contact');
        $box = $this->conversationWith($tenant['business'], $tenant['contact']->phone);

        $this->document($tenant['business'], $tenant['location'], $tenant['contact'], [
            'sent_at' => Carbon::parse('2026-09-01 09:00:00'),
        ]);

        $subject = new TimelineSubject($tenant['business'], $tenant['contact']->phone, $box, null);

        $this->assertSame([], app(DocumentActivitySource::class)->recent($subject, 200));
    }

    public function test_nothing_from_another_business_ever_appears_even_on_the_same_number(): void
    {
        $tenant = $this->sendableTenant('Mine');
        $sibling = $this->sendableTenant('Theirs');

        $this->document($sibling['business'], $sibling['location'], $sibling['contact'], [
            'sent_at' => Carbon::parse('2026-09-01 09:00:00'),
        ]);

        $box = $this->conversationWith($tenant['business'], $tenant['contact']->phone);
        $page = app(ContactActivityTimeline::class)->forConversation($tenant['business'], $box, $box->resolveDisplayContact($tenant['business']));

        // Only this Business's own "added to contacts" activity is present;
        // the sibling Business's document never appears here.
        $this->assertSame(['activity: Added to contacts'], $this->describe($page));
    }

    public function test_the_source_costs_a_fixed_number_of_queries_however_many_documents_there_are(): void
    {
        $tenant = $this->sendableTenant();
        $box = $this->conversationWith($tenant['business'], $tenant['contact']->phone);
        $subject = new TimelineSubject($tenant['business'], $tenant['contact']->phone, $box, $tenant['contact']);

        $connectionId = $this->connection($tenant['business']);

        $seed = function () use ($tenant, $connectionId): void {
            $documentId = $this->document($tenant['business'], $tenant['location'], $tenant['contact'], [
                'sent_at' => now(), 'signed_at' => now(), 'paid_at' => now(),
            ]);
            $versionId = $this->version($documentId);
            $scheduleItemId = $this->scheduleItem($versionId);
            $paymentId = $this->payment($tenant['business'], $documentId, $scheduleItemId, $connectionId, [
                'status' => 'succeeded', 'succeeded_at' => now(),
            ]);
            $this->refund($tenant['business'], $paymentId, ['status' => 'succeeded', 'succeeded_at' => now()]);
        };

        $seed();
        $few = $this->queriesFor(fn () => app(DocumentActivitySource::class)->recent($subject, 200));

        $seed();
        $seed();
        $many = $this->queriesFor(fn () => app(DocumentActivitySource::class)->recent($subject, 200));

        $this->assertSame($few, $many);
        $this->assertSame(3, $many, 'The document lookup, the succeeded-payments lookup, and the succeeded-refunds lookup.');
    }

    // -----------------------------------------------------------------

    private function document(Business $business, BusinessLocation $location, Contacts $contact, array $overrides = []): int
    {
        return DB::table('business_documents')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'contact_id' => $contact->id,
            'kind' => 'proposal',
            'status' => 'sent',
            'requires_signature' => true,
            'title' => 'Fixture proposal',
            'currency_code' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function version(int $documentId): int
    {
        return DB::table('business_document_versions')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_document_id' => $documentId,
            'version_number' => random_int(1, 1000000),
            'state' => 'issued',
            'content' => json_encode(['body' => 'Fixture']),
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'currency_code' => 'USD',
            'schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function scheduleItem(int $versionId): int
    {
        return DB::table('business_document_payment_schedule_items')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_document_version_id' => $versionId,
            'sequence' => 1,
            'kind' => 'full',
            'amount_minor' => 10000,
            'currency_code' => 'USD',
            'status' => 'paid',
            'reminder_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function connection(Business $business): int
    {
        return DB::table('business_stripe_connections')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'stripe_account_id' => 'acct_' . Str::random(16),
            'status' => 'active',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payment(Business $business, int $documentId, int $scheduleItemId, int $connectionId, array $overrides = []): int
    {
        $uid = (string) Str::uuid();

        return DB::table('business_document_payments')->insertGetId(array_merge([
            'uid' => $uid,
            'business_id' => $business->id,
            'business_document_id' => $documentId,
            'schedule_item_id' => $scheduleItemId,
            'business_stripe_connection_id' => $connectionId,
            'local_idempotency_key' => 'document-payment:' . $uid,
            'amount_minor' => 10000,
            'currency_code' => 'USD',
            'status' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function refund(Business $business, int $paymentId, array $overrides = []): int
    {
        $uid = (string) Str::uuid();

        return DB::table('business_document_refunds')->insertGetId(array_merge([
            'uid' => $uid,
            'business_id' => $business->id,
            'business_document_payment_id' => $paymentId,
            'local_idempotency_key' => 'document-refund:' . $uid,
            'amount_minor' => 1000,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @return list<string> */
    private function describe($page): array
    {
        return array_map(static function ($item): string {
            if ($item->isMessage()) {
                return ($item->isInbound() ? 'in: ' : 'out: ') . $item->body;
            }

            return 'activity: ' . $item->title;
        }, $page->items);
    }

    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
