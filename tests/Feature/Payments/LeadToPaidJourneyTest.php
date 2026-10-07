<?php

namespace Tests\Feature\Payments;

use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Events\DocumentFullyPaid;
use App\Events\DocumentPaymentSucceeded;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Library\Documents\DocumentContentHasher;
use App\Library\Documents\DocumentManager;
use App\Library\Payments\PaymentManager;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentPaymentScheduleItem;
use App\Models\BusinessDocumentVersion;
use App\Models\BusinessPaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\Support\Documents\ShownVersion;
use Tests\TestCase;

/**
 * V1 final acceptance — one composed customer journey over the real public
 * routes and the real webhook boundary (fake Stripe gateway only):
 *
 *   Lead (Contact + Opportunity) -> Proposal/Contract -> public view -> signature
 *   -> deposit -> replayed + tampered webhooks -> balance -> paid.
 *
 * Each step is already proved in isolation elsewhere; this pins that the
 * COMPOSITION keeps the money honest: two payment rows, one effect per
 * settlement, the signed revision untouched by payment, and the CRM
 * Opportunity stage unmoved (Blueprint §9).
 */
class LeadToPaidJourneyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPayableDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGateway();
        $this->allowPaymentEntitlement();
    }

    public function test_lead_to_contract_to_signature_to_deposit_to_balance_has_one_effect_per_settlement(): void
    {
        Notification::fake();
        $succeeded = 0;
        $fullyPaid = 0;
        Event::listen(DocumentPaymentSucceeded::class, function () use (&$succeeded): void { $succeeded++; });
        Event::listen(DocumentFullyPaid::class, function () use (&$fullyPaid): void { $fullyPaid++; });

        // --- Lead: a Contact with an open Opportunity at the Location -------
        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);
        $pipeline = app(CrmPipelineService::class)->setUpStandardPipeline($tenant['business']);
        $opportunity = app(CrmOpportunityService::class)
            ->create($tenant['business'], $pipeline, $tenant['contact'], 'Wedding booth', null, null);
        DB::table('crm_opportunities')->where('id', $opportunity->id)->update(['location_id' => $tenant['location']->id]);
        $stageBefore = $opportunity->refresh()->stage_id;

        // --- Contract with a 40% deposit and a balance ----------------------
        $manager = app(DocumentManager::class);
        $document = $manager->create($tenant['business'], $tenant['location'], $tenant['contact'],
            $opportunity->fresh(), 'proposal', 'Booth hire agreement', $tenant['customer']->user);
        $manager->addCustomLine($document, 'Booth hire', null, 1, 50000);
        $manager->setSchedule($document, $this->depositAndBalance());
        $manager->edit($document, [
            'recipient_email_snapshot' => 'client@example.test',
            'recipient_name_snapshot' => 'Pat Rivera',
        ]);
        [$document, $token] = $this->sendAndCaptureToken($document->refresh());

        // --- Public view carries the Business's own identity ---------------
        $this->get($this->publicUrl($document, $token))
            ->assertOk()
            ->assertSee('Booth hire agreement')
            ->assertSee($tenant['business']->name);

        // --- Unsigned: no charge can start ----------------------------------
        $this->assertFalse($this->postJson($this->payUrl($document, $token))->isSuccessful());
        $this->assertSame(0, BusinessDocumentPayment::query()->count());

        // --- Signature -------------------------------------------------------
        $this->post($this->signUrl($document, $token), [
            'displayed_version_uid' => ShownVersion::uid($document),
            'signer_name' => 'Pat Rivera',
            'signer_email' => 'pat@example.test',
            'typed_name' => 'Pat Rivera',
        ])->assertOk()->assertSee('Signature recorded');

        $document = $document->refresh();
        $version = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $signedHash = $version->content_hash;
        $this->assertSame($signedHash, app(DocumentContentHasher::class)->hash($version));

        // --- Deposit ---------------------------------------------------------
        $this->postJson($this->payUrl($document, $token))->assertOk()->assertJsonStructure(['client_secret']);
        // A second click re-drives the SAME attempt, never a second charge.
        $this->postJson($this->payUrl($document, $token))->assertOk();
        $this->assertSame(1, BusinessDocumentPayment::query()->count());
        $deposit = BusinessDocumentPayment::query()->sole();
        $this->assertSame(20000, (int) $deposit->amount_minor);

        // Tampered amount and currency are refused and settle nothing.
        foreach ([['amount' => 1], ['currency' => 'EUR']] as $tamper) {
            [$body, $headers] = $this->webhookPayload(
                'payment_intent.succeeded', (string) $deposit->provider_payment_intent_id, 'acct_ready001',
                $tamper['amount'] ?? 20000, $tamper['currency'] ?? 'USD',
                (string) $deposit->local_idempotency_key,
            );
            $this->postWebhook($body, $headers)->assertOk();
        }
        $this->assertSame('created', $deposit->refresh()->status->value);
        $this->assertSame(0, $succeeded);

        // The genuine event, delivered three times (duplicate + replay).
        [$body, $headers] = $this->webhookPayload(
            'payment_intent.succeeded', (string) $deposit->provider_payment_intent_id, 'acct_ready001',
            20000, 'USD', (string) $deposit->local_idempotency_key, 'evt_deposit_once',
        );
        foreach ([1, 2, 3] as $_) {
            $this->postWebhook($body, $headers)->assertOk();
        }
        $this->assertSame(1, BusinessPaymentEvent::query()->where('provider_event_id', 'evt_deposit_once')->count());
        $this->assertSame('succeeded', $deposit->refresh()->status->value);
        $this->assertSame(1, $succeeded);
        $this->assertSame(0, $fullyPaid, 'A deposit is not a settled document.');
        $this->assertSame(DocumentStatus::Signed, $document->refresh()->status);

        // --- Balance ---------------------------------------------------------
        app(PaymentManager::class)->start($this->accessFor($document->refresh(), $token));
        $this->assertSame(2, BusinessDocumentPayment::query()->count());
        $balance = BusinessDocumentPayment::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(30000, (int) $balance->amount_minor);

        [$body, $headers] = $this->webhookPayload(
            'payment_intent.succeeded', (string) $balance->provider_payment_intent_id, 'acct_ready001',
            30000, 'USD', (string) $balance->local_idempotency_key, 'evt_balance_once',
        );
        $this->postWebhook($body, $headers)->assertOk();
        $this->postWebhook($body, $headers)->assertOk();

        // --- Final state -----------------------------------------------------
        $this->assertSame(DocumentStatus::Paid, $document->refresh()->status);
        $this->assertSame(2, BusinessDocumentPayment::query()->count(), 'No double payment record.');
        $this->assertSame(2, BusinessDocumentPaymentScheduleItem::query()->where('status', PaymentScheduleItemStatus::Paid->value)->count());
        $this->assertSame(2, $succeeded);
        $this->assertSame(1, $fullyPaid);

        // The signed revision is untouched by payment...
        $this->assertSame($signedHash, $version->fresh()->content_hash);
        $this->assertSame($version->id, (int) $document->current_version_id);
        // ...and a paid contract accepts no further authoring.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            $manager->addCustomLine($document, 'Sneaky extra', null, 1, 100);
        } finally {
            $this->assertSame($stageBefore, $opportunity->refresh()->stage_id,
                'Blueprint §9: payment progress never advances a pipeline stage.');
        }
    }
}
