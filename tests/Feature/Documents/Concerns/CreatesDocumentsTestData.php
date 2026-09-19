<?php

namespace Tests\Feature\Documents\Concerns;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * Fixtures for Implementation Contract 17 Sub-slice A.
 *
 * Deliberately DB-level inserts for the Slice-17 tables themselves: this
 * sub-slice ships models with casts/relations only and no manager, so a schema
 * test must exercise the DDL rather than an application write path that does
 * not exist yet. The Business/Workspace/Location fixtures reuse the
 * repository's existing choke point (CreatesBusinessTestData).
 */
trait CreatesDocumentsTestData
{
    use CreatesBusinessTestData;

    protected function documentsBusiness(): Business
    {
        return $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
    }

    protected function documentsLocation(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    protected function documentsContact(Business $business, BusinessLocation $location): int
    {
        $group = ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Test Group ' . uniqid(),
            'status' => true,
        ]);

        return DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'location_id' => $location->id,
            'group_id' => $group->id,
            'phone' => '1415555' . random_int(1000, 9999),
            'status' => Contacts::STATUS_SUBSCRIBE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A complete Business + Location + Contact bundle.
     *
     * @return array{business: Business, location: BusinessLocation, contactId: int}
     */
    protected function documentsBundle(): array
    {
        $business = $this->documentsBusiness();
        $location = $this->documentsLocation($business);

        return [
            'business' => $business,
            'location' => $location,
            'contactId' => $this->documentsContact($business, $location),
        ];
    }

    protected function insertDocument(array $bundle, array $overrides = []): int
    {
        return DB::table('business_documents')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_id' => $bundle['business']->id,
            'business_location_id' => $bundle['location']->id,
            'contact_id' => $bundle['contactId'],
            'kind' => 'proposal',
            'status' => 'draft',
            'requires_signature' => true,
            'title' => 'Fixture proposal',
            'currency_code' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function insertVersion(int $documentId, array $overrides = []): int
    {
        return DB::table('business_document_versions')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_document_id' => $documentId,
            'version_number' => 1,
            'state' => 'draft',
            'content' => json_encode(['body' => 'Fixture']),
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'currency_code' => 'USD',
            'schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function insertLineItem(int $versionId, array $overrides = []): int
    {
        return DB::table('business_document_line_items')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_document_version_id' => $versionId,
            'position' => 0,
            'source' => 'custom',
            'name' => 'Fixture line',
            'quantity' => 1,
            'unit_price_minor' => 10000,
            'line_total_minor' => 10000,
            'currency_code' => 'USD',
            'created_at' => now(),
        ], $overrides));
    }

    protected function insertScheduleItem(int $versionId, array $overrides = []): int
    {
        return DB::table('business_document_payment_schedule_items')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_document_version_id' => $versionId,
            'sequence' => 1,
            'kind' => 'full',
            'amount_minor' => 10000,
            'currency_code' => 'USD',
            'status' => 'pending',
            'reminder_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function insertSignature(int $documentId, int $versionId, array $overrides = []): int
    {
        return DB::table('business_document_signatures')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_document_id' => $documentId,
            'business_document_version_id' => $versionId,
            'signed_content_hash' => str_repeat('a', 64),
            'signer_name' => 'Pat Customer',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat Customer',
            'signature_method' => 'typed',
            'consent_statement' => 'I agree to the terms shown.',
            'consent_statement_hash' => str_repeat('b', 64),
            'ip_address' => '203.0.113.9',
            'signed_at' => now(),
            'created_at' => now(),
        ], $overrides));
    }

    protected function insertStripeConnection(int $businessId, array $overrides = []): int
    {
        return DB::table('business_stripe_connections')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_id' => $businessId,
            'stripe_account_id' => 'acct_' . Str::random(16),
            'status' => 'active',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function insertPayment(
        int $businessId,
        int $documentId,
        int $scheduleItemId,
        int $connectionId,
        array $overrides = []
    ): int {
        $uid = (string) Str::uuid();

        return DB::table('business_document_payments')->insertGetId(array_merge([
            'uid' => $uid,
            'business_id' => $businessId,
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

    protected function insertRefund(int $businessId, int $paymentId, array $overrides = []): int
    {
        $uid = (string) Str::uuid();

        return DB::table('business_document_refunds')->insertGetId(array_merge([
            'uid' => $uid,
            'business_id' => $businessId,
            'business_document_payment_id' => $paymentId,
            'local_idempotency_key' => 'document-refund:' . $uid,
            'amount_minor' => 1000,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function insertPaymentEvent(array $overrides = []): int
    {
        return DB::table('business_payment_events')->insertGetId(array_merge([
            'stripe_account_id' => 'acct_' . Str::random(16),
            'provider_event_id' => 'evt_' . Str::random(16),
            'event_type' => 'payment_intent.succeeded',
            'state' => 'received',
            'attempts' => 0,
            'payload_hash' => str_repeat('c', 64),
            'created_at' => now(),
        ], $overrides));
    }
}
