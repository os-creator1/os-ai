<?php

namespace Tests\Feature\Documents;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Enums\Documents\BusinessDocumentRefundStatus;
use App\Enums\Documents\BusinessPaymentEventState;
use App\Enums\Documents\DocumentKind;
use App\Enums\Documents\DocumentLineItemSource;
use App\Enums\Documents\DocumentSignatureMethod;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentVersionState;
use App\Enums\Documents\PaymentScheduleItemKind;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Enums\Documents\StripeConnectionStatus;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLineItem;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentRefund;
use App\Models\BusinessDocumentSignature;
use App\Models\BusinessDocumentVersion;
use App\Models\BusinessLocation;
use App\Models\BusinessPaymentEvent;
use App\Models\BusinessStripeConnection;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\TestCase;

/**
 * Implementation Contract 17 §5, §12.A — casts, relations and mass-assignment
 * protection only. No manager, controller, route or provider call exists in
 * this sub-slice, so nothing here exercises behaviour beyond what the models
 * themselves declare.
 */
class DocumentsModelSmokeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDocumentsTestData;

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @return array{bundle: array, document: BusinessDocument, version: BusinessDocumentVersion} */
    private function documentWithVersion(): array
    {
        $bundle = $this->documentsBundle();

        $document = BusinessDocument::create([
            'business_id' => $bundle['business']->id,
            'business_location_id' => $bundle['location']->id,
            'contact_id' => $bundle['contactId'],
            'kind' => DocumentKind::Proposal,
            'title' => 'Wedding photo booth',
            'currency_code' => 'USD',
        ]);

        $version = BusinessDocumentVersion::create([
            'business_document_id' => $document->id,
            'version_number' => 1,
            'content' => ['body' => 'Terms'],
            'subtotal_minor' => 50000,
            'total_minor' => 50000,
            'currency_code' => 'USD',
        ]);

        return ['bundle' => $bundle, 'document' => $document->fresh(), 'version' => $version->fresh()];
    }

    // ------------------------------------------------------------------
    // Identity
    // ------------------------------------------------------------------

    public function test_every_uid_bearing_model_mints_a_real_uuid_v4_not_uniqid(): void
    {
        $f = $this->documentWithVersion();

        $line = BusinessDocumentLineItem::create([
            'business_document_version_id' => $f['version']->id,
            'source' => DocumentLineItemSource::Custom,
            'name' => 'Booth',
            'quantity' => 1,
            'unit_price_minor' => 50000,
            'line_total_minor' => 50000,
            'currency_code' => 'USD',
        ]);

        $item = BusinessDocumentPaymentScheduleItem::create([
            'business_document_version_id' => $f['version']->id,
            'sequence' => 1,
            'kind' => PaymentScheduleItemKind::Full,
            'amount_minor' => 50000,
            'currency_code' => 'USD',
        ]);

        $connection = BusinessStripeConnection::create([
            'business_id' => $f['bundle']['business']->id,
            'stripe_account_id' => 'acct_' . Str::random(12),
        ]);

        $payment = BusinessDocumentPayment::create([
            'business_id' => $f['bundle']['business']->id,
            'business_document_id' => $f['document']->id,
            'schedule_item_id' => $item->id,
            'business_stripe_connection_id' => $connection->id,
            'local_idempotency_key' => 'k-' . Str::random(8),
            'amount_minor' => 50000,
            'currency_code' => 'USD',
        ]);

        $refund = BusinessDocumentRefund::create([
            'business_id' => $f['bundle']['business']->id,
            'business_document_payment_id' => $payment->id,
            'local_idempotency_key' => 'r-' . Str::random(8),
            'amount_minor' => 1000,
        ]);

        $signature = BusinessDocumentSignature::create([
            'business_document_id' => $f['document']->id,
            'business_document_version_id' => $f['version']->id,
            'signed_content_hash' => str_repeat('a', 64),
            'signer_name' => 'Pat',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat',
            'consent_statement' => 'I agree.',
            'consent_statement_hash' => str_repeat('b', 64),
            'ip_address' => '203.0.113.9',
            'signed_at' => now(),
        ]);

        foreach ([$f['document'], $f['version'], $line, $item, $connection, $payment, $refund, $signature] as $model) {
            $this->assertMatchesRegularExpression(self::UUID_V4, (string) $model->uid, get_class($model) . ' must mint a UUIDv4, never HasUid\'s uniqid().');
        }
    }

    public function test_table_names_are_the_contracted_ones(): void
    {
        $this->assertSame('business_documents', (new BusinessDocument())->getTable());
        $this->assertSame('business_document_versions', (new BusinessDocumentVersion())->getTable());
        $this->assertSame('business_document_line_items', (new BusinessDocumentLineItem())->getTable());
        $this->assertSame('business_document_payment_schedule_items', (new BusinessDocumentPaymentScheduleItem())->getTable());
        $this->assertSame('business_document_signatures', (new BusinessDocumentSignature())->getTable());
        $this->assertSame('business_stripe_connections', (new BusinessStripeConnection())->getTable());
        $this->assertSame('business_payment_events', (new BusinessPaymentEvent())->getTable());
        $this->assertSame('business_document_payments', (new BusinessDocumentPayment())->getTable());
        $this->assertSame('business_document_refunds', (new BusinessDocumentRefund())->getTable());
    }

    // ------------------------------------------------------------------
    // Casts
    // ------------------------------------------------------------------

    public function test_status_and_kind_columns_cast_to_enums_with_contracted_defaults(): void
    {
        $f = $this->documentWithVersion();

        $this->assertSame(DocumentKind::Proposal, $f['document']->kind);
        $this->assertSame(DocumentStatus::Draft, $f['document']->status);
        $this->assertTrue($f['document']->requires_signature);
        $this->assertSame(0, $f['document']->expiry_reminder_count);
        $this->assertSame(DocumentVersionState::Draft, $f['version']->state);
        $this->assertSame(['body' => 'Terms'], $f['version']->content);
        $this->assertSame(50000, $f['version']->total_minor);
    }

    public function test_money_columns_cast_to_integers(): void
    {
        $f = $this->documentWithVersion();

        $this->assertIsInt($f['version']->subtotal_minor);
        $this->assertIsInt($f['version']->total_minor);
        $this->assertIsInt($f['version']->version_number);
    }

    public function test_schedule_payment_refund_connection_and_event_enums_cast(): void
    {
        $f = $this->documentWithVersion();
        $business = $f['bundle']['business'];

        $item = BusinessDocumentPaymentScheduleItem::create([
            'business_document_version_id' => $f['version']->id,
            'sequence' => 1,
            'kind' => PaymentScheduleItemKind::Deposit,
            'amount_minor' => 20000,
            'currency_code' => 'USD',
        ])->fresh();

        $connection = BusinessStripeConnection::create([
            'business_id' => $business->id,
            'stripe_account_id' => 'acct_' . Str::random(12),
        ])->fresh();

        $payment = BusinessDocumentPayment::create([
            'business_id' => $business->id,
            'business_document_id' => $f['document']->id,
            'schedule_item_id' => $item->id,
            'business_stripe_connection_id' => $connection->id,
            'local_idempotency_key' => 'k-' . Str::random(8),
            'amount_minor' => 20000,
            'currency_code' => 'USD',
        ])->fresh();

        $refund = BusinessDocumentRefund::create([
            'business_id' => $business->id,
            'business_document_payment_id' => $payment->id,
            'local_idempotency_key' => 'r-' . Str::random(8),
            'amount_minor' => 500,
        ])->fresh();

        $event = BusinessPaymentEvent::create([
            'stripe_account_id' => $connection->stripe_account_id,
            'provider_event_id' => 'evt_' . Str::random(8),
            'event_type' => 'payment_intent.succeeded',
            'payload_hash' => str_repeat('d', 64),
        ])->fresh();

        $this->assertSame(PaymentScheduleItemKind::Deposit, $item->kind);
        $this->assertSame(PaymentScheduleItemStatus::Pending, $item->status);
        $this->assertSame(StripeConnectionStatus::Pending, $connection->status);
        $this->assertFalse($connection->charges_enabled);
        $this->assertSame(BusinessDocumentPaymentStatus::Created, $payment->status);
        $this->assertSame(BusinessDocumentRefundStatus::Pending, $refund->status);
        $this->assertSame(BusinessPaymentEventState::Received, $event->state);
        $this->assertSame(DocumentSignatureMethod::Typed, DocumentSignatureMethod::from('typed'));
        $this->assertSame(DocumentLineItemSource::Catalog, DocumentLineItemSource::from('catalog'));
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function test_the_document_graph_resolves_through_relations(): void
    {
        $f = $this->documentWithVersion();
        $document = $f['document'];

        DB::table('business_documents')->where('id', $document->id)->update(['current_version_id' => $f['version']->id]);

        $item = BusinessDocumentPaymentScheduleItem::create([
            'business_document_version_id' => $f['version']->id,
            'sequence' => 1,
            'kind' => PaymentScheduleItemKind::Full,
            'amount_minor' => 50000,
            'currency_code' => 'USD',
        ]);

        $line = BusinessDocumentLineItem::create([
            'business_document_version_id' => $f['version']->id,
            'source' => DocumentLineItemSource::Custom,
            'name' => 'Booth',
            'quantity' => 2,
            'unit_price_minor' => 25000,
            'line_total_minor' => 50000,
            'currency_code' => 'USD',
        ]);

        $document = $document->fresh();

        $this->assertInstanceOf(Business::class, $document->business);
        $this->assertInstanceOf(BusinessLocation::class, $document->businessLocation);
        $this->assertInstanceOf(Contacts::class, $document->contact);
        $this->assertTrue($document->currentVersion->is($f['version']));
        $this->assertCount(1, $document->versions);
        $this->assertTrue($f['version']->document->is($document));

        // The schedule and lines hang off the VERSION.
        $this->assertTrue($f['version']->paymentScheduleItems->first()->is($item));
        $this->assertTrue($f['version']->lineItems->first()->is($line));
        $this->assertTrue($item->version->is($f['version']));
        $this->assertFalse(method_exists($item, 'document'), 'A schedule item has no document shortcut (§5.9).');
    }

    public function test_payment_relations_reach_the_historical_connection_and_refunds(): void
    {
        $f = $this->documentWithVersion();
        $business = $f['bundle']['business'];

        $item = BusinessDocumentPaymentScheduleItem::create([
            'business_document_version_id' => $f['version']->id,
            'sequence' => 1,
            'kind' => PaymentScheduleItemKind::Full,
            'amount_minor' => 50000,
            'currency_code' => 'USD',
        ]);

        $connection = BusinessStripeConnection::create([
            'business_id' => $business->id,
            'stripe_account_id' => 'acct_' . Str::random(12),
        ]);

        $payment = BusinessDocumentPayment::create([
            'business_id' => $business->id,
            'business_document_id' => $f['document']->id,
            'schedule_item_id' => $item->id,
            'business_stripe_connection_id' => $connection->id,
            'local_idempotency_key' => 'k-' . Str::random(8),
            'amount_minor' => 50000,
            'currency_code' => 'USD',
        ]);

        $refund = BusinessDocumentRefund::create([
            'business_id' => $business->id,
            'business_document_payment_id' => $payment->id,
            'local_idempotency_key' => 'r-' . Str::random(8),
            'amount_minor' => 1000,
        ]);

        $this->assertTrue($payment->connection->is($connection));
        $this->assertTrue($payment->scheduleItem->is($item));
        $this->assertTrue($payment->document->is($f['document']));
        $this->assertTrue($payment->refunds->first()->is($refund));
        $this->assertTrue($refund->payment->is($payment));
        $this->assertTrue($connection->payments->first()->is($payment));
        $this->assertTrue($item->payments->first()->is($payment));
    }

    // ------------------------------------------------------------------
    // Mass-assignment protection
    // ------------------------------------------------------------------

    public function test_lifecycle_and_provider_truth_fields_are_not_mass_assignable(): void
    {
        $protected = [
            BusinessDocument::class => [
                'status', 'current_version_id', 'sent_at', 'signed_at', 'paid_at', 'expired_at', 'voided_at',
                'void_reason', 'access_token_hash', 'access_token_expires_at', 'access_token_rotated_at',
                'expiry_reminder_last_sent_at', 'expiry_reminder_count', 'uid',
            ],
            BusinessDocumentVersion::class => ['state', 'content_hash', 'issued_at', 'superseded_at', 'draft_guard', 'uid'],
            BusinessDocumentPaymentScheduleItem::class => ['status', 'paid_at', 'reminder_last_sent_at', 'reminder_count'],
            BusinessStripeConnection::class => [
                'status', 'charges_enabled', 'payouts_enabled', 'details_submitted', 'requirements_disabled_reason',
                'default_currency', 'connected_at', 'disconnected_at', 'last_synced_at', 'lock_version', 'active_business_id',
            ],
            BusinessPaymentEvent::class => [
                'state', 'attempts', 'processing_started_at', 'lease_expires_at', 'last_attempt_at', 'completed_at', 'last_error',
            ],
            BusinessDocumentPayment::class => [
                'status', 'provider_payment_intent_id', 'provider_charge_id', 'failure_code', 'succeeded_at',
                'receipt_sent_at', 'active_schedule_item_id', 'uid',
            ],
            BusinessDocumentRefund::class => ['status', 'provider_refund_id', 'succeeded_at', 'uid'],
        ];

        foreach ($protected as $class => $columns) {
            $model = new $class();

            foreach ($columns as $column) {
                $this->assertFalse($model->isFillable($column), "{$class}::{$column} must not be mass-assignable.");
            }
        }
    }

    public function test_mass_assigning_a_lifecycle_field_is_silently_discarded_not_persisted(): void
    {
        $bundle = $this->documentsBundle();

        $document = BusinessDocument::create([
            'business_id' => $bundle['business']->id,
            'business_location_id' => $bundle['location']->id,
            'contact_id' => $bundle['contactId'],
            'kind' => DocumentKind::Invoice,
            'title' => 'Invoice',
            'currency_code' => 'USD',
            'status' => 'paid',
            'current_version_id' => 9999,
            'access_token_hash' => 'stolen',
            'paid_at' => now(),
        ])->fresh();

        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertNull($document->current_version_id);
        $this->assertNull($document->access_token_hash);
        $this->assertNull($document->paid_at);
    }

    public function test_the_hashed_link_token_and_encrypted_payload_are_hidden_from_serialization(): void
    {
        $bundle = $this->documentsBundle();

        $document = BusinessDocument::create([
            'business_id' => $bundle['business']->id,
            'business_location_id' => $bundle['location']->id,
            'contact_id' => $bundle['contactId'],
            'kind' => DocumentKind::Proposal,
            'title' => 'Hidden',
            'currency_code' => 'USD',
        ]);
        $document->forceFill(['access_token_hash' => 'hashed'])->save();

        $this->assertArrayNotHasKey('access_token_hash', $document->fresh()->toArray());

        $event = BusinessPaymentEvent::create([
            'stripe_account_id' => 'acct_x',
            'provider_event_id' => 'evt_hidden',
            'event_type' => 'payment_intent.succeeded',
            'payload_hash' => str_repeat('e', 64),
            'payload_encrypted' => '{"secret":"body"}',
        ]);

        $this->assertArrayNotHasKey('payload_encrypted', $event->fresh()->toArray());
    }

    // ------------------------------------------------------------------
    // Write-once / encrypted behaviour
    // ------------------------------------------------------------------

    public function test_write_once_models_carry_no_updated_at(): void
    {
        $this->assertNull(BusinessDocumentLineItem::UPDATED_AT);
        $this->assertNull(BusinessDocumentSignature::UPDATED_AT);
        $this->assertNull(BusinessPaymentEvent::UPDATED_AT);

        $this->assertNotNull(BusinessDocumentVersion::UPDATED_AT, 'A DRAFT version is edited in place; the row keeps updated_at.');
        $this->assertNotNull(BusinessDocumentPayment::UPDATED_AT);
    }

    public function test_the_event_payload_is_encrypted_at_rest_and_decrypted_by_the_model(): void
    {
        $plaintext = '{"id":"evt_1","type":"payment_intent.succeeded"}';

        $event = BusinessPaymentEvent::create([
            'stripe_account_id' => 'acct_enc',
            'provider_event_id' => 'evt_enc',
            'event_type' => 'payment_intent.succeeded',
            'payload_hash' => hash('sha256', $plaintext),
            'payload_encrypted' => $plaintext,
        ]);

        $raw = DB::table('business_payment_events')->where('id', $event->id)->value('payload_encrypted');

        $this->assertNotSame($plaintext, $raw, 'The payload must be encrypted at rest.');
        $this->assertStringNotContainsString('payment_intent', (string) $raw);
        $this->assertSame($plaintext, $event->fresh()->payload_encrypted);
    }

    public function test_there_is_no_client_secret_attribute_on_the_payment_model(): void
    {
        $f = $this->documentWithVersion();

        $payment = new BusinessDocumentPayment();

        $this->assertFalse($payment->isFillable('client_secret'));
        $this->assertNotContains('client_secret', array_keys($payment->getCasts()));
        $this->assertNotContains('client_secret', $payment->getFillable());
        $this->assertNotNull($f['document']);
    }
}
