<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Form;
use App\Models\FormDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Workflow\CrossDomain\Support\BuildsCrossDomainFixtures;
use Tests\TestCase;

/**
 * Automations x Calendar / Forms — Send booking link, Send form, Send questionnaire.
 *
 * Each links to the owning domain's own public page, resolved from the resource when
 * the step runs; delivers through the canonical email and messaging doors; keeps the
 * journey's Location; and is at-most-once.
 */
class LinkActionsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCrossDomainFixtures;

    /** @var array<string, mixed> */
    private array $world;

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = $this->xWorld();
        $this->xTextingReady($this->world['business']);
        $this->formsPipeline($this->world['business']);
    }

    private function bookingStep(BookingType $type, array $channels = ['email'], array $extra = []): array
    {
        return $this->xNode('send_booking_link', ['booking_type_id' => $type->id, 'channels' => $channels] + $extra);
    }

    private function formStep(string $nodeType, Form $form, array $channels = ['email']): array
    {
        return $this->xNode($nodeType, ['form_id' => $form->id, 'channels' => $channels]);
    }

    private function journey(AutomationWorkflow $workflow, ?Contacts $contact = null, ?BusinessLocation $pinned = null): AutomationEnrollment
    {
        return $this->xAdvance($this->xEnroll($workflow, $contact ?? $this->world['contact'], 'k-' . uniqid(), $pinned));
    }

    private function bookingUrl(BookingType $type): string
    {
        return route('public.booking.show', [$type->public_booking_uuid]);
    }

    // =================================================================
    // Send booking link
    // =================================================================

    public function test_the_link_goes_by_email_through_business_email_and_is_the_calendars_own_page(): void
    {
        $type = $this->xBookingType($this->world['location']);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->bookingStep($type)]);

        $enrollment = $this->journey($workflow);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $sent = $this->fakeGoogle->sent[0];
        $this->assertSame('pat@example.com', $sent->toEmail);
        $this->assertSame('Book your appointment', $sent->subject);
        $this->assertStringContainsString($this->bookingUrl($type), $sent->bodyText);
        $this->assertSame('Link sent by email', $this->xStep($enrollment, 'send_booking_link')->safe_result_summary);
        $this->assertSame(1, DB::table('business_email_messages')->where('business_id', $this->world['business']->id)->count());
    }

    public function test_the_link_goes_by_text_through_the_one_messaging_door(): void
    {
        $type = $this->xBookingType($this->world['location']);
        $core = $this->captureSendCore(1);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->bookingStep($type, ['sms'], ['message' => 'Book with us:'])]);

        $enrollment = $this->journey($workflow);

        $this->assertSame(StepRunStatus::Succeeded, $this->xStep($enrollment, 'send_booking_link')->status);
        $this->assertSame(1, $core->count());
        $this->assertStringContainsString($this->bookingUrl($type), $core->lastPayload()['message']);
        $this->assertStringStartsWith('Book with us:', $core->lastPayload()['message']);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'), 'Text only: no email.');
    }

    public function test_both_channels_deliver_once_each_and_a_replay_repeats_neither(): void
    {
        $type = $this->xBookingType($this->world['location']);
        $core = $this->captureSendCore(1);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->bookingStep($type, ['email', 'sms'])]);
        $enrollment = $this->xEnroll($workflow, $this->world['contact'], 'k1');

        $this->xAdvance($enrollment);
        // The same job delivered again — the engine's claim finds the step recorded.
        $this->xAdvance($enrollment->fresh());
        app(\App\Library\Automation\Workflow\Runtime\WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame(1, $core->count());
        $this->assertSame('Link sent by email and text', $this->xStep($enrollment, 'send_booking_link')->safe_result_summary);
    }

    public function test_one_channel_failing_does_not_hide_the_one_that_delivered(): void
    {
        $type = $this->xBookingType($this->world['location']);
        $core = $this->captureSendCore(1, succeed: false);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->bookingStep($type, ['email', 'sms'])]);

        $step = $this->xStep($this->journey($workflow), 'send_booking_link');

        $this->assertSame(StepRunStatus::Succeeded, $step->status);
        $this->assertStringContainsString('Link sent by email', (string) $step->safe_result_summary);
        $this->assertStringContainsString('text: send_failed', (string) $step->safe_result_summary);
        $this->assertSame(1, $core->count());
    }

    public function test_when_nothing_delivers_the_step_fails_with_the_reasons(): void
    {
        $type = $this->xBookingType($this->world['location']);
        $this->captureSendCore(0);
        // Published while everything worked (publish refuses a mailbox-less account) …
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->bookingStep($type, ['email', 'sms'])]);
        // … then the mailbox goes away and the contact opts out before the step runs.
        DB::table('business_email_accounts')->where('business_id', $this->world['business']->id)->update(['state' => 'disconnected']);
        DB::table('contacts')->where('id', $this->world['contact']->id)->update(['status' => 'unsubscribe']);

        $step = $this->xStep($this->journey($workflow), 'send_booking_link');

        $this->assertSame(StepRunStatus::Failed, $step->status);
        $this->assertStringContainsString('email: email_refused', (string) $step->safe_error_summary);
        $this->assertStringContainsString('text: contact_unsubscribed', (string) $step->safe_error_summary);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_switched_off_or_foreign_booking_type_fails_closed(): void
    {
        $type = $this->xBookingType($this->world['location']);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->bookingStep($type)]);

        // Switched off after publish.
        DB::table('booking_types')->where('id', $type->id)->update(['is_active' => false]);
        $step = $this->xStep($this->journey($workflow), 'send_booking_link');
        $this->assertSame('booking_type_unavailable', $step->safe_error_summary);

        // Another Business's booking type forced into a pinned version.
        $other = $this->sendableTenant('Other Studio');
        $theirs = $this->xBookingType($other['location']);
        DB::table('automation_workflow_nodes')->where('version_id', $workflow->published_version_id)->where('node_type', 'send_booking_link')
            ->update(['config' => json_encode(['booking_type_id' => $theirs->id, 'channels' => ['email']])]);
        $step = $this->xStep($this->journey($workflow, $this->xContact($this->world, $this->world['location'], '14155557701')), 'send_booking_link');

        $this->assertSame('booking_type_unavailable', $step->safe_error_summary);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_journey_pinned_to_one_location_never_sends_another_locations_booking_type(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $theirs = $this->xBookingType($uptown, 'Uptown consultation');
        $mine = $this->xBookingType($this->world['location']);
        $wide = $this->xManualWorkflow($this->world['business'], [$this->bookingStep($theirs)]);

        // Business-wide, run pinned to Main, booking type at Uptown: refused at run time, nothing sent.
        $step = $this->xStep($this->journey($wide, null, $this->world['location']), 'send_booking_link');
        $this->assertSame(StepRunStatus::Skipped, $step->status);
        $this->assertSame('resource_outside_workflow_location', $step->safe_error_summary);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));

        // The same workflow run pinned to Uptown sends it.
        $this->xStep($this->journey($wide, $this->xContact($this->world, $uptown, '14155557702'), $uptown), 'send_booking_link');
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertStringContainsString($this->bookingUrl($theirs), $this->fakeGoogle->sent[0]->bodyText);

        // A Location-limited workflow cannot even PUBLISH with the other Location's booking type.
        try {
            $this->xManualWorkflow($this->world['business'], [$this->bookingStep($theirs)], ['business_location_id' => $this->world['location']->id]);
            $this->fail('Location A cannot send Location B\'s booking link.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not limited to', json_encode($exception->errors()));
        }

        $this->assertNotNull($this->xManualWorkflow($this->world['business'], [$this->bookingStep($mine)], ['business_location_id' => $this->world['location']->id])->published_version_id);
    }

    public function test_a_selected_scope_may_send_any_of_its_locations_booking_types_but_not_a_third(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $midtown = $this->xLocation($this->world['business'], 'Midtown');
        $selected = ['scope_mode' => 'selected', 'business_location_ids' => [$this->world['location']->id, $uptown->id]];

        $this->assertNotNull($this->xManualWorkflow($this->world['business'], [$this->bookingStep($this->xBookingType($uptown))], $selected)->published_version_id);

        try {
            $this->xManualWorkflow($this->world['business'], [$this->bookingStep($this->xBookingType($midtown))], $selected);
            $this->fail('A booking type outside every selected Location can never be sent.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Midtown', json_encode($exception->errors()));
        }
    }

    public function test_publish_refuses_what_the_account_cannot_run(): void
    {
        $type = $this->xBookingType($this->world['location']);

        // No Calendar entitlement.
        $this->denyFeature($this->world['workspace'], PlatformFeature::Calendar);
        try {
            $this->xManualWorkflow($this->world['business'], [$this->bookingStep($type)]);
            $this->fail('Without the calendar there is no booking-link action.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('calendar', mb_strtolower(json_encode($exception->errors())));
        }
    }

    public function test_publish_refuses_a_channel_the_account_has_no_way_to_use(): void
    {
        $type = $this->xBookingType($this->world['location']);
        $bare = $this->sendableTenant('Bare Studio');
        $bareType = $this->xBookingType($bare['location']);

        foreach ([['sms', 'phone number'], ['email', 'mailbox']] as [$channel, $word]) {
            try {
                $this->xManualWorkflow($bare['business'], [$this->bookingStep($bareType, [$channel])]);
                $this->fail("{$channel} with no way to send must not publish.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($word, json_encode($exception->errors()), $channel);
            }
        }

        $this->assertNotNull($this->xManualWorkflow($this->world['business'], [$this->bookingStep($type, ['email', 'sms'])])->published_version_id);
    }

    public function test_the_shape_is_validated_before_anything_else(): void
    {
        $type = $this->xBookingType($this->world['location']);

        foreach ([
            'no channels' => $this->xNode('send_booking_link', ['booking_type_id' => $type->id, 'channels' => []]),
            'unknown channel' => $this->xNode('send_booking_link', ['booking_type_id' => $type->id, 'channels' => ['carrier_pigeon']]),
            'no booking type' => $this->xNode('send_booking_link', ['booking_type_id' => null, 'channels' => ['email']]),
            'duplicate channel' => $this->xNode('send_booking_link', ['booking_type_id' => $type->id, 'channels' => ['email', 'email']]),
        ] as $label => $node) {
            try {
                $this->xManualWorkflow($this->world['business'], [$node]);
                $this->fail("{$label} must not publish.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    // =================================================================
    // Send form / Send questionnaire
    // =================================================================

    private function liveFormAt(BusinessLocation $location, bool $enabled = true): array
    {
        [$form, $deployment] = $this->liveForm($this->world['business'], $location);

        if (! $enabled) {
            DB::table('form_deployments')->where('id', $deployment->id)->update(['is_enabled' => false]);
        }

        return [$form, $deployment->fresh()];
    }

    public function test_a_form_is_sent_as_the_deployments_public_link_at_the_journeys_location(): void
    {
        [$form, $deployment] = $this->liveFormAt($this->world['location']);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->formStep('send_form', $form)]);

        $enrollment = $this->journey($workflow, null, $this->world['location']);

        $this->assertSame(StepRunStatus::Succeeded, $this->xStep($enrollment, 'send_form')->status);
        $this->assertStringContainsString(route('public.forms.show', [$deployment->uid]), $this->fakeGoogle->sent[0]->bodyText);
        $this->assertSame('Please fill in this form', $this->fakeGoogle->sent[0]->subject);
    }

    public function test_a_questionnaire_is_sent_as_its_deployment_link_and_never_as_a_form(): void
    {
        $questionnaire = $this->makeQuestionnaire($this->world['business']);
        $deployment = $this->deploy($this->world['business'], $questionnaire, $this->world['location']);
        [$onePage] = $this->liveFormAt($this->world['location']);

        $good = $this->xManualWorkflow($this->world['business'], [$this->formStep('send_questionnaire', $questionnaire)]);
        $this->journey($good, null, $this->world['location']);
        $this->assertStringContainsString(route('public.forms.show', [$deployment->uid]), $this->fakeGoogle->sent[0]->bodyText);
        $this->assertSame('A few questions for you', $this->fakeGoogle->sent[0]->subject);

        // The kinds cannot be swapped, at publish or at run time.
        foreach ([['send_questionnaire', $onePage], ['send_form', $questionnaire]] as [$type, $form]) {
            try {
                $this->xManualWorkflow($this->world['business'], [$this->formStep($type, $form)]);
                $this->fail("{$type} with the wrong kind must not publish.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($type === 'send_form' ? 'questionnaire' : 'one-page form', json_encode($exception->errors()));
            }
        }
    }

    public function test_a_form_that_is_off_or_has_no_link_here_fails_closed(): void
    {
        [$form] = $this->liveFormAt($this->world['location']);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->formStep('send_form', $form)]);

        // Switched off after publish.
        DB::table('forms')->where('id', $form->id)->update(['lifecycle_state' => 'inactive']);
        $this->assertSame('form_unavailable', $this->xStep($this->journey($workflow), 'send_form')->safe_error_summary);
        DB::table('forms')->where('id', $form->id)->update(['lifecycle_state' => 'active']);

        // No deployment at all.
        DB::table('form_deployments')->where('form_id', $form->id)->delete();
        $contact = $this->xContact($this->world, $this->world['location'], '14155557711');
        $this->assertSame('form_deployment_unavailable', $this->xStep($this->journey($workflow, $contact, $this->world['location']), 'send_form')->safe_error_summary);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_forms_link_at_another_location_is_not_sent_by_a_journey_pinned_elsewhere(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        [$form] = $this->liveFormAt($uptown);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->formStep('send_form', $form)]);

        $step = $this->xStep($this->journey($workflow, null, $this->world['location']), 'send_form');

        $this->assertSame(StepRunStatus::Skipped, $step->status);
        $this->assertSame('resource_outside_workflow_location', $step->safe_error_summary);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_an_unpinned_journey_uses_the_forms_one_deployment_and_refuses_to_pick_between_several(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        [$form, $deployment] = $this->liveFormAt($this->world['location']);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->formStep('send_form', $form)]);

        $this->journey($workflow);
        $this->assertStringContainsString($deployment->uid, $this->fakeGoogle->sent[0]->bodyText);

        // A second Location now offers it too: an unpinned journey will not choose.
        $this->deploy($this->world['business'], $form, $uptown);
        $step = $this->xStep($this->journey($workflow, $this->xContact($this->world, null, '14155557712')), 'send_form');
        $this->assertSame('form_deployment_unavailable', $step->safe_error_summary);
    }

    public function test_another_businesss_form_cannot_be_published_or_run(): void
    {
        $other = $this->sendableTenant('Other Studio');
        [$theirForm] = $this->liveForm($other['business'], $other['location']);

        try {
            $this->xManualWorkflow($this->world['business'], [$this->formStep('send_form', $theirForm)]);
            $this->fail('A foreign form must not publish.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('does not belong to this business', json_encode($exception->errors()));
        }

        [$mine] = $this->liveFormAt($this->world['location']);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->formStep('send_form', $mine)]);
        DB::table('automation_workflow_nodes')->where('version_id', $workflow->published_version_id)->where('node_type', 'send_form')
            ->update(['config' => json_encode(['form_id' => $theirForm->id, 'channels' => ['email']])]);

        $this->assertSame('form_unavailable', $this->xStep($this->journey($workflow), 'send_form')->safe_error_summary);
    }

    public function test_the_forms_entitlement_is_required(): void
    {
        [$form] = $this->liveFormAt($this->world['location']);
        $this->denyFeature($this->world['workspace'], PlatformFeature::Forms);

        try {
            $this->xManualWorkflow($this->world['business'], [$this->formStep('send_form', $form)]);
            $this->fail('Without forms there is no send-form action.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('forms', mb_strtolower(json_encode($exception->errors())));
        }
    }

    public function test_a_contact_who_left_a_limited_journeys_location_is_never_sent_a_link(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $type = $this->xBookingType($this->world['location']);
        $workflow = $this->xManualWorkflow($this->world['business'], [$this->bookingStep($type)], ['business_location_id' => $this->world['location']->id]);
        $enrollment = $this->xEnroll($workflow, $this->world['contact'], 'k', $this->world['location']);
        DB::table('contacts')->where('id', $this->world['contact']->id)->update(['location_id' => $uptown->id]);

        $step = $this->xStep($this->xAdvance($enrollment), 'send_booking_link');

        $this->assertSame('contact_outside_workflow_location', $step->safe_error_summary);
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }
}
