<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Calendar\AppointmentScheduled;
use App\Library\Documents\DocumentManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Library\Payments\PaymentManager;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessStripeConnection;
use App\Models\CrmOpportunity;
use App\Models\CrmPipelineStage;
use App\Models\Contacts;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Workflow\CrossDomain\Support\BuildsCrossDomainFixtures;
use Tests\Support\Documents\ShownVersion;
use Tests\TestCase;

/**
 * THE AUTOMATIONS V1 ACCEPTANCE JOURNEY.
 *
 * One photo-booth business, five published workflows, and a real customer walking
 * through every domain. Each handoff uses its domain's own seam — the Forms
 * service, the Calendar's event, DocumentManager, PaymentManager and Stripe's
 * webhook, Business Email, the CRM service — with every provider faked and nothing
 * sent for real:
 *
 *   form submitted ─▶ contact + deal ─▶ [thank-you email, booking link]
 *   appointment booked ─▶ [deal → Qualified, proposal created & sent]
 *   proposal signed ─▶ [secure payment link re-sent, deal → Proposal sent]
 *   payment succeeds (Stripe webhook) ─▶ [questionnaire sent, team told]
 *   questionnaire submitted ─▶ [deal → Negotiating, team told]
 *
 * And at the end: every side effect happened exactly once, and replaying every event
 * the journey produced changes nothing.
 */
