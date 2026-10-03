<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\Documents\DocumentManager;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\BusinessDocument;
use App\Models\CatalogItem;
use App\Models\Contacts;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Workflow\CrossDomain\Support\BuildsCrossDomainFixtures;
use Tests\TestCase;

/**
 * Automations x Documents / Payments — Create & send proposal and Request payment.
 *
 * Every document is made, priced, scheduled and sent by DocumentManager; a payment
 * is requested only as that manager's secure link; nothing is hand-built, nothing
 * charges a card, and both are at-most-once.
 */
class DocumentActionsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCrossDomainFixtures;

    /** @var array<string, mixed> */
    private array $world;

    private CatalogItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = $this->xWorld();
        Notification::fake();
        $this->item = $this->xCatalogItem($this->world['business'], 'Photo booth hire', 50000);
    }

    private function proposalStep(array $overrides = []): array
    {
        return $this->xNode('create_send_proposal', $overrides + [
            'title' => 'Photo booth for the wedding',
            'catalog_item_id' => $this->item->id,
            'quantity' => 1,
            'payment_schedule' => 'full',
        ]);
    }

    private function journey(AutomationWorkflow $workflow, ?Contacts $contact = null, $pinned = null): AutomationEnrollment
    {
        return $this->xAdvance($this->xEnroll($workflow, $contact ?? $this->world['contact'], 'k-' . uniqid(), $pinned));
    }

    private function documents(string $kind = null): \Illuminate\Support\Collection
    {
        return BusinessDocument::query()
            ->where('business_id', $this->world['business']->id)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->orderBy('id')
            ->get();
    }

    /** Documents the AUTOMATION made — not the fixture contact's own. */
    private function made(string $kind = null): \Illuminate\Support\Collection
    {
        return $this->documents($kind)->where('title', '!=', 'Kitchen renovation proposal')->values();
    }

    // =================================================================
    // Create & send proposal
    // =================================================================

    public function test_the_proposal_is_made_priced_and_sent_by_the_document_manager(): void
    {
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->proposalStep()]);

        $enrollment = $this->journey($workflow);

        $document = $this->made('proposal')->sole();
        $this->assertSame('sent', $document->status->value);
        $this->assertTrue((bool) $document->requires_signature, 'A proposal is the signed agreement: a contract is the same kind.');
        $this->assertSame('Photo booth for the wedding', $document->title);
        $this->assertSame((int) $this->world['contact']->id, (int) $document->contact_id);
        $this->assertSame('pat@example.com', $document->recipient_email_snapshot);

        $version = DB::table('business_document_versions')->where('business_document_id', $document->id)->where('state', 'issued')->first();
        $this->assertSame(50000, (int) $version->total_minor, 'The line is the catalog\'s frozen price snapshot.');
        $this->assertSame('catalog', DB::table('business_document_line_items')->where('business_document_version_id', $version->id)->value('source'));
        $this->assertSame(['full'], DB::table('business_document_payment_schedule_items')->where('business_document_version_id', $version->id)->pluck('kind')->all());

        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
        $this->assertSame(StepRunStatus::Succeeded, $this->xStep($enrollment, 'create_send_proposal')->status);
        $this->assertSame('Proposal sent to p***@example.com', $this->xStep($enrollment, 'create_send_proposal')->safe_result_summary);
    }

    public function test_a_deposit_schedule_splits_the_total_exactly(): void
    {
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->proposalStep(['payment_schedule' => 'deposit', 'deposit_percent' => 30, 'quantity' => 2])]);

        $this->journey($workflow);

        $document = $this->made('proposal')->sole();
        $versionId = (int) $document->current_version_id;
        $this->assertSame(100000, (int) DB::table('business_document_versions')->where('id', $versionId)->value('total_minor'));
        $this->assertSame(
            [['deposit', 30000], ['balance', 70000]],
            DB::table('business_document_payment_schedule_items')->where('business_document_version_id', $versionId)->orderBy('sequence')->get()
                ->map(fn ($row) => [$row->kind, (int) $row->amount_minor])->all(),
        );
    }

    public function test_a_replay_of_the_step_creates_and_sends_nothing_twice(): void
    {
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->proposalStep()]);
        $enrollment = $this->xEnroll($workflow, $this->world['contact'], 'k1');

        $this->xAdvance($enrollment);
        $this->xAdvance($enrollment->fresh());
        app(\App\Library\Automation\Workflow\Runtime\WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(1, $this->made('proposal')->count(), 'One claimed step, one document.');
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
    }

    public function test_the_document_an_automation_sends_does_not_restart_the_workflow_that_sent_it(): void
    {
        // "When a document is sent, create and send a proposal" — it would feed itself forever.
        $workflow = $this->triggerWorkflow($this->world['business'], WorkflowTriggerType::DocumentSent, [], [$this->proposalStep(), $this->endStep()]);

        $this->journey($workflow);

        $this->assertSame(1, AutomationEnrollment::query()->where('workflow_id', $workflow->id)->count(), 'Its own output never re-enrolls it.');
        $this->assertSame(1, $this->made('proposal')->count());
    }

    public function test_a_contact_with_no_single_email_gets_no_document_at_all(): void
    {
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->proposalStep()]);
        $none = $this->xContact($this->world, $this->world['location'], '14155557801', null);
        $two = $this->xContact($this->world, $this->world['location'], '14155557802', 'a@example.com');
        $this->xGiveEmail($this->world['business'], $two, 'a@example.com');
        DB::table('contacts_custom_field')->insert([
            'contact_id' => $two->id,
            'field_id' => DB::table('contact_group_fields')->where('contact_group_id', $two->group_id)->where('tag', 'EMAIL')->value('id'),
            'value' => 'b@example.com', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([$none, $two] as $contact) {
            $step = $this->xStep($this->journey($workflow, $contact), 'create_send_proposal');
            $this->assertSame(StepRunStatus::Skipped, $step->status);
            $this->assertSame('contact_email_unavailable', $step->safe_error_summary);
        }

        $this->assertSame(0, $this->made()->count(), 'Nothing was created, so no draft is left behind.');
    }

    public function test_an_archived_or_foreign_product_fails_closed_without_leaving_a_draft(): void
    {
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->proposalStep()]);
        $other = $this->sendableTenant('Other Studio');
        $theirItem = $this->xCatalogItem($other['business'], 'Their package', 99900);

        DB::table('catalog_items')->where('id', $this->item->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);
        $this->assertSame('catalog_item_unavailable', $this->xStep($this->journey($workflow), 'create_send_proposal')->safe_error_summary);

        DB::table('automation_workflow_nodes')->where('version_id', $workflow->published_version_id)->where('node_type', 'create_send_proposal')
            ->update(['config' => json_encode(['title' => 'X', 'catalog_item_id' => $theirItem->id, 'quantity' => 1, 'payment_schedule' => 'full'])]);
        $contact = $this->xContact($this->world, $this->world['location'], '14155557803');
        $this->assertSame('catalog_item_unavailable', $this->xStep($this->journey($workflow, $contact), 'create_send_proposal')->safe_error_summary);

        $this->assertSame(0, $this->made()->count());
    }

    public function test_publish_refuses_what_the_account_cannot_run_or_what_is_not_theirs(): void
    {
        $other = $this->sendableTenant('Other Studio');
        $theirItem = $this->xCatalogItem($other['business'], 'Their package', 99900);

        try {
            $this->xManualWorkflow($this->world['business'], [$this->proposalStep(['catalog_item_id' => $theirItem->id])]);
            $this->fail('A foreign product must not publish.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('does not belong to this business', json_encode($exception->errors()));
        }

        foreach ([
            'empty title' => ['title' => ''],
            'zero quantity' => ['quantity' => 0],
            'quantity too big' => ['quantity' => 100],
            'unknown schedule' => ['payment_schedule' => 'monthly'],
            'deposit without a percent' => ['payment_schedule' => 'deposit'],
            'deposit too small' => ['payment_schedule' => 'deposit', 'deposit_percent' => 1],
            'percent with full' => ['deposit_percent' => 30],
        ] as $label => $override) {
            try {
                $this->xManualWorkflow($this->world['business'], [$this->proposalStep($override)]);
                $this->fail("{$label} must not publish.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->denyFeature($this->world['workspace'], PlatformFeature::PaymentsContracts);

        try {
            $this->xManualWorkflow($this->world['business'], [$this->proposalStep()]);
            $this->fail('Without Payments & contracts there is no proposal action.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('proposals', mb_strtolower(json_encode($exception->errors())));
        }
    }

    public function test_the_document_is_made_at_the_pinned_location_and_never_at_a_guessed_one(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->proposalStep()]);

        $this->journey($workflow, $this->xContact($this->world, $uptown, '14155557811'), $uptown);
        $this->assertSame((int) $uptown->id, (int) $this->made('proposal')->sole()->business_location_id, 'The pinned Location, not the Contact\'s default.');

        // Two active Locations and no pinned one: no inference from where the contact lives.
        $step = $this->xStep($this->journey($workflow, $this->xContact($this->world, null, '14155557812')), 'create_send_proposal');
        $this->assertSame('document_location_unresolved', $step->safe_error_summary);
        $this->assertSame(1, $this->made('proposal')->count());
    }

    public function test_the_deal_the_trigger_names_is_linked_to_the_document(): void
    {
        $workflow = $this->triggerWorkflow($this->world['business'], WorkflowTriggerType::OpportunityCreated, [], [$this->proposalStep(), $this->endStep()]);

        $deal = $this->xDeal($this->world['business'], $this->world['contact'], $this->world['location']);
        $this->xAdvance(AutomationEnrollment::query()->where('workflow_id', $workflow->id)->sole());

        $this->assertSame((int) $deal->id, (int) $this->made('proposal')->sole()->crm_opportunity_id);
    }

    public function test_a_contact_who_left_a_limited_journeys_location_gets_nothing(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->proposalStep()], ['business_location_id' => $this->world['location']->id]);
        $enrollment = $this->xEnroll($workflow, $this->world['contact'], 'k', $this->world['location']);
        DB::table('contacts')->where('id', $this->world['contact']->id)->update(['location_id' => $uptown->id]);

        $step = $this->xStep($this->xAdvance($enrollment), 'create_send_proposal');

        $this->assertSame('contact_outside_workflow_location', $step->safe_error_summary);
        $this->assertSame(0, $this->made()->count());
    }

    // =================================================================
    // Request payment — for the journey's document
    // =================================================================

    private function paymentFollowUp(array $step = null): AutomationWorkflow
    {
        return $this->triggerWorkflow($this->world['business'], WorkflowTriggerType::DocumentSent, [], [
            $step ?? $this->xNode('request_payment', ['source' => 'document']),
            $this->endStep(),
        ]);
    }

    private function sendTheContactsDocument(): BusinessDocument
    {
        return app(DocumentManager::class)->send($this->draftDocument($this->world));
    }

    public function test_the_documents_secure_link_is_re_sent_not_a_card_charged(): void
    {
        $workflow = $this->paymentFollowUp();
        $document = $this->sendTheContactsDocument();
        $before = DB::table('business_documents')->where('id', $document->id)->value('access_token_rotated_at');
        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->id)->sole();

        $this->travel(1)->minute();
        $step = $this->xStep($this->xAdvance($enrollment), 'request_payment');

        $this->assertSame(StepRunStatus::Succeeded, $step->status);
        $this->assertSame('Payment link sent', $step->safe_result_summary);
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 2);
        $this->assertNotSame($before, DB::table('business_documents')->where('id', $document->id)->value('access_token_rotated_at'), 'The link was rotated.');
        $this->assertSame(0, DB::table('business_document_payments')->count(), 'Nothing was charged and no payment row exists.');
    }

    public function test_a_paid_or_voided_document_is_not_requested_again(): void
    {
        $workflow = $this->paymentFollowUp();
        $document = $this->sendTheContactsDocument();
        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->id)->sole();
        DB::table('business_documents')->where('id', $document->id)->update(['status' => 'paid', 'paid_at' => now()]);

        $step = $this->xStep($this->xAdvance($enrollment), 'request_payment');

        $this->assertSame(StepRunStatus::Skipped, $step->status);
        $this->assertSame('document_not_payable', $step->safe_error_summary);
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
    }

    public function test_a_journey_that_is_not_about_a_document_cannot_publish_a_document_payment_request(): void
    {
        try {
            $this->xManualWorkflow($this->world['business'], [$this->xNode('request_payment', ['source' => 'document'])]);
            $this->fail('A manual workflow has no document to request payment for.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('starts from a proposal', json_encode($exception->errors()));
        }

        // A signed-proposal or payment trigger can.
        foreach ([WorkflowTriggerType::DocumentSigned, WorkflowTriggerType::PaymentFailed] as $trigger) {
            $this->assertNotNull($this->triggerWorkflow($this->world['business'], $trigger, [], [$this->xNode('request_payment', ['source' => 'document']), $this->endStep()])->published_version_id);
        }
    }

    public function test_a_documents_link_for_another_contact_or_business_is_never_resent(): void
    {
        $workflow = $this->paymentFollowUp();
        $document = $this->sendTheContactsDocument();
        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->id)->sole();
        // The journey's contact is not the document's contact (a tampered row).
        $someoneElse = $this->xContact($this->world, $this->world['location'], '14155557821');
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update(['contact_id' => $someoneElse->id]);

        $step = $this->xStep($this->xAdvance($enrollment->fresh()), 'request_payment');

        $this->assertSame('document_not_found', $step->safe_error_summary);
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
        $this->assertNotNull($document);
    }

    public function test_requesting_payment_needs_a_stripe_account_that_can_take_one(): void
    {
        $bare = $this->sendableTenant('Bare Studio');

        try {
            $this->triggerWorkflow($bare['business'], WorkflowTriggerType::DocumentSent, [], [$this->xNode('request_payment', ['source' => 'document']), $this->endStep()]);
            $this->fail('No connected account: no payment request.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Stripe', json_encode($exception->errors()));
        }

        // And a version published while it was true fails closed when it stops being true.
        $workflow = $this->paymentFollowUp();
        $this->sendTheContactsDocument();
        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->id)->sole();
        DB::table('business_stripe_connections')->where('business_id', $this->world['business']->id)->update(['status' => 'disconnected']);

        $this->assertSame('payment_request_unavailable', $this->xStep($this->xAdvance($enrollment), 'request_payment')->safe_error_summary);
    }

    // =================================================================
    // Request payment — a new invoice
    // =================================================================

    public function test_a_new_invoice_from_a_product_is_made_and_sent_as_an_invoice(): void
    {
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->xNode('request_payment', [
            'source' => 'invoice', 'title' => 'Booking deposit', 'catalog_item_id' => $this->item->id, 'quantity' => 2,
        ])]);

        $enrollment = $this->journey($workflow);

        $invoice = $this->made('invoice')->sole();
        $this->assertSame('sent', $invoice->status->value);
        $this->assertFalse((bool) $invoice->requires_signature, 'An invoice needs no signature, only a payment.');
        $this->assertSame(100000, (int) DB::table('business_document_versions')->where('id', $invoice->current_version_id)->value('total_minor'));
        $this->assertSame(['full'], DB::table('business_document_payment_schedule_items')->where('business_document_version_id', $invoice->current_version_id)->pluck('kind')->all());
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
        $this->assertSame('Payment request sent', $this->xStep($enrollment, 'request_payment')->safe_result_summary);
        $this->assertSame(0, DB::table('business_document_payments')->count());
    }

    public function test_a_new_invoice_for_a_fixed_amount_is_a_custom_line_and_a_replay_sends_one(): void
    {
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->xNode('request_payment', [
            'source' => 'invoice', 'title' => 'Travel fee', 'amount_minor' => 7550,
        ])]);
        $enrollment = $this->xEnroll($workflow, $this->world['contact'], 'k1');

        $this->xAdvance($enrollment);
        $this->xAdvance($enrollment->fresh());

        $invoice = $this->made('invoice')->sole();
        $this->assertSame(7550, (int) DB::table('business_document_versions')->where('id', $invoice->current_version_id)->value('total_minor'));
        $this->assertSame('custom', DB::table('business_document_line_items')->where('business_document_version_id', $invoice->current_version_id)->value('source'));
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
    }

    public function test_an_invoice_must_name_a_product_or_an_amount_but_not_both(): void
    {
        foreach ([
            'neither' => ['source' => 'invoice', 'title' => 'X'],
            'both' => ['source' => 'invoice', 'title' => 'X', 'catalog_item_id' => $this->item->id, 'amount_minor' => 100],
            'no title' => ['source' => 'invoice', 'title' => '', 'amount_minor' => 100],
            'unknown source' => ['source' => 'saved_card'],
        ] as $label => $config) {
            try {
                $this->xManualWorkflow($this->world['business'], [$this->xNode('request_payment', $config)]);
                $this->fail("{$label} must not publish.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
