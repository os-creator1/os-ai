<?php

namespace Tests\Feature\Forms;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Forms\FormContactResolution;
use App\Events\Forms\FormSubmissionRecorded;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Library\Forms\Exceptions\FormUnavailableException;
use App\Library\Forms\FormDefinitionNormalizer;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormSession;
use App\Models\FormSubmission;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Forms V1 correction round 1 — the multi-page questionnaire (Blueprint §16).
 *
 * One definition model: an ordinary form is one page, a questionnaire is two or
 * more ordered pages. Moving between pages creates no Contact, Opportunity, event
 * or submission; the server holds the answers in a session pinned to ONE immutable
 * version; the final step validates the complete pinned definition and finishes
 * through the same idempotent claim as a one-page form.
 */
class FormQuestionnaireFlowTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private Form $form;

    private FormDeployment $deployment;

    private FormSubmissionService $service;

    /** @var list<FormSubmissionRecorded> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->formsPipeline($this->business);
        $this->form = $this->makeQuestionnaire($this->business);
        $this->deployment = $this->deploy($this->business, $this->form, $this->downtown);
        $this->service = app(FormSubmissionService::class);

        Event::listen(FormSubmissionRecorded::class, function (FormSubmissionRecorded $event): void {
            $this->events[] = $event;
        });
    }

    private function step(string $token, string $page, ?array $answers = null, ?FormDeployment $deployment = null)
    {
        $deployment ??= $this->deployment;

        return $this->service->submit($deployment->uid, $this->stepInput($token, $page, $answers));
    }

    /** All three pages with the standard answers; returns the final result. */
    private function walk(string $token, ?FormDeployment $deployment = null)
    {
        $this->step($token, 'page_1', null, $deployment);
        $this->step($token, 'page_2', null, $deployment);

        return $this->step($token, 'page_3', null, $deployment);
    }

    private function counts(): array
    {
        return [
            'submissions' => FormSubmission::count(),
            'contacts' => Contacts::count(),
            'opportunities' => CrmOpportunity::count(),
            'events' => count($this->events),
        ];
    }

    // ------------------------------------------------------------ definition

    public function test_a_questionnaire_and_an_ordinary_form_are_one_definition_model(): void
    {
        $version = $this->form->currentVersion();

        $this->assertTrue($version->isMultiPage());
        $this->assertSame(['page_1', 'page_2', 'page_3'], $version->pageKeys());
        $this->assertSame(['About you', 'Your event', 'Details'], array_column($version->pages(), 'title'));
        $this->assertSame(['your_name', 'phone'], array_column($version->fieldsOnPage('page_1'), 'key'));
        $this->assertSame(['event_date', 'event_type'], array_column($version->fieldsOnPage('page_2'), 'key'));
        $this->assertSame(['message', 'i_agree'], array_column($version->fieldsOnPage('page_3'), 'key'));

        // An ordinary form is the same shape with exactly one page.
        $ordinary = $this->makeForm($this->business, ['name' => 'Ordinary'])->currentVersion();
        $this->assertFalse($ordinary->isMultiPage());
        $this->assertSame(['page_1'], $ordinary->pageKeys());
        $this->assertCount(6, $ordinary->fieldsOnPage('page_1'));
        $this->assertSame(['page_1'], array_unique(array_column($ordinary->fields, 'page')));
    }

    public function test_a_version_that_predates_pages_reads_as_one_implicit_page(): void
    {
        $version = $this->form->currentVersion();
        // A genuine pre-pages row: no page structure, and no `page` on any field.
        DB::table('form_versions')->where('id', $version->id)->update([
            'pages' => null,
            'fields' => json_encode(array_map(fn (array $field) => array_diff_key($field, ['page' => 1]), $version->fields)),
        ]);
        $legacy = $version->fresh();

        $this->assertNull($legacy->pages);
        $this->assertSame(['page_1'], $legacy->pageKeys());
        $this->assertFalse($legacy->isMultiPage());
        $this->assertCount(6, $legacy->fieldsOnPage('page_1'), 'every field is on the one implicit page');
    }

    public function test_the_definition_is_bounded_in_pages_and_fields(): void
    {
        $pages = fn (int $n) => array_map(fn ($i) => ['key' => 'page_'.$i, 'title' => 'P'.$i, 'position' => $i], range(1, $n));
        $field = fn (string $label, string $page) => ['label' => $label, 'type' => 'text', 'page' => $page];

        $tooManyPages = array_map(fn ($i) => $field('Q'.$i, 'page_'.$i), range(1, FormDefinitionNormalizer::MAX_PAGES + 1));
        $tooManyOnAPage = array_map(fn ($i) => $field('Q'.$i, 'page_1'), range(1, FormDefinitionNormalizer::MAX_FIELDS_PER_PAGE + 1));
        $tooManyInTotal = [];
        foreach (range(1, 3) as $p) {
            foreach (range(1, FormDefinitionNormalizer::MAX_FIELDS_PER_PAGE) as $i) {
                $tooManyInTotal[] = $field("Q{$p}_{$i}", 'page_'.$p);
            }
        }

        $cases = [
            'too many pages' => ['pages' => $pages(FormDefinitionNormalizer::MAX_PAGES + 1), 'fields' => $tooManyPages],
            'too many fields on one page' => ['pages' => $pages(1), 'fields' => $tooManyOnAPage],
            'too many fields in total' => ['pages' => $pages(3), 'fields' => $tooManyInTotal],
            'a field on a page that does not exist' => ['pages' => $pages(1), 'fields' => [$field('Q', 'page_9')]],
            'duplicate page key' => ['pages' => [['key' => 'page_1'], ['key' => 'page_1']], 'fields' => [$field('Q', 'page_1')]],
            'invalid page key' => ['pages' => [['key' => 'Bad Key']], 'fields' => [$field('Q', 'Bad Key')]],
            'page title too long' => ['pages' => [['key' => 'page_1', 'title' => str_repeat('x', 121)]], 'fields' => [$field('Q', 'page_1')]],
            'two phones across pages' => ['pages' => $pages(2), 'fields' => [['label' => 'A', 'type' => 'phone', 'page' => 'page_1'], ['label' => 'B', 'type' => 'phone', 'page' => 'page_2']]],
            'two name fields across pages' => ['pages' => $pages(2), 'fields' => [['label' => 'A', 'type' => 'text', 'contact_name' => true, 'page' => 'page_1'], ['label' => 'B', 'type' => 'text', 'contact_name' => true, 'page' => 'page_2']]],
            'a field keyed "page"' => ['pages' => $pages(1), 'fields' => [['key' => 'page', 'label' => 'A', 'type' => 'text', 'page' => 'page_1']]],
        ];

        foreach ($cases as $label => $override) {
            try {
                app(FormManager::class)->create($this->business, $this->leadFormInput($override + ['create_opportunity' => false]));
                $this->fail("Expected a refusal: {$label}");
            } catch (FormRuleException) {
                $this->assertTrue(true);
            }
        }

        // The boundaries themselves are accepted: MAX_PAGES pages, MAX_FIELDS_PER_PAGE on one page.
        $eight = app(FormManager::class)->create($this->business, $this->leadFormInput([
            'pages' => $pages(FormDefinitionNormalizer::MAX_PAGES),
            'fields' => array_map(fn ($i) => $field('Q'.$i, 'page_'.$i), range(1, FormDefinitionNormalizer::MAX_PAGES)),
        ]));
        $this->assertCount(FormDefinitionNormalizer::MAX_PAGES, $eight->currentVersion()->pages());
    }

    public function test_a_page_with_no_questions_is_dropped_and_pages_follow_their_position(): void
    {
        $form = app(FormManager::class)->create($this->business, $this->leadFormInput([
            'pages' => [
                ['key' => 'page_1', 'title' => 'One', 'position' => 3],
                ['key' => 'page_2', 'title' => 'Empty', 'position' => 2],
                ['key' => 'page_3', 'title' => 'Three', 'position' => 1],
            ],
            'fields' => [
                ['label' => 'Alpha', 'type' => 'text', 'page' => 'page_1'],
                ['label' => 'Gamma', 'type' => 'text', 'page' => 'page_3'],
            ],
        ]));
        $version = $form->currentVersion();

        $this->assertSame(['page_3', 'page_1'], $version->pageKeys(), 'the empty page is gone and the rest follow position');
        $this->assertSame(['gamma', 'alpha'], array_column($version->fields, 'key'), 'fields follow their page order');
    }

    public function test_reordering_pages_is_a_new_version_that_keeps_every_field_key(): void
    {
        $before = $this->form->currentVersion();
        $keysBefore = collect($before->fields)->pluck('page', 'key')->all();

        $pages = $before->pages();
        foreach ($pages as $i => &$page) {
            $page['position'] = 3 - $i;           // reverse the order
        }
        unset($page);

        app(FormManager::class)->update($this->business, $this->form, $this->questionnaireInput([
            'pages' => $pages,
            'fields' => $before->fields,
        ]));
        $after = $this->form->fresh()->currentVersion();

        $this->assertSame(2, $after->version);
        $this->assertSame(['page_3', 'page_2', 'page_1'], $after->pageKeys());
        $this->assertEquals($keysBefore, collect($after->fields)->pluck('page', 'key')->all(), 'every field keeps its key and its page');
        $this->assertSame(['page_1', 'page_2', 'page_3'], $before->fresh()->pageKeys(), 'the old version still has the old order');

        // Saving the same thing again writes nothing.
        app(FormManager::class)->update($this->business, $this->form, $this->questionnaireInput(['pages' => $after->pages(), 'fields' => $after->fields]));
        $this->assertSame(2, $this->form->fresh()->current_version);
    }

    // ------------------------------------------------------------------ flow

    public function test_a_questionnaire_walks_next_next_finalize_with_one_of_everything_at_the_end(): void
    {
        $token = FormOperationToken::issue($this->deployment);

        $one = $this->step($token, 'page_1');
        $two = $this->step($token, 'page_2');

        $this->assertFalse($one->isFinal());
        $this->assertSame('page_2', $one->nextPage);
        $this->assertSame('page_3', $two->nextPage);

        $final = $this->step($token, 'page_3');

        $this->assertTrue($final->isFinal());
        $this->assertFalse($final->replayed);
        $submission = $final->submission->fresh();
        $this->assertEquals([
            'your_name' => 'Ada Lovelace', 'phone' => '14155551234', 'event_date' => '2027-06-01',
            'event_type' => 'Wedding', 'message' => 'Quote please', 'i_agree' => true,
        ], $submission->values);
        $this->assertSame(['submissions' => 1, 'contacts' => 1, 'opportunities' => 1, 'events' => 1], $this->counts());
        $this->assertSame(FormContactResolution::Created, $submission->contact_resolution);
        $this->assertSame((int) $this->downtown->id, (int) $submission->business_location_id);
        $this->assertSame((int) $this->form->currentVersion()->id, (int) $submission->form_version_id);
    }

    public function test_no_final_side_effect_happens_before_the_final_step(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $zero = ['submissions' => 0, 'contacts' => 0, 'opportunities' => 0, 'events' => 0];

        $this->step($token, 'page_1');
        $this->assertSame($zero, $this->counts(), 'after page 1');
        $this->step($token, 'page_2');
        $this->assertSame($zero, $this->counts(), 'after page 2');

        // The only thing that exists is the server-side, in-progress session.
        $session = FormSession::firstOrFail();
        $this->assertNull($session->form_submission_id);
        $this->assertNull($session->finalized_at);
        $this->assertSame(['page_1', 'page_2'], $session->completed_pages);
        $this->assertEquals('14155551234', $session->answers['phone']);

        $this->step($token, 'page_3');
        $this->assertSame(['submissions' => 1, 'contacts' => 1, 'opportunities' => 1, 'events' => 1], $this->counts());
    }

    public function test_a_required_field_on_a_later_page_is_enforced_and_leaves_no_final_state(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $this->step($token, 'page_1');
        $this->step($token, 'page_2');

        foreach ([['message' => '', 'i_agree' => '1'], ['message' => 'ok'], ['message' => 'ok', 'i_agree' => '0']] as $bad) {
            try {
                $this->step($token, 'page_3', $bad);
                $this->fail('a missing required answer on the last page must be refused');
            } catch (ValidationException $exception) {
                $this->assertTrue($exception->errors() !== []);
            }
        }

        $this->assertSame(0, FormSubmission::count());
        $this->assertNotContains('page_3', FormSession::firstOrFail()->completed_pages);

        // Correcting it finishes on the same token.
        $this->assertTrue($this->step($token, 'page_3')->isFinal());
    }

    public function test_page_order_is_enforced_and_a_stale_or_invented_page_fails_closed(): void
    {
        $token = FormOperationToken::issue($this->deployment);

        foreach (['page_2', 'page_3'] as $skipped) {
            try {
                $this->step($token, $skipped);
                $this->fail("starting at {$skipped} must be refused");
            } catch (ValidationException) {
                $this->assertSame(0, FormSession::count(), 'a refused start creates no session');
            }
        }

        foreach (['page_9', 'garbage', '', 'PAGE_1'] as $invented) {
            try {
                $this->step($token, $invented, []);
                $this->fail("an invented page '{$invented}' must be refused");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('page', $exception->errors());
            }
        }

        $this->step($token, 'page_1');
        try {
            $this->step($token, 'page_3');   // skipping page_2
            $this->fail('skipping a page must be refused');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('page', $exception->errors());
        }

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_going_back_and_resubmitting_an_earlier_page_updates_the_answers_used_at_the_end(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $this->step($token, 'page_1');
        $this->step($token, 'page_2');

        // Back to page 1 with a corrected name, then forward again.
        $this->step($token, 'page_1', ['your_name' => 'Ada Byron', 'phone' => '+1 (415) 555-1234']);
        $submission = $this->step($token, 'page_3')->submission->fresh();

        $this->assertSame('Ada Byron', $submission->values['your_name']);
        $this->assertSame('Wedding', $submission->values['event_type'], 'pages not revisited keep their answers');
    }

    public function test_unknown_posted_keys_are_never_stored_in_the_session_or_the_submission(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $this->step($token, 'page_1', $this->questionnaireAnswers()['page_1'] + ['business_id' => 99, 'event_date' => '2030-01-01', 'is_admin' => 1]);

        $answers = FormSession::firstOrFail()->answers;
        ksort($answers);
        $this->assertSame(['phone', 'your_name'], array_keys($answers), 'only the keys of THIS page are recorded');
    }

    // -------------------------------------------------------------- pinning

    public function test_an_owner_edit_midway_never_changes_the_version_of_a_flow_in_progress(): void
    {
        $v1 = $this->form->currentVersion();
        $token = FormOperationToken::issue($this->deployment, $v1);
        $this->step($token, 'page_1');

        // The owner publishes version 2: reordered pages, a new required question,
        // a relabelled one and "Wedding" removed.
        $fields = $v1->fields;
        $fields[5]['label'] = 'Anything else?';
        $fields[3]['options'] = ['Corporate', 'Birthday'];
        $fields[] = ['label' => 'Budget', 'type' => 'text', 'required' => true, 'page' => 'page_2'];
        app(FormManager::class)->update($this->business, $this->form, $this->questionnaireInput(['pages' => $v1->pages(), 'fields' => $fields]));
        $this->assertSame(2, $this->form->fresh()->current_version);

        // The visitor carries on and finishes against version 1.
        $this->step($token, 'page_2');
        $submission = $this->step($token, 'page_3')->submission->fresh();

        $this->assertSame((int) $v1->id, (int) $submission->form_version_id);
        $this->assertSame('Wedding', $submission->values['event_type']);
        $this->assertArrayNotHasKey('budget', $submission->values);
        $this->assertSame((int) $v1->id, (int) FormSession::firstOrFail()->form_version_id);

        // A visitor who STARTS now is on version 2 and must answer its new question.
        $v2Token = FormOperationToken::issue($this->deployment);
        $this->step($v2Token, 'page_1');
        $this->expectException(ValidationException::class);
        $this->step($v2Token, 'page_2');   // missing the required budget
    }

    // ------------------------------------------------------------ idempotency

    public function test_a_replayed_final_step_converges_and_a_tampered_one_is_refused(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $first = $this->walk($token);
        $afterFirst = $this->counts();

        $second = $this->step($token, 'page_3');
        $third = $this->step($token, 'page_3');

        $this->assertTrue($second->replayed);
        $this->assertTrue($third->replayed);
        $this->assertSame($first->submission->id, $second->submission->id);
        $this->assertSame($afterFirst, $this->counts(), 'a replay creates and emits nothing');
        $this->assertSame(['submissions' => 1, 'contacts' => 1, 'opportunities' => 1, 'events' => 1], $afterFirst);

        try {
            $this->step($token, 'page_3', ['message' => 'tampered after the fact', 'i_agree' => '1']);
            $this->fail('a replay with different final answers must be refused');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        $this->assertSame('Quote please', $first->submission->fresh()->values['message']);
    }

    public function test_the_finalized_session_is_stamped_and_takes_no_further_answers(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $submission = $this->walk($token)->submission;
        $session = FormSession::firstOrFail();

        $this->assertSame((int) $submission->id, (int) $session->form_submission_id);
        $this->assertNotNull($session->finalized_at);
        $answers = $session->answers;

        // A late page-1 post does not mutate a finalized session.
        $this->step($token, 'page_1', ['your_name' => 'Someone Else', 'phone' => '+1 (415) 555-9999']);
        $this->assertEquals($answers, FormSession::firstOrFail()->answers);
    }

    public function test_two_separately_started_questionnaires_stay_separate_even_with_identical_answers(): void
    {
        $a = $this->walk(FormOperationToken::issue($this->deployment));
        $b = $this->walk(FormOperationToken::issue($this->deployment));

        $this->assertNotSame($a->submission->id, $b->submission->id);
        $this->assertSame(['submissions' => 2, 'contacts' => 1, 'opportunities' => 2, 'events' => 2], $this->counts());
        $this->assertSame(2, FormSession::count());
        $this->assertSame(FormContactResolution::Created, $a->submission->fresh()->contact_resolution);
        $this->assertSame(FormContactResolution::Matched, $b->submission->fresh()->contact_resolution);
    }

    public function test_the_finalized_session_holds_exactly_the_answers_of_its_submission(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $submission = $this->walk($token)->submission->fresh();
        $session = FormSession::firstOrFail();

        $this->assertSame((int) $submission->id, (int) $session->form_submission_id);
        $this->assertNotNull($session->finalized_at);
        $this->assertEquals($submission->values, $session->answers, 'the session is a faithful copy of the submission it produced');
        $this->assertSame(['page_1', 'page_2', 'page_3'], $session->completed_pages);
    }

    public function test_a_finalized_session_cannot_be_changed_through_the_model_or_the_store(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $this->walk($token);
        $session = FormSession::firstOrFail();
        $before = (array) DB::table('form_sessions')->where('id', $session->id)->first();

        foreach ([
            fn () => $session->forceFill(['answers' => ['your_name' => 'Tampered']])->save(),
            fn () => $session->update(['expires_at' => now()->addYear()]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a finalized session must refuse every Eloquent update');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }

        // The store's page save and a replayed final step leave it byte-identical.
        $this->step($token, 'page_1', ['your_name' => 'Late Edit', 'phone' => '+1 (415) 555-1234']);
        $this->step($token, 'page_2', ['event_date' => '2030-01-01', 'event_type' => 'Corporate']);
        $this->assertTrue($this->step($token, 'page_3')->replayed);

        $this->assertEquals($before, (array) DB::table('form_sessions')->where('id', $session->id)->first());
        $this->assertSame('Ada Lovelace', FormSubmission::firstOrFail()->values['your_name']);
    }

    public function test_a_final_step_with_different_answers_after_the_session_is_final_is_the_existing_conflict(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $first = $this->walk($token)->submission;
        $before = $this->counts();

        try {
            $this->step($token, 'page_3', ['message' => 'A different final answer', 'i_agree' => '1']);
            $this->fail('different final answers on a finalized session must conflict');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame('Quote please', $first->fresh()->values['message']);
        $this->assertSame('Quote please', FormSession::firstOrFail()->answers['message']);
    }

    public function test_the_event_carries_the_final_submission_once_and_no_event_precedes_it(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $this->step($token, 'page_1');
        $this->step($token, 'page_2');
        $this->assertCount(0, $this->events);

        $submission = $this->step($token, 'page_3')->submission->fresh();

        $this->assertCount(1, $this->events);
        $event = $this->events[0];
        $this->assertSame((int) $submission->id, $event->submissionId);
        $this->assertSame((int) $submission->form_version_id, $event->formVersionId);
        $this->assertSame('form_submission:'.$submission->uid, $event->occurrenceKey);
        $this->assertSame('created', $event->contactResolution);
    }

    public function test_an_ordinary_one_page_form_is_unchanged_and_creates_no_session(): void
    {
        [, $deployment] = $this->liveForm($this->business, $this->uptown, ['name' => 'Ordinary']);

        $submission = $this->service->submit($deployment->uid, $this->submitInput($deployment))->submission->fresh();

        $this->assertSame((int) $this->uptown->id, (int) $submission->business_location_id);
        $this->assertSame(0, FormSession::count(), 'a one-page form needs no in-progress state');
        $this->assertCount(1, $this->events);
    }

    // ------------------------------------------------------------- authority

    public function test_every_step_re_checks_current_authority(): void
    {
        $manager = app(FormManager::class);
        $token = FormOperationToken::issue($this->deployment);
        $this->step($token, 'page_1');

        // Switched-off form mid-flow: the NEXT step and the final step refuse.
        $manager->deactivate($this->business, $this->form);
        $this->assertStepRefused($token, 'page_2', 'form_not_active');
        $manager->activate($this->business, $this->form);

        // Disabled deployment.
        $manager->setDeployment($this->business, $this->form, $this->downtown, false);
        $this->assertStepRefused($token, 'page_2', 'deployment_disabled');
        $manager->setDeployment($this->business, $this->form, $this->downtown, true);

        // Archived Location.
        $this->downtown->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived, 'archived_at' => now()])->save();
        $this->assertStepRefused($token, 'page_2', 'location_archived');
        $this->downtown->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Active, 'archived_at' => null])->save();

        // Entitlement lost mid-flow — even at the FINAL boundary.
        $this->step($token, 'page_2');
        $this->denyFeature($this->workspace, PlatformFeature::Forms);
        $this->assertStepRefused($token, 'page_3', 'not_entitled');

        $this->assertSame(0, FormSubmission::count());
        $this->assertSame(['submissions' => 0, 'contacts' => 0, 'opportunities' => 0, 'events' => 0], $this->counts());
    }

    private function assertStepRefused(string $token, string $page, string $reason): void
    {
        try {
            $this->step($token, $page);
            $this->fail("expected the step to be refused ({$reason})");
        } catch (FormUnavailableException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    public function test_a_session_cannot_be_used_at_a_foreign_or_sibling_deployment(): void
    {
        $uptownDeployment = $this->deploy($this->business, $this->form, $this->uptown);
        [, $stranger] = $this->formsTenant(name: 'Other Studio');
        $strangerLocation = $this->formsLocation($stranger, 'Elsewhere');
        $strangerForm = $this->makeQuestionnaire($stranger);
        $strangerDeployment = $this->deploy($stranger, $strangerForm, $strangerLocation);

        $token = FormOperationToken::issue($this->deployment);
        $this->step($token, 'page_1');

        foreach ([$uptownDeployment, $strangerDeployment] as $elsewhere) {
            try {
                $this->step($token, 'page_2', null, $elsewhere);
                $this->fail('a token issued for one deployment must not progress another');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('form', $exception->errors());
            }
        }

        $this->assertSame(1, FormSession::count(), 'only the genuine session exists');
    }

    public function test_business_and_location_isolation_across_deployments_of_one_form(): void
    {
        $uptownDeployment = $this->deploy($this->business, $this->form, $this->uptown);

        $downtownResult = $this->walk(FormOperationToken::issue($this->deployment));
        $uptownResult = $this->walk(FormOperationToken::issue($uptownDeployment), $uptownDeployment);

        $this->assertSame((int) $this->downtown->id, (int) $downtownResult->submission->fresh()->business_location_id);
        $this->assertSame((int) $this->uptown->id, (int) $uptownResult->submission->fresh()->business_location_id);

        // The same person at two Locations is two Contacts: no cross-Location merge.
        $this->assertSame(2, Contacts::where('phone', '14155551234')->count());
        $this->assertSame(2, CrmOpportunity::count());
        $this->assertSame(
            [(int) $this->downtown->id, (int) $this->uptown->id],
            CrmOpportunity::orderBy('id')->pluck('location_id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertSame(2, FormSession::count());
    }

    public function test_malformed_forged_and_expired_sessions_fail_closed(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        [$nonce, $versionId, $signature] = explode('.', $token);

        foreach ([
            'empty' => '', 'garbage' => 'garbage', 'two parts' => $nonce.'.'.$signature,
            'wrong signature' => $nonce.'.'.$versionId.'.'.str_repeat('0', 64),
            'other nonce' => str_repeat('b', 32).'.'.$versionId.'.'.$signature,
        ] as $label => $bad) {
            try {
                $this->step($bad, 'page_1');
                $this->fail("expected refusal: {$label}");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('form', $exception->errors(), $label);
            }
        }
        $this->assertSame(0, FormSession::count());

        // An expired session is refused on its next answer.
        $this->step($token, 'page_1');
        FormSession::query()->update(['expires_at' => now()->subMinute()]);
        try {
            $this->step($token, 'page_2');
            $this->fail('an expired session must be refused');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    public function test_only_long_abandoned_sessions_are_prunable(): void
    {
        $this->step(FormOperationToken::issue($this->deployment), 'page_1');
        $this->step(FormOperationToken::issue($this->deployment), 'page_1');

        $old = FormSession::orderBy('id')->first();
        DB::table('form_sessions')->where('id', $old->id)->update(['expires_at' => now()->subDays(8)]);

        $this->assertSame(1, (new FormSession())->prunable()->count());
        $this->assertSame((int) $old->id, (int) (new FormSession())->prunable()->first()->id);
    }

    // ------------------------------------------------------------- query bounds

    public function test_a_step_costs_a_constant_number_of_queries_however_many_sessions_exist(): void
    {
        $this->step(FormOperationToken::issue($this->deployment), 'page_1');   // warm up

        $measure = function (): int {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });
            $this->step(FormOperationToken::issue($this->deployment), 'page_1');

            return $count;
        };

        $few = $measure();

        $version = $this->form->currentVersion();
        foreach (range(1, 40) as $i) {
            FormSession::create([
                'form_deployment_id' => $this->deployment->id, 'form_version_id' => $version->id,
                'operation_nonce' => bin2hex(random_bytes(16)), 'answers' => [], 'completed_pages' => [],
                'expires_at' => now()->addHour(),
            ]);
        }

        $this->assertSame($few, $measure(), 'queries must not grow with the number of sessions');
    }

    // ------------------------------------------------------------------- HTTP

    public function test_the_public_questionnaire_flow_end_to_end_over_http(): void
    {
        $start = $this->get(route('public.forms.show', [$this->deployment->uid]))->assertOk();
        $start->assertSee('Step 1 of 3', false)->assertSee('About you')->assertSee('Your name')->assertDontSee('Event date');
        preg_match('/name="operation_token" value="([^"]+)"/', $start->getContent(), $match);
        $token = $match[1];
        $submit = route('public.forms.submit', [$this->deployment->uid]);
        $pageUrl = fn (string $page) => route('public.forms.page', [$this->deployment->uid, $token, $page]);

        // Page 2 is not reachable before page 1 is completed.
        $this->get($pageUrl('page_2'))->assertNotFound();

        $this->post($submit, $this->stepInput($token, 'page_1'))->assertRedirect($pageUrl('page_2'));
        $second = $this->get($pageUrl('page_2'))->assertOk();
        $second->assertSee('Step 2 of 3', false)->assertSee('Event date')->assertDontSee('Your name')->assertSee('data-role="public-form-back"', false);
        $this->assertSame(0, FormSubmission::count());

        // Back to page 1 shows the answers the server kept.
        $this->get($pageUrl('page_1'))->assertOk()->assertSee('Ada Lovelace');

        // Page 3 is still locked until page 2 is done.
        $this->get($pageUrl('page_3'))->assertNotFound();
        $this->post($submit, $this->stepInput($token, 'page_2'))->assertRedirect($pageUrl('page_3'));
        $this->get($pageUrl('page_3'))->assertOk()->assertSee('Step 3 of 3', false)->assertSee('Finish');

        // The last page finishes, exactly once even when double-clicked.
        $this->post($submit, $this->stepInput($token, 'page_3'));
        $this->post($submit, $this->stepInput($token, 'page_3'));
        $this->assertSame(['submissions' => 1, 'contacts' => 1, 'opportunities' => 1, 'events' => 1], $this->counts());

        $submission = FormSubmission::firstOrFail();
        $this->get(route('public.forms.thanks', ['deploymentUid' => $this->deployment->uid, 's' => $submission->uid]))
            ->assertOk()->assertSee('Thanks — questionnaire received.');
        // A finished session no longer opens pages.
        $this->get($pageUrl('page_2'))->assertNotFound();
    }

    public function test_a_page_address_with_a_forged_stale_or_wrong_token_is_a_404(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $this->step($token, 'page_1');
        [$nonce, $versionId, $signature] = explode('.', $token);
        $url = fn (string $t, string $page = 'page_2') => '/forms/'.$this->deployment->uid.'/s/'.$t.'/'.$page;

        $this->get($url($token))->assertOk();
        $this->get($url($nonce.'.'.$versionId.'.'.str_repeat('0', 64)))->assertNotFound();
        $this->get($url(str_repeat('c', 32).'.'.$versionId.'.'.$signature))->assertNotFound();
        $this->get($url($token, 'page_9'))->assertNotFound();
        $this->get($url($token, 'Bad-Page'))->assertNotFound();
        $this->get('/forms/'.$this->deployment->uid.'/s/not-a-token/page_1')->assertNotFound();

        // A token for another deployment of the same form.
        $other = $this->deploy($this->business, $this->form, $this->uptown);
        $this->get('/forms/'.$other->uid.'/s/'.$token.'/page_2')->assertNotFound();

        // A one-page form has no page route.
        [, $ordinaryDeployment] = $this->liveForm($this->business, $this->uptown, ['name' => 'Ordinary']);
        $this->get('/forms/'.$ordinaryDeployment->uid.'/s/'.FormOperationToken::issue($ordinaryDeployment).'/page_1')->assertNotFound();
    }

    public function test_page_urls_refuse_when_the_form_loses_authority_mid_flow(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $this->step($token, 'page_1');
        $url = route('public.forms.page', [$this->deployment->uid, $token, 'page_2']);

        $this->get($url)->assertOk();
        app(FormManager::class)->deactivate($this->business, $this->form);
        $this->get($url)->assertNotFound();
        $this->post(route('public.forms.submit', [$this->deployment->uid]), $this->stepInput($token, 'page_2'))->assertNotFound();
    }

    public function test_a_page_view_costs_a_constant_number_of_queries_however_many_sessions_exist(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $this->step($token, 'page_1');
        $url = route('public.forms.page', [$this->deployment->uid, $token, 'page_2']);
        $this->get($url)->assertOk();   // warm up

        $measure = function () use ($url): int {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });
            $this->get($url)->assertOk();

            return $count;
        };

        $few = $measure();
        $version = $this->form->currentVersion();
        foreach (range(1, 40) as $i) {
            FormSession::create([
                'form_deployment_id' => $this->deployment->id, 'form_version_id' => $version->id,
                'operation_nonce' => bin2hex(random_bytes(16)), 'answers' => [], 'completed_pages' => [],
                'expires_at' => now()->addHour(),
            ]);
        }

        $this->assertSame($few, $measure());
    }

    // ----------------------------------------------------------- customer editor

    public function test_the_owner_builds_and_reorders_a_questionnaire_through_the_editor(): void
    {
        $this->authenticateAs($this->owner);
        $w = $this->workspace;
        $b = $this->business;

        $slots = fn (array $positions, array $titles = []) => array_map(
            fn ($n) => ['key' => 'page_'.$n, 'title' => $titles[$n] ?? '', 'position' => $positions[$n] ?? ($n + 10)],
            range(1, FormDefinitionNormalizer::MAX_PAGES)
        );
        $field = fn (string $label, string $page, string $type = 'text') => ['key' => '', 'label' => $label, 'page' => $page, 'type' => $type, 'required' => '0', 'contact_name' => '0', 'options' => ''];

        $this->post($this->formsRoute('store', $w, $b), [
            'name' => 'Built in the editor',
            'pages' => $slots([1 => 1, 2 => 2], [1 => 'First', 2 => 'Second']),
            'fields' => [$field('Alpha', 'page_1'), $field('Beta', 'page_2'), $field('', 'page_1')],   // + a blank spare row
            'create_opportunity' => '0',
        ])->assertRedirect();

        $form = Form::where('name', 'Built in the editor')->firstOrFail();
        $this->assertSame(['page_1', 'page_2'], $form->currentVersion()->pageKeys(), 'unused slots are ignored');
        $this->assertSame(['alpha', 'beta'], array_column($form->currentVersion()->fields, 'key'));

        $this->get($this->formsRoute('edit', $w, $b, [$form->uid]))->assertOk()
            ->assertSee('data-role="forms-pages"', false)->assertSee('First')->assertSee('Second');

        // Reorder: swap the positions; the keys of the questions are carried by the form.
        $existing = $form->currentVersion()->fields;
        $this->post($this->formsRoute('update', $w, $b, [$form->uid]), [
            'name' => 'Built in the editor',
            'pages' => $slots([1 => 2, 2 => 1], [1 => 'First', 2 => 'Second']),
            'fields' => [
                ['key' => 'alpha', 'label' => 'Alpha', 'page' => 'page_1', 'type' => 'text', 'required' => '0', 'contact_name' => '0', 'options' => ''],
                ['key' => 'beta', 'label' => 'Beta', 'page' => 'page_2', 'type' => 'text', 'required' => '0', 'contact_name' => '0', 'options' => ''],
            ],
            'create_opportunity' => '0',
        ])->assertRedirect();

        $form->refresh();
        $this->assertSame(2, $form->current_version);
        $this->assertSame(['page_2', 'page_1'], $form->currentVersion()->pageKeys());
        $this->assertSame(['beta', 'alpha'], array_column($form->currentVersion()->fields, 'key'));
        $this->assertNotEmpty($existing);

        // A refused page rule is worded by the manager and writes nothing.
        $before = Form::count();
        $this->post($this->formsRoute('store', $w, $b), [
            'name' => 'Bad page ref', 'pages' => $slots([1 => 1]),
            'fields' => [$field('Alpha', 'page_99')], 'create_opportunity' => '0',
        ])->assertSessionHasErrors('forms');
        $this->assertSame($before, Form::count());
    }

    public function test_a_response_detail_groups_answers_by_the_pages_of_its_version(): void
    {
        $submission = $this->walk(FormOperationToken::issue($this->deployment))->submission;
        $this->authenticateAs($this->owner);

        $html = $this->get($this->formsRoute('submissions.show', $this->workspace, $this->business, [$submission->uid]))->assertOk()->getContent();

        foreach (['About you', 'Your event', 'Details'] as $title) {
            $this->assertStringContainsString($title, $html);
        }
        $this->assertSame(3, substr_count($html, 'data-role="forms-answers-page"'));
        $this->assertLessThan(strpos($html, 'Event date'), strpos($html, 'Your name'), 'page order is preserved');
    }
}