class AutomationsV1JourneyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCrossDomainFixtures;

    /** @var array<string, mixed> */
    private array $world;

    /** @var list<CrmPipelineStage> */
    private array $stages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = $this->xWorld();
        $this->allowPaymentEntitlement();
        Notification::fake();
    }

    /** Every active journey runs to rest, as the queue would. */
    private function settle(): void
    {
        for ($round = 0; $round < 8; $round++) {
            $active = AutomationEnrollment::query()->where('status', EnrollmentStatus::Active->value)->get();

            if ($active->isEmpty()) {
                return;
            }

            $active->each(fn (AutomationEnrollment $enrollment) => $this->xAdvance($enrollment));
        }

        $this->fail('The journeys did not come to rest.');
    }

    private function workflow(WorkflowTriggerType $trigger, array $steps, array $config = []): AutomationWorkflow
    {
        return $this->triggerWorkflow($this->world['business'], $trigger, $config, [...$steps, $this->endStep()]);
    }

    private function lastIssuedToken(): string
    {
        $entries = collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])
            ->flatMap(fn (array $byClass) => $byClass[DocumentIssuedNotification::class] ?? [])
            ->values();

        $this->assertNotEmpty($entries, 'A secure link was emailed.');

        return (string) (new \ReflectionProperty($entries->last()['notification'], 'plaintextToken'))->getValue($entries->last()['notification']);
    }

    private function stageOf(CrmOpportunity $deal): int
    {
        return (int) DB::table('crm_opportunities')->where('id', $deal->id)->value('stage_id');
    }

    public function test_a_lead_becomes_a_paying_customer_with_every_handoff_through_its_own_domain(): void
    {
        $business = $this->world['business'];
        $location = $this->world['location'];
        $pipeline = $this->formsPipeline($business);
        $this->stages = CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('position')->get()->all();
        [$newInquiry, $qualified, $proposalSent, $negotiating] = $this->stages;

        $bookingType = $this->xBookingType($location, 'Photo booth consultation');
        $package = $this->xCatalogItem($business, 'Wedding photo booth', 80000);
        [$leadForm, $leadDeployment] = $this->liveForm($business, $location, ['create_opportunity' => true]);
        $questionnaire = $this->makeQuestionnaire($business, ['create_opportunity' => false]);
        $questionnaireDeployment = $this->deploy($business, $questionnaire, $location);

        // ---- the five published workflows ----------------------------------------------------
        $welcome = $this->workflow(WorkflowTriggerType::FormSubmitted, [
            $this->xNode('send_email', ['subject' => 'Thanks for your enquiry', 'body' => 'We have your details.']),
            $this->xNode('send_booking_link', ['booking_type_id' => $bookingType->id, 'channels' => ['email']]),
        ], ['form_id' => $leadForm->id]);

        $booked = $this->workflow(WorkflowTriggerType::AppointmentScheduled, [
            $this->xNode('move_opportunity', ['pipeline_id' => $pipeline->id, 'stage_id' => $qualified->id]),
            $this->xNode('create_send_proposal', ['title' => 'Wedding photo booth', 'catalog_item_id' => $package->id, 'quantity' => 1, 'payment_schedule' => 'full']),
        ]);

        $signed = $this->workflow(WorkflowTriggerType::DocumentSigned, [
            $this->xNode('request_payment', ['source' => 'document']),
            $this->xNode('move_opportunity', ['pipeline_id' => $pipeline->id, 'stage_id' => $proposalSent->id]),
        ], ['document_kind' => 'proposal']);

        $paid = $this->workflow(WorkflowTriggerType::PaymentSucceeded, [
            $this->xNode('send_questionnaire', ['form_id' => $questionnaire->id, 'channels' => ['email']]),
            $this->xNode('internal_notification', ['message' => 'Deposit received — questionnaire sent']),
        ]);

        $answered = $this->workflow(WorkflowTriggerType::QuestionnaireSubmitted, [
            $this->xNode('move_opportunity', ['pipeline_id' => $pipeline->id, 'stage_id' => $negotiating->id]),
            $this->xNode('internal_notification', ['message' => 'Questionnaire answered']),
        ], ['form_id' => $questionnaire->id]);

        // ---- 1. A form is submitted: a contact and a deal exist, and the welcome workflow enrolls ----
        $submission = app(FormSubmissionService::class)->submit($leadDeployment->uid, $this->submitInput($leadDeployment))->submission;
        $contact = Contacts::query()->findOrFail($submission->contact_id);
        $deal = CrmOpportunity::query()->findOrFail($submission->crm_opportunity_id);

        $this->assertSame(1, $this->enrollmentCount($welcome));
        $this->assertSame((int) $newInquiry->id, $this->stageOf($deal));
        $this->settle();

        $this->assertSame(2, $this->fakeGoogle->callCount('send'), 'Thank-you email and booking link.');
        $this->assertSame('ada@example.test', $this->fakeGoogle->sent[0]->toEmail);
        $this->assertStringContainsString(route('public.booking.show', [$bookingType->public_booking_uuid]), $this->fakeGoogle->sent[1]->bodyText);

        // ---- 2. The contact books: the Calendar announces it ------------------------------------
        $appointmentId = DB::table('appointments')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_location_id' => $location->id,
            'booking_type_id' => $bookingType->id,
            'staff_user_id' => $this->world['customer']->user_id,
            'contact_id' => $contact->id,
            'crm_opportunity_id' => $deal->id,
            'status' => 'scheduled',
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scheduled = new AppointmentScheduled($appointmentId, (int) $business->id, (int) $location->id, (int) $bookingType->id, (int) $this->world['customer']->user_id, (int) $contact->id, (int) $deal->id, now()->addDays(3)->toIso8601String(), now()->addDays(3)->addMinutes(30)->toIso8601String(), null);
        event($scheduled);
        $this->settle();

        $this->assertSame(1, $this->enrollmentCount($booked));
        $this->assertSame((int) $qualified->id, $this->stageOf($deal), 'The CRM service moved the deal.');
        $proposal = BusinessDocument::query()->where('contact_id', $contact->id)->where('kind', 'proposal')->sole();
        $this->assertSame('sent', $proposal->status->value);
        $this->assertSame((int) $deal->id, (int) $proposal->crm_opportunity_id, 'The proposal is linked to the contact\'s deal.');
        $this->assertSame(80000, (int) DB::table('business_document_versions')->where('id', $proposal->current_version_id)->value('total_minor'));
        $firstToken = $this->lastIssuedToken();

        // ---- 3. The contact signs: payment is requested, the deal moves ------------------------------
        app(DocumentManager::class)->sign($proposal->refresh(), [
            'displayed_version_uid' => ShownVersion::uid($proposal),
            'signer_name' => 'Ada Lovelace', 'signer_email' => 'ada@example.test', 'typed_name' => 'Ada Lovelace',
            'ip_address' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Test)',
        ]);
        $this->settle();

        $this->assertSame(1, $this->enrollmentCount($signed));
        $this->assertSame((int) $proposalSent->id, $this->stageOf($deal));
        $secondToken = $this->lastIssuedToken();
        $this->assertNotSame($firstToken, $secondToken, 'The payment request is a fresh secure link; the old one no longer works.');

        // ---- 4. The contact pays through Stripe's webhook ---------------------------------------------
        $proposal = $proposal->refresh();
        app(PaymentManager::class)->start($this->accessFor($proposal, $secondToken));
        $payment = BusinessDocumentPayment::query()->where('business_document_id', $proposal->id)->firstOrFail();
        $connection = BusinessStripeConnection::query()->findOrFail($payment->business_stripe_connection_id);
        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $payment->provider_payment_intent_id, (string) $connection->stripe_account_id, (int) $payment->amount_minor, (string) $payment->currency_code, (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();
        $this->settle();

        $this->assertSame('paid', DB::table('business_documents')->where('id', $proposal->id)->value('status'));
        $this->assertSame(1, $this->enrollmentCount($paid));
        $questionnaireMail = collect($this->fakeGoogle->sent)->first(fn ($mail) => str_contains($mail->bodyText, route('public.forms.show', [$questionnaireDeployment->uid])));
        $this->assertNotNull($questionnaireMail, 'The questionnaire link was emailed after payment.');
        $this->assertStringContainsString('A few questions', $questionnaireMail->subject);

        // ---- 5. The contact answers the questionnaire ------------------------------------------------------
        $service = app(FormSubmissionService::class);
        $token = FormOperationToken::issue($questionnaireDeployment);
        $answers = $this->questionnaireAnswers();
        $answers['page_1']['phone'] = '+1 (415) 555-1234';
        foreach (['page_1', 'page_2', 'page_3'] as $page) {
            $service->submit($questionnaireDeployment->uid, $this->stepInput($token, $page, $answers[$page]));
        }
        $this->settle();

        $this->assertSame(1, $this->enrollmentCount($answered));
        $this->assertSame((int) $negotiating->id, $this->stageOf($deal), 'The deal reached its final stage.');

        // ---- Everything happened exactly once ----------------------------------------------------------------
        $this->assertSame(1, Contacts::query()->where('business_id', $business->id)->where('phone', 'like', '%4155551234')->count(), 'One person throughout.');
        $this->assertSame(0, AutomationEnrollment::query()->whereNotIn('status', [EnrollmentStatus::Completed->value])->count(), 'Every journey completed.');
        $this->assertSame(1, DB::table('business_documents')->where('contact_id', $contact->id)->count());
        $this->assertSame(3, DB::table('business_email_messages')->where('business_id', $business->id)->count(), 'Thank-you, booking link and questionnaire — by Business Email.');
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 2);

        // ---- And replaying every event the journey produced changes nothing -----------------------------------
        $enrollments = AutomationEnrollment::query()->count();
        $mails = $this->fakeGoogle->callCount('send');
        $this->finishJourneys();
        event($scheduled);
        event(new \App\Events\Forms\FormSubmissionRecorded((int) $business->id, (int) $location->id, (int) $leadForm->id, (int) $submission->form_version_id, (int) $submission->id, (int) $contact->id, (int) $deal->id, 'created', 'form_submission:' . $submission->uid));
        $this->postWebhook($body, $headers)->assertOk();
        $this->settle();

        $this->assertSame($enrollments, AutomationEnrollment::query()->count(), 'No event enrolls anybody twice.');
        $this->assertSame($mails, $this->fakeGoogle->callCount('send'), 'No replay sends anything twice.');
        $this->assertSame((int) $negotiating->id, $this->stageOf($deal));
    }
}
