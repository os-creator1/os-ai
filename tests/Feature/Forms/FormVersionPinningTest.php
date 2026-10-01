<?php

namespace Tests\Feature\Forms;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\Forms\Exceptions\FormUnavailableException;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Forms V1 correction round 1 — every rendered flow is PINNED to the immutable
 * version the visitor saw.
 *
 * The defect this holds shut: the token authenticated only deployment + nonce, so
 * a form rendered as version N and submitted after the owner published N+1 was
 * validated and stored as N+1. Now the token binds the deployment, the immutable
 * version id and the nonce; the server re-reads that version, proves it is a
 * version of THIS deployment's Form, and validates and persists against exactly it
 * — while every CURRENT authority check still applies.
 */
class FormVersionPinningTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $location;

    private Form $form;

    private FormDeployment $deployment;

    private FormVersion $v1;

    private FormSubmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business, $this->workspace] = $this->formsTenant();
        $this->location = $this->formsLocation($this->business, 'Downtown');
        $this->formsPipeline($this->business);
        [$this->form, $this->deployment] = $this->liveForm($this->business, $this->location);
        $this->v1 = $this->form->currentVersion();
        $this->service = app(FormSubmissionService::class);
    }

    /**
     * Owner publishes version 2: "message" is RELABELLED under the same key,
     * "event_type" loses an option, and a NEW required "budget" question appears.
     */
    private function publishVersionTwo(): FormVersion
    {
        $fields = $this->form->currentVersion()->fields;
        $fields[5]['label'] = 'Anything else we should know?';          // same key, new meaning
        $fields[4]['options'] = ['Corporate', 'Birthday'];                // "Wedding" removed
        $fields[] = ['label' => 'Budget', 'type' => 'text', 'required' => true, 'page' => 'page_1']; // new required

        app(FormManager::class)->update($this->business, $this->form, $this->leadFormInput(['fields' => $fields]));

        return $this->form->fresh()->currentVersion();
    }

    private function submitWith(string $token, array $answers = [], ?FormDeployment $deployment = null)
    {
        $deployment ??= $this->deployment;

        return $this->service->submit($deployment->uid, $this->submitInput($deployment, $answers, $token));
    }

    // ------------------------------------------------------------------ pinning

    public function test_a_form_rendered_as_version_one_is_submitted_as_version_one_after_the_owner_publishes_version_two(): void
    {
        $token = FormOperationToken::issue($this->deployment, $this->v1);   // visitor sees v1
        $v2 = $this->publishVersionTwo();                                    // owner publishes v2
        $this->assertSame(2, $v2->version);

        // v1 answers: valid under v1 (no budget, "Wedding" allowed), INVALID under v2.
        $submission = $this->submitWith($token)->submission->fresh();

        $this->assertSame((int) $this->v1->id, (int) $submission->form_version_id, 'persisted against the version the visitor saw');
        $this->assertNotSame((int) $v2->id, (int) $submission->form_version_id);
        $this->assertSame('Wedding', $submission->values['event_type'], 'validated by v1: "Wedding" was still an option');
        $this->assertArrayNotHasKey('budget', $submission->values, 'v2\'s new required question is not part of a v1 flow');
        $this->assertSame(1, $submission->version->version);
    }

    public function test_the_same_answers_posted_with_a_version_two_token_are_validated_as_version_two(): void
    {
        $v2 = $this->publishVersionTwo();
        $token = FormOperationToken::issue($this->deployment, $v2);

        try {
            $this->submitWith($token);
            $this->fail('v2 requires a budget and no longer offers "Wedding"');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('budget', $exception->errors());
            $this->assertArrayHasKey('event_type', $exception->errors());
        }

        $submission = $this->submitWith($token, ['budget' => '5000', 'event_type' => 'Corporate'])->submission->fresh();
        $this->assertSame((int) $v2->id, (int) $submission->form_version_id);
    }

    public function test_a_changed_label_with_the_same_key_never_rewrites_the_historical_meaning(): void
    {
        $token = FormOperationToken::issue($this->deployment, $this->v1);
        $this->publishVersionTwo();

        $submission = $this->submitWith($token, ['message' => 'Hello'])->submission->fresh();
        $labels = collect($submission->version->fields)->pluck('label', 'key');

        $this->assertSame('Message', $labels['message'], 'the answer is still read as the question it answered');
        $this->assertSame('Hello', $submission->values['message']);

        $current = collect($this->form->fresh()->currentVersion()->fields)->pluck('label', 'key');
        $this->assertSame('Anything else we should know?', $current['message'], 'while the form now asks it differently');
    }

    public function test_a_field_removed_or_changed_in_version_two_does_not_corrupt_an_in_flight_version_one_operation(): void
    {
        $v1Token = FormOperationToken::issue($this->deployment, $this->v1);
        $this->publishVersionTwo();

        $before = FormSubmission::count();
        $submission = $this->submitWith($v1Token, ['event_type' => 'Wedding', 'email' => 'ada@example.test'])->submission->fresh();

        $this->assertSame($before + 1, FormSubmission::count());
        $this->assertSame('Wedding', $submission->values['event_type']);
        $this->assertSame('ada@example.test', $submission->values['email']);
    }

    public function test_current_version_submission_still_works_and_pins_the_current_version(): void
    {
        $v2 = $this->publishVersionTwo();

        // issue() with no version pins the form's CURRENT version.
        $token = FormOperationToken::issue($this->deployment);
        $submission = $this->submitWith($token, ['budget' => '100', 'event_type' => 'Birthday'])->submission->fresh();

        $this->assertSame((int) $v2->id, (int) $submission->form_version_id);
    }

    public function test_replay_of_a_version_one_submission_after_version_two_exists_converges_on_the_original(): void
    {
        $token = FormOperationToken::issue($this->deployment, $this->v1);
        $first = $this->submitWith($token);

        $this->publishVersionTwo();

        $second = $this->submitWith($token);
        $third = $this->submitWith($token);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertTrue($third->replayed);
        $this->assertSame($first->submission->id, $second->submission->id);
        $this->assertSame((int) $this->v1->id, (int) $second->submission->fresh()->form_version_id);
        $this->assertSame(1, FormSubmission::count());

        // ...and the hash comparison is made against the SAME pinned version: a
        // replay with different answers is still refused.
        $this->expectException(ValidationException::class);
        $this->submitWith($token, ['message' => 'tampered']);
    }

    // ------------------------------------------------------------- forgery

    public function test_a_forged_version_identity_fails(): void
    {
        $v2 = $this->publishVersionTwo();
        $token = FormOperationToken::issue($this->deployment, $this->v1);
        [$nonce, $versionId, $signature] = explode('.', $token);

        // Swap in the other version id without a matching signature.
        $forged = $nonce.'.'.$v2->id.'.'.$signature;
        // And a version id that exists nowhere.
        $invented = $nonce.'.999999.'.$signature;

        foreach ([$forged, $invented] as $bad) {
            try {
                $this->submitWith($bad);
                $this->fail('a token whose version does not match its signature must be refused');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('form', $exception->errors());
            }
        }

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_a_posted_raw_version_id_is_never_trusted(): void
    {
        $v2 = $this->publishVersionTwo();
        $token = FormOperationToken::issue($this->deployment, $v2);

        // The visitor posts "form_version_id" = v1 alongside a v2 token: ignored.
        $submission = $this->submitWith($token, [
            'form_version_id' => $this->v1->id, 'version' => 1, 'budget' => '1', 'event_type' => 'Corporate',
        ])->submission->fresh();

        $this->assertSame((int) $v2->id, (int) $submission->form_version_id, 'the token, not a posted id, decides the version');
    }

    public function test_a_version_of_another_form_or_business_fails_even_when_validly_signed(): void
    {
        // Another Form of the SAME Business.
        $other = $this->makeForm($this->business, ['name' => 'Other form'], true);
        $otherVersion = $other->currentVersion();

        // A Form of ANOTHER Business.
        [, $stranger] = $this->formsTenant(name: 'Other Studio');
        $strangerForm = $this->makeForm($stranger, ['name' => 'Stranger form'], true);

        foreach ([$otherVersion, $strangerForm->currentVersion()] as $foreignVersion) {
            // issue() signs correctly — the HMAC is genuine; the SCOPE is wrong.
            $token = FormOperationToken::issue($this->deployment, $foreignVersion);

            try {
                $this->submitWith($token);
                $this->fail('a version of another Form must be refused');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('form', $exception->errors());
            }
        }

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_a_token_pinned_at_one_deployment_is_refused_at_another(): void
    {
        $uptown = $this->formsLocation($this->business, 'Uptown');
        $second = $this->deploy($this->business, $this->form, $uptown);
        $token = FormOperationToken::issue($this->deployment, $this->v1);

        $this->expectException(ValidationException::class);
        $this->submitWith($token, [], $second);
    }

    // ------------------------------------------- pinning is not authority

    public function test_pinning_never_bypasses_current_authority(): void
    {
        $token = FormOperationToken::issue($this->deployment, $this->v1);
        $this->publishVersionTwo();

        // Inactive form.
        app(FormManager::class)->deactivate($this->business, $this->form);
        $this->assertRefused($token, 'form_not_active');
        app(FormManager::class)->activate($this->business, $this->form);

        // Disabled deployment.
        app(FormManager::class)->setDeployment($this->business, $this->form, $this->location, false);
        $this->assertRefused($token, 'deployment_disabled');
        app(FormManager::class)->setDeployment($this->business, $this->form, $this->location, true);

        // Archived Location.
        $this->location->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived, 'archived_at' => now()])->save();
        $this->assertRefused($token, 'location_archived');
        $this->location->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Active, 'archived_at' => null])->save();

        // Lost entitlement.
        $this->denyFeature($this->workspace, PlatformFeature::Forms);
        $this->assertRefused($token, 'not_entitled');

        $this->assertSame(0, FormSubmission::count());
    }

    private function assertRefused(string $token, string $reason): void
    {
        try {
            $this->submitWith($token);
            $this->fail("an old, validly signed token must still be refused ({$reason})");
        } catch (FormUnavailableException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    // ------------------------------------------------------------------ HTTP

    public function test_the_public_page_pins_what_it_renders_and_the_thank_you_is_the_pinned_versions(): void
    {
        $page = $this->get(route('public.forms.show', [$this->deployment->uid]))->assertOk();
        $page->assertSee('Message', false)->assertDontSee('Anything else we should know?');
        preg_match('/name="operation_token" value="([^"]+)"/', $page->getContent(), $match);

        // The owner publishes v2 (new label, new required question, new thank-you).
        $fields = $this->form->currentVersion()->fields;
        $fields[5]['label'] = 'Anything else we should know?';
        $fields[] = ['label' => 'Budget', 'type' => 'text', 'required' => true, 'page' => 'page_1'];
        app(FormManager::class)->update($this->business, $this->form, $this->leadFormInput([
            'fields' => $fields, 'success_message' => 'Version two thank-you.',
        ]));

        // A fresh visitor now sees v2.
        $this->get(route('public.forms.show', [$this->deployment->uid]))->assertSee('Anything else we should know?')->assertSee('Budget');

        // The ALREADY-rendered page still finishes as v1.
        $this->post(route('public.forms.submit', [$this->deployment->uid]), $this->submitInput($this->deployment, [], $match[1]));
        $submission = FormSubmission::firstOrFail();

        $this->assertSame((int) $this->v1->id, (int) $submission->form_version_id);
        $this->get(route('public.forms.thanks', ['deploymentUid' => $this->deployment->uid, 's' => $submission->uid]))
            ->assertOk()->assertSee('Thanks — we will be in touch.')->assertDontSee('Version two thank-you.');
    }

    public function test_the_thank_you_of_a_submission_belonging_to_another_deployment_is_not_shown(): void
    {
        $uptown = $this->formsLocation($this->business, 'Uptown');
        $other = $this->deploy($this->business, $this->form, $uptown);
        $submission = $this->submitWith(FormOperationToken::issue($other, $this->v1), [], $other)->submission;

        // Asked through the WRONG deployment, the named submission is ignored.
        $this->get(route('public.forms.thanks', ['deploymentUid' => $this->deployment->uid, 's' => $submission->uid]))->assertOk();
        $this->assertSame((int) $other->id, (int) $submission->form_deployment_id);
    }
}
