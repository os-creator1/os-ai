<?php

namespace Tests\Feature\CustomFields;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\User;
use App\Notifications\WorkflowInternalNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * Automations x the canonical merge engine: what a customer actually receives.
 *
 * Includes the regression for the dead builder chips: the V2 builder used to
 * insert lowercase `{first_name}` tokens that nothing resolved at run time, so a
 * delivered message could carry raw braces.
 */
class MergeFieldAutomationEmailTest extends TestCase
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

    /** @return array{0: Business, 1: Contacts} */
    private function readyTenant(): array
    {
        [, $business] = $this->emailTenant();
        $business = $this->activate($business);
        $this->activeAccount($business);

        return [$business, $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat')];
    }

    private function sendThrough(Business $business, Contacts $contact, string $subject, string $body): AutomationEnrollment
    {
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [$this->emailStep($subject, $body), $this->endStep()]);

        return $this->enrollAndRun($workflow, $contact);
    }

    private function enrollAndRun(AutomationWorkflow $workflow, Contacts $contact): AutomationEnrollment
    {
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'manual-' . Str::uuid());
        $this->assertNotNull($enrollment);
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $enrollment->fresh();
    }

    private function sentMail(): object
    {
        $this->assertSame(1, $this->fakeGoogle->callCount('send'), 'Exactly one email should have been delivered.');

        return $this->fakeGoogle->sent[0];
    }

    private function eventDate(Business $business, Contacts $contact, string $date = '2027-06-14'): void
    {
        $field = app(CustomFieldDefinitionManager::class)->create($business, 'Event Date', 'date');
        app(CustomFieldValueService::class)->set($business, $contact, $field, $date);
    }

    public function test_the_canonical_chips_resolve_in_a_delivered_email_with_no_raw_token(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->eventDate($business, $contact);

        $enrollment = $this->sendThrough($business, $contact, 'Hi {{contact.first_name}}', "Hi {{contact.first_name}},\n\nYour event is {{contact.event_date}} — {{business.name}}");

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $mail = $this->sentMail();
        $this->assertSame('Hi Pat', $mail->subject);
        $this->assertStringContainsString('Hi Pat,', $mail->bodyText);
        $this->assertStringContainsString('Your event is 14 Jun 2027', $mail->bodyText);
        $this->assertStringContainsString($business->name, $mail->bodyText);
        $this->assertStringNotContainsString('{{', $mail->subject . $mail->bodyText);
        $this->assertStringNotContainsString('}}', $mail->subject . $mail->bodyText);
    }

    /** The dead-chip regression: every token the builder's Insert-field picker offers must resolve. */
    public function test_every_token_the_builder_picker_offers_resolves_and_none_reaches_the_customer_raw(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->eventDate($business, $contact);

        $html = $this->builderPickerHtml($business);
        preg_match_all('/data-merge-insert="([^"]+)"/', $html, $matches);
        $tokens = array_values(array_unique($matches[1]));

        $this->assertContains('{{contact.first_name}}', $tokens);
        $this->assertContains('{{contact.event_date}}', $tokens);
        $this->assertContains('{{business.name}}', $tokens);

        $this->sendThrough($business, $contact, 'Hello {{contact.first_name}}', implode(' | ', $tokens));

        $mail = $this->sentMail();
        $this->assertStringNotContainsString('{{', $mail->bodyText, 'A picker token must never be delivered as raw syntax.');
        $this->assertStringContainsString('Pat', $mail->bodyText);
    }

    private function builderPickerHtml(Business $business): string
    {
        $picker = app(\App\Library\Merge\MergeFieldRegistry::class)->picker(
            $business,
            ['contact', 'custom', 'business', 'location'],
            ['opportunity', 'appointment'],
        );

        return view('components.merge-field-picker', ['picker' => $picker, 'target' => '[data-field="body"]', 'label' => 'Insert field'])->render();
    }

    public function test_legacy_uppercase_tags_still_resolve_for_existing_workflows(): void
    {
        [$business, $contact] = $this->readyTenant();

        $this->sendThrough($business, $contact, 'Welcome {FIRST_NAME}', 'Dear {FIRST_NAME}, see {NOT_A_TAG}.');

        $mail = $this->sentMail();
        $this->assertSame('Welcome Pat', $mail->subject);
        $this->assertStringContainsString('Dear Pat,', $mail->bodyText);
        $this->assertStringContainsString('{NOT_A_TAG}', $mail->bodyText, 'Legacy behaviour: an unknown {TAG} is left as written.');
    }

    public function test_the_old_lowercase_builder_chips_in_saved_workflows_now_resolve(): void
    {
        [$business, $contact] = $this->readyTenant();

        $this->sendThrough($business, $contact, 'Hello {first_name}', '{first_name} at {business_name}; {{first_name}} stays unknown');

        $mail = $this->sentMail();
        $this->assertSame('Hello Pat', $mail->subject);
        $this->assertStringContainsString('Pat at ' . $business->name, $mail->bodyText);
        $this->assertStringNotContainsString('{{', $mail->bodyText, 'The undocumented {{first_name}} alias is blank, never leaked raw.');
    }

    public function test_a_missing_value_renders_blank_and_a_wholly_empty_message_is_skipped(): void
    {
        [$business, $contact] = $this->readyTenant();
        app(CustomFieldDefinitionManager::class)->create($business, 'Event Date', 'date');

        $this->sendThrough($business, $contact, 'Hi {{contact.first_name}}', 'Date: [{{contact.event_date}}] [{{contact.bogus}}]');

        $mail = $this->sentMail();
        $this->assertStringContainsString('Date: [] []', $mail->bodyText);

        $enrollment = $this->sendThrough($business, $contact, '{{contact.event_date}}', '{{contact.event_date}}');
        $stepRun = AutomationStepRun::query()->where('enrollment_id', $enrollment->id)->where('node_type', 'send_email')->sole();

        $this->assertSame(1, $this->fakeGoogle->callCount('send'), 'No second email: the all-blank message was skipped.');
        $this->assertSame(StepRunStatus::Skipped, $stepRun->status);
        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status, 'The journey continues.');
    }

    public function test_merge_values_never_cross_businesses(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->eventDate($business, $contact);

        $other = $this->activate($this->emailTenant('Other Studio')[1]);
        $this->activeAccount($other, mailbox: 'owner@other.test');
        $otherContact = $this->contactWithEmails($other, ['sam@example.com'], null, 'Sam');

        // Business B's workflow cannot see Business A's field key: blank, not A's value.
        $this->sendThrough($other, $otherContact, 'Hi {{contact.first_name}}', 'Date [{{contact.event_date}}] from {{business.name}}');

        $mail = $this->sentMail();
        $this->assertStringContainsString('Date []', $mail->bodyText);
        $this->assertStringContainsString('Other Studio', $mail->bodyText);
        $this->assertStringNotContainsString('14 Jun', $mail->bodyText);
    }

    public function test_the_adapter_delegates_to_the_one_engine(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->eventDate($business, $contact);

        $text = '{FIRST_NAME} / {{contact.first_name}} / {{contact.event_date}}';

        $this->assertSame(
            'Pat / Pat / 14 Jun 2027',
            ContactMergeFields::render($text, $contact->fresh()),
        );
    }

    public function test_test_workflow_previews_the_rendered_message_sends_nothing_and_flags_unknown_fields(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->eventDate($business, $contact);
        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [
            $this->emailStep('Hi {{contact.first_name}}', 'Your event is {{contact.event_date}} {{contact.zzz}}'),
            $this->endStep(),
        ]);

        $result = app(\App\Library\Automation\Workflow\WorkflowSimulator::class)
            ->simulate(\App\Models\AutomationWorkflowVersion::query()->find($workflow->published_version_id), $contact);
        $detail = (string) collect($result['steps'])->firstWhere('type', 'send_email')['detail'];

        $this->assertStringContainsString('Nothing is sent while testing', $detail);
        $this->assertStringContainsString('Subject: “Hi Pat”', $detail);
        $this->assertStringContainsString('Message: “Your event is 14 Jun 2027', $detail);
        $this->assertStringContainsString('Unknown merge field {{contact.zzz}} will be left blank', $detail);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_internal_notifications_merge_too(): void
    {
        [$business, $contact] = $this->readyTenant();
        $this->eventDate($business, $contact);
        Notification::fake();

        $workflow = $this->triggerWorkflow($business, WorkflowTriggerType::ManualEnrollment, [], [
            ['key' => (string) Str::uuid(), 'type' => 'internal_notification', 'config' => ['message' => '{{contact.first_name}} booked {{contact.event_date}}']],
            $this->endStep(),
        ]);
        $this->enrollAndRun($workflow, $contact);

        Notification::assertSentTo(
            User::find((int) $business->customer_id),
            WorkflowInternalNotification::class,
            fn (WorkflowInternalNotification $notification): bool => $notification->message === 'Pat booked 14 Jun 2027',
        );
    }
}
