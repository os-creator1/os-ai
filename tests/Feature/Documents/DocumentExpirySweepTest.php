<?php

namespace Tests\Feature\Documents;

use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Events\DocumentExpired;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Payments\Concerns\CreatesRefundablePayments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §8.6 — `expires_at` is an OFFER expiry, and the
 * sweep must never strand a signature or captured money.
 */
class DocumentExpirySweepTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRefundablePayments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
        config(['documents.enabled' => true]);
    }

    /** An INVOICE — payable, and expirable, while merely `sent`. */
    private function sentInvoice(array $tenant, ?array $schedule = null): array
    {
        $manager = app(DocumentManager::class);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'], null,
            'invoice', 'Kitchen invoice', $tenant['customer']->user);
        $manager->addCustomLine($document, 'Work', null, 1, 50000);
        $manager->setSchedule($document, $schedule ?? [['kind' => 'full', 'amount_minor' => 50000, 'currency_code' => 'USD']]);
        $manager->edit($document, ['recipient_email_snapshot' => 'client@example.test']);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        return ['tenant' => $tenant, 'document' => $document->refresh(), 'token' => $token];
    }

    private function expiresAt(BusinessDocument $document, string $when): void
    {
        DB::table('business_documents')->where('id', $document->id)->update(['expires_at' => $when]);
    }

    // =================================================================
    // What the sweep MAY expire
    // =================================================================

    public function test_an_unsigned_unpaid_sent_document_past_its_expiry_is_expired(): void
    {
        Event::fake([DocumentExpired::class]);
        $tenant = $this->sendableTenant();
        $fixture = $this->sentInvoice($tenant);
        $this->expiresAt($fixture['document'], now()->subDay()->toDateTimeString());

        $this->assertSame(1, app(DocumentManager::class)->expireDue(100));

        $document = $fixture['document']->refresh();
        $this->assertSame(DocumentStatus::Expired, $document->status);
        $this->assertNotNull($document->expired_at);
        Event::assertDispatched(DocumentExpired::class, 1);
    }

    public function test_a_document_whose_expiry_has_not_arrived_is_left_alone(): void
    {
        $fixture = $this->sentInvoice($this->sendableTenant());
        $this->expiresAt($fixture['document'], now()->addDay()->toDateTimeString());

        $this->assertSame(0, app(DocumentManager::class)->expireDue(100));
        $this->assertSame(DocumentStatus::Sent, $fixture['document']->refresh()->status);
    }

    public function test_a_document_with_no_expiry_never_expires(): void
    {
        $fixture = $this->sentInvoice($this->sendableTenant());
        DB::table('business_documents')->where('id', $fixture['document']->id)->update(['expires_at' => null]);

        $this->assertSame(0, app(DocumentManager::class)->expireDue(100));
        $this->assertSame(DocumentStatus::Sent, $fixture['document']->refresh()->status);
    }

    // =================================================================
    // §8.6 — what the sweep may NEVER expire
    // =================================================================

    public function test_a_signed_proposal_is_never_swept(): void
    {
        $fixture = $this->payableDocument();
        $this->assertSame(DocumentStatus::Signed, $fixture['document']->status);
        $this->expiresAt($fixture['document'], now()->subWeek()->toDateTimeString());

        $this->assertSame(0, app(DocumentManager::class)->expireDue(100));
        $this->assertSame(DocumentStatus::Signed, $fixture['document']->refresh()->status,
            'A signature is never stranded by an offer expiry.');
    }

    public function test_an_invoice_with_a_successful_payment_is_never_swept(): void
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);
        $fixture = $this->sentInvoice($tenant);

        $this->captureNextItem($fixture);
        $this->expiresAt($fixture['document'], now()->subWeek()->toDateTimeString());

        $this->assertSame(0, app(DocumentManager::class)->expireDue(100));
        $this->assertSame(DocumentStatus::Paid, $fixture['document']->refresh()->status);
    }

    public function test_a_deposit_paid_balance_outstanding_document_is_never_swept(): void
    {
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);
        $fixture = $this->sentInvoice($tenant, $this->depositAndBalance());

        $deposit = $this->captureNextItem($fixture);
        $this->assertSame(20000, (int) $deposit->amount_minor);
        $this->expiresAt($fixture['document'], now()->subWeek()->toDateTimeString());

        $this->assertSame(0, app(DocumentManager::class)->expireDue(100));

        $document = $fixture['document']->refresh();
        $this->assertSame(DocumentStatus::Sent, $document->status, 'The deposit must not be stranded.');

        $schedule = BusinessDocumentPaymentScheduleItem::query()->orderBy('sequence')->get();
        $this->assertSame(PaymentScheduleItemStatus::Paid, $schedule[0]->status);
        $this->assertSame(PaymentScheduleItemStatus::Pending, $schedule[1]->status,
            'The balance stays due on its own schedule.');
    }

    public function test_terminal_documents_stay_terminal(): void
    {
        $fixture = $this->sentInvoice($this->sendableTenant());
        app(DocumentManager::class)->void($fixture['document'], 'Cancelled.');
        $this->expiresAt($fixture['document'], now()->subWeek()->toDateTimeString());

        $this->assertSame(0, app(DocumentManager::class)->expireDue(100));
        $this->assertSame(DocumentStatus::Void, $fixture['document']->refresh()->status);
    }

    // =================================================================
    // Sweep mechanics
    // =================================================================

    public function test_a_second_run_expires_nothing_and_changes_nothing(): void
    {
        $fixture = $this->sentInvoice($this->sendableTenant());
        $this->expiresAt($fixture['document'], now()->subDay()->toDateTimeString());

        $this->assertSame(1, app(DocumentManager::class)->expireDue(100));
        $expiredAt = $fixture['document']->refresh()->expired_at;

        $this->assertSame(0, app(DocumentManager::class)->expireDue(100));
        $this->assertEquals($expiredAt, $fixture['document']->refresh()->expired_at);
    }

    public function test_the_batch_is_bounded_by_the_limit(): void
    {
        $tenant = $this->sendableTenant();
        foreach (range(1, 3) as $ignored) {
            $fixture = $this->sentInvoice($tenant);
            $this->expiresAt($fixture['document'], now()->subDay()->toDateTimeString());
        }

        $this->assertSame(2, app(DocumentManager::class)->expireDue(2));
        $this->assertSame(1, app(DocumentManager::class)->expireDue(2));
        $this->assertSame(0, app(DocumentManager::class)->expireDue(2));
    }

    public function test_expiring_preserves_the_immutable_version_content(): void
    {
        $fixture = $this->sentInvoice($this->sendableTenant());
        $this->expiresAt($fixture['document'], now()->subDay()->toDateTimeString());

        $version = BusinessDocumentVersion::query()->findOrFail($fixture['document']->current_version_id);
        $before = [$version->content_hash, $version->total_minor, $version->state];

        app(DocumentManager::class)->expireDue(100);

        $version->refresh();
        $this->assertSame($before, [$version->content_hash, $version->total_minor, $version->state]);
        $this->assertSame(1, BusinessDocumentPaymentScheduleItem::query()
            ->where('status', PaymentScheduleItemStatus::Pending->value)->count(),
            '§8.6 prescribes no schedule transition on expiry, and none is invented.');
    }
}
