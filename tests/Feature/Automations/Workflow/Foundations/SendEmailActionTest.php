<?php

namespace Tests\Feature\Automations\Workflow\Foundations;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus as Status;
use App\Enums\BusinessEmail\BusinessEmailSource;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Executors\SendEmailNodeExecutor;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\BusinessEmail\BusinessEmailSender;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\BusinessEmailMessage;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * Automations x Business Email — the Send email action.
 *
 * The provider is the Business Email foundation's own in-memory fake, so "how
 * many provider calls" is observable, and Http::preventStrayRequests() turns any
 * direct network call by the executor into a failure.
 */
class SendEmailActionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessEmailFixtures;
    use BuildsFoundationWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();
    }

    /** @return array{0: Business, 1: Contacts} an active Business with a connected mailbox and an emailable contact */
    private function readyTenant(string $email = 'pat@example.com'): array
    {
        [, $business] = $this->emailTenant();
        $business = $this->activate($business);
        $this->activeAccount($business);

        return [$business, $this->contactWithEmails($business, [$email], null, 'Pat')];
    }

    /** @return array{0: AutomationWorkflow, 1: AutomationWorkflowNode} */
    private function emailJourney(Business $business, array $step = null): array
    {
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [$step ?? $this->emailStep(), $this->endStep()]);
        $node = AutomationWorkflowNode::query()
            ->where('version_id', $workflow->published_version_id)
            ->where('node_type', 'send_email')
            ->sole();

        return [$workflow, $node];
    }

    private function runJourney(AutomationWorkflow $workflow, Contacts $contact): AutomationEnrollment
    {
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'manual-' . uniqid());
        $this->assertNotNull($enrollment);
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $enrollment->fresh();
    }

    private function stepRun(AutomationEnrollment $enrollment): AutomationStepRun
    {
        return AutomationStepRun::query()->where('enrollment_id', $enrollment->id)->where('node_type', 'send_email')->sole();
    }

    // =================================================================
    // 8. The canonical seam
    // =================================================================

    public function test_the_action_sends_through_business_email_and_records_a_bounded_result(): void
    {
        [$business, $contact] = $this->readyTenant();
        [$workflow] = $this->emailJourney($business, $this->emailStep('Welcome {FIRST_NAME}', 'We will be in touch.'));

        $enrollment = $this->runJourney($workflow, $contact);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame('pat@example.com', $this->fakeGoogle->sent[0]->toEmail);
        $this->assertSame('Welcome Pat', $this->fakeGoogle->sent[0]->subject, 'Merge tags are rendered for this contact.');

        $stepRun = $this->stepRun($enrollment);
        $message = BusinessEmailMessage::query()->sole();

        $this->assertSame(StepRunStatus::Succeeded, $stepRun->status);
        $this->assertSame(Status::Accepted, $message->status);
        $this->assertSame(BusinessEmailSource::Automation, $message->source);
        $this->assertSame((int) $stepRun->id, (int) $message->automation_step_run_id, 'The ledger row is attributed to the step run.');
        $this->assertSame((int) $business->id, (int) $message->business_id);
        $this->assertNotNull($message->location_id, 'The sender attributes a Location; the action never leaves it NULL.');

        // The run state records a bounded, non-secret result: a masked address, no body, no token.
        $this->assertSame('Email sent to p***@example.com', $stepRun->safe_result_summary);
        $this->assertStringNotContainsString('We will be in touch', (string) $stepRun->safe_result_summary);
    }

    public function test_the_executor_has_no_provider_or_ledger_access_of_its_own(): void
    {
        $source = file_get_contents(base_path('app/Library/Automation/Workflow/Executors/SendEmailNodeExecutor.php'));
        // Code only: the docblock names the ledger to say this class does not touch it.
        $source = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);

        foreach ([
            'GoogleBusinessEmailProvider', 'MicrosoftBusinessEmailProvider', 'BusinessEmailProviderRegistry',
            'BusinessEmailAccountManager', 'Http::', 'GuzzleHttp', 'BusinessEmailMessage::', 'business_email_messages',
            'DB::table', 'refresh_token', 'Crypt::',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, 'Send email must reach a mailbox only through BusinessEmailSender.');
        }

        $constructor = (new \ReflectionClass(SendEmailNodeExecutor::class))->getConstructor();
        $this->assertSame(
            [BusinessEmailSender::class],
            array_map(fn (\ReflectionParameter $parameter) => (string) $parameter->getType(), $constructor->getParameters()),
        );
    }

    public function test_the_workflow_cannot_choose_who_sends_or_from_where(): void
    {
        [$business, $contact] = $this->readyTenant();
        [$otherTenant] = [$this->emailTenant('Other Studio')];
        $foreignLocation = $this->makeLocation($otherTenant[1], 'Foreign Location');

        // A definition that carries account, address and Location keys: all ignored.
        $step = $this->emailStep();
        $step['config'] += ['location_id' => $foreignLocation->id, 'account_id' => 999, 'from' => 'attacker@evil.test', 'to' => 'attacker@evil.test'];
        [$workflow, $node] = $this->emailJourney($business, $step);

        $this->runJourney($workflow, $contact);

        $message = BusinessEmailMessage::query()->sole();
        $this->assertSame('pat@example.com', $message->to_email, 'Only the Contact\'s own address is ever used.');
        $this->assertSame('owner@business.test', $message->from_email, 'Only the Business\'s own connected mailbox sends.');
        $this->assertSame((int) $business->id, (int) DB::table('business_locations')->where('id', $message->location_id)->value('business_id'));
        $this->assertNotSame((int) $foreignLocation->id, (int) $message->location_id);
    }

    // =================================================================
    // 9. Replay
    // =================================================================

    public function test_replaying_the_exact_node_creates_one_email_operation_only(): void
    {
        [$business, $contact] = $this->readyTenant();
        [$workflow, $node] = $this->emailJourney($business);

        $enrollment = $this->runJourney($workflow, $contact);
        $stepRun = $this->stepRun($enrollment);

        $expectedKey = sprintf('automation:%d:%d:email', $workflow->id, $stepRun->id);
        $this->assertSame($expectedKey, BusinessEmailMessage::query()->sole()->operation_key, 'The key derives from the step run alone.');

        // The same node of the same journey, executed again and again.
        $executor = app(SendEmailNodeExecutor::class);

        for ($i = 0; $i < 3; $i++) {
            $outcome = $executor->execute($node, $enrollment, $business, $contact->fresh());
            $this->assertSame(StepRunStatus::Succeeded, $outcome->status, 'A replay is answered from the recorded row.');
        }

        // And the advancer re-driven over the finished journey.
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(1, BusinessEmailMessage::query()->count(), 'One logical send, one ledger row.');
        $this->assertSame(1, $this->fakeGoogle->callCount('send'), 'One provider call, however often the node replays.');
    }

    public function test_two_different_journeys_get_two_different_operations(): void
    {
        [$business, $contact] = $this->readyTenant();
        [$workflow] = $this->emailJourney($business);
        $other = $this->contactWithEmails($business, ['sam@example.com'], null, 'Sam');

        $this->runJourney($workflow, $contact);
        $this->runJourney($workflow, $other);

        $this->assertSame(2, BusinessEmailMessage::query()->distinct()->count('operation_key'));
        $this->assertSame(2, $this->fakeGoogle->callCount('send'));
    }

    // =================================================================
    // 10. Fail closed
    // =================================================================

    public function test_a_contact_with_no_usable_email_fails_closed_without_sending(): void
    {
        [$business] = $this->readyTenant();
        $noEmail = $this->contactWithEmails($business, []);
        $twoEmails = $this->contactWithEmails($business, ['a@example.com', 'b@example.com']);
        [$workflow] = $this->emailJourney($business);

        foreach ([$noEmail, $twoEmails] as $contact) {
            $enrollment = $this->runJourney($workflow, $contact);

            $this->assertSame(EnrollmentStatus::Failed, $enrollment->status);
            $this->assertSame('email_refused: recipient_invalid', $this->stepRun($enrollment)->safe_error_summary);
        }

        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        $this->assertSame(0, BusinessEmailMessage::query()->count(), 'A refusal writes no ledger row.');
    }

    public function test_a_business_with_no_connected_mailbox_fails_closed_without_sending(): void
    {
        [, $business] = $this->emailTenant();
        $business = $this->activate($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);
        [$workflow] = $this->emailJourney($business);

        $enrollment = $this->runJourney($workflow, $contact);

        $this->assertSame(EnrollmentStatus::Failed, $enrollment->status);
        $this->assertSame('email_refused: disconnected_account', $this->stepRun($enrollment)->safe_error_summary);
        $this->assertSame(0, $this->fakeGoogle->callCount('send') + $this->fakeMicrosoft->callCount('send'));
        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    public function test_an_incomplete_step_is_skipped_and_sends_nothing(): void
    {
        [$business, $contact] = $this->readyTenant();
        [$workflow, $node] = $this->emailJourney($business);

        DB::table('automation_workflow_nodes')->where('id', $node->id)->update(['config' => json_encode(['subject' => '', 'body' => 'x'])]);

        $enrollment = $this->runJourney($workflow, $contact);

        $this->assertSame('send_config_invalid', $this->stepRun($enrollment)->safe_error_summary);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_the_foundations_per_contact_cap_is_preserved(): void
    {
        [$business, $contact] = $this->readyTenant();
        config(['business_email.send.per_contact_per_hour' => 1]);

        [$first] = $this->emailJourney($business);
        $second = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [$this->emailStep('Second', 'Again'), $this->endStep()]);

        $this->runJourney($first, $contact);
        $enrollment = $this->runJourney($second, $contact);

        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame('email_refused: send_limit_exceeded', $this->stepRun($enrollment)->safe_error_summary);
    }

    // =================================================================
    // 11. Unconfirmed is never auto-retried
    // =================================================================

    public function test_an_unconfirmed_result_is_surfaced_and_never_resent(): void
    {
        [$business, $contact] = $this->readyTenant();
        [$workflow, $node] = $this->emailJourney($business);
        $this->fakeGoogle->sendScript = [new BusinessEmailProviderException(Category::TemporaryProviderFailure, 'transport', ambiguous: true)];

        $enrollment = $this->runJourney($workflow, $contact);

        $this->assertSame(Status::Unconfirmed, BusinessEmailMessage::query()->sole()->status);
        $this->assertSame('email_unconfirmed', $this->stepRun($enrollment)->safe_error_summary);
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->status, 'The journey stops for a person to look at; it does not continue as if sent.');

        // Replay the node, advance again, wait out any retry window: still one provider call.
        $this->travel(6)->hours();
        $outcome = app(SendEmailNodeExecutor::class)->execute($node, $enrollment, $business, $contact->fresh());
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(StepRunStatus::Failed, $outcome->status);
        $this->assertSame('email_unconfirmed', $outcome->safeErrorSummary);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'), 'Unconfirmed is not safe to resend.');
        $this->assertSame(1, BusinessEmailMessage::query()->count());
    }

    public function test_a_failed_provider_result_is_a_terminal_step_failure_with_its_category(): void
    {
        [$business, $contact] = $this->readyTenant();
        [$workflow, $node] = $this->emailJourney($business);
        $this->fakeGoogle->sendScript = [new BusinessEmailProviderException(Category::PermanentProviderFailure, '550')];

        $enrollment = $this->runJourney($workflow, $contact);

        $this->assertSame('email_failed: permanent_provider_failure', $this->stepRun($enrollment)->safe_error_summary);
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    // =================================================================
    // 12. Wrong Contact / Location
    // =================================================================

    public function test_a_contact_of_another_business_can_never_be_emailed(): void
    {
        [$business] = $this->readyTenant();
        [, $other] = $this->emailTenant('Other Studio');
        $this->activeAccount($other);
        $foreign = $this->contactWithEmails($other, ['victim@example.com']);
        [$workflow, $node] = $this->emailJourney($business);

        // EnrollmentService refuses the foreign contact outright...
        $this->assertNull(app(EnrollmentService::class)->enroll($workflow, $foreign, 'forged'));

        // ...and a forged contact handed straight to the executor is refused by the sender's own re-derivation.
        $enrollment = new AutomationEnrollment(['workflow_id' => $workflow->id, 'business_id' => $business->id, 'contact_id' => $foreign->id]);
        $outcome = app(SendEmailNodeExecutor::class)->execute($node, $enrollment, $business, $foreign);

        $this->assertSame(StepRunStatus::Failed, $outcome->status);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    public function test_a_contact_pinned_to_a_foreign_location_is_refused_by_the_foundations_location_rules(): void
    {
        [$business] = $this->readyTenant();
        [, $other] = $this->emailTenant('Other Studio');
        $foreignLocation = $this->makeLocation($other, 'Foreign Location');
        [$workflow] = $this->emailJourney($business);

        // A contact of THIS Business whose stored Location belongs to another Business.
        $contact = $this->contactWithEmails($business, ['pat@example.com'], (int) $foreignLocation->id);
        $enrollment = $this->runJourney($workflow, $contact);

        // The sender falls back to the Business's own single active Location; it never sends from the foreign one.
        $message = BusinessEmailMessage::query()->first();

        if ($message !== null) {
            $this->assertSame((int) $business->id, (int) DB::table('business_locations')->where('id', $message->location_id)->value('business_id'));
        }

        $this->assertNotSame((int) $foreignLocation->id, (int) ($message?->location_id ?? 0));
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
    }

    // =================================================================
    // Simulation never sends
    // =================================================================

    public function test_simulating_the_workflow_describes_the_email_but_sends_nothing(): void
    {
        [$business, $contact] = $this->readyTenant();
        [$workflow] = $this->emailJourney($business);

        $result = app(\App\Library\Automation\Workflow\WorkflowSimulator::class)->simulate($workflow->publishedVersion ?? \App\Models\AutomationWorkflowVersion::query()->find($workflow->published_version_id), $contact);

        $emailStep = collect($result['steps'])->firstWhere('type', 'send_email');
        $this->assertNotNull($emailStep);
        $this->assertTrue($emailStep['would_run'], 'The step is described as one that would run, but is not executed.');
        $this->assertSame('external', $emailStep['side_effect']);
        $this->assertStringContainsString('Nothing is sent while testing', (string) $emailStep['detail']);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }
}
