<?php

namespace Tests\Feature\Forms;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CustomFieldDefinition;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Forms visual builder — the editor shell over the unchanged Forms domain:
 * existing forms open as they are, every edit is an autosave of the whole
 * document that goes through FormManager (so versioning, immutability and the
 * Location contract are the ones already proven), and the new elements, style and
 * consent behave as documented.
 */
class FormBuilderTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private $owner;

    private Business $business;

    private $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private CustomFieldDefinitionManager $customFields;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->formsPipeline($this->business);
        $this->customFields = app(CustomFieldDefinitionManager::class);
        $this->authenticateAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function save(Form $form, array $document, ?Business $through = null)
    {
        return $this->postJson($this->formsRoute('builder.save', $this->workspace, $through ?? $this->business, [$form->uid]), $document);
    }

    /** The current document, changed by $change, saved on top of the version it was read at. */
    private function edit(Form $form, callable $change)
    {
        $document = $this->builderDocumentFor($form);
        $document = $change($document) ?? $document;

        return $this->save($form, $document);
    }

    /** @return array<string, mixed> the JSON state the builder page hands its script */
    private function pageState(Form $form): array
    {
        $html = $this->get($this->formsRoute('edit', $this->workspace, $this->business, [$form->uid]))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<script type="application/json" id="fb-state">(.*?)</script>#s', $html, $match));

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function keys(Form $form): array
    {
        return array_column($form->fresh()->currentVersion()->fields, 'key');
    }

    private function liveBuilderForm(array $overrides = []): array
    {
        $form = $this->makeForm($this->business, $overrides, true);

        return [$form, $this->deploy($this->business, $form, $this->downtown)];
    }

    private function submit(FormDeployment $deployment, array $answers = []): FormSubmission
    {
        return app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, $answers))->submission->fresh();
    }

    private function field(array $document, string $key): array
    {
        return collect($document['fields'])->firstWhere('key', $key) ?? $this->fail("no field {$key}");
    }

    // -------------------------------------------------------- existing forms open

    public function test_an_existing_form_opens_in_the_visual_builder_exactly_as_stored(): void
    {
        $form = $this->makeForm($this->business, $this->questionnaireInput());

        $state = $this->pageState($form);

        $this->assertSame($form->uid, $state['formUid']);
        $this->assertSame(1, $state['version']);
        $this->assertSame($this->keys($form), array_column($state['doc']['fields'], 'key'));
        $this->assertSame(['page_1', 'page_2', 'page_3'], array_column($state['doc']['pages'], 'key'));
        $this->assertSame('About you', $state['doc']['pages'][0]['title']);
        $this->assertSame(['Wedding', 'Corporate'], $this->field($state['doc'], 'event_type')['options']);

        $groups = array_column($state['toolbox'], 'id');
        $this->assertSame(['quick', 'personal', 'fields', 'content', 'custom', 'submit', 'consent'], $groups, 'no Payments group: there is no safe canonical seam');

        $this->get($this->formsRoute('edit', $this->workspace, $this->business, [$form->uid]))
            ->assertSee('data-role="forms-builder"', false)
            ->assertSee('data-role="forms-tabs"', false)
            ->assertDontSee('data-role="forms-pages"', false);
    }

    public function test_opening_the_builder_writes_nothing_and_a_no_op_save_writes_no_version(): void
    {
        $form = $this->makeForm($this->business);
        $before = FormVersion::count();

        $this->pageState($form);
        $this->assertSame($before, FormVersion::count());

        $this->save($form, $this->builderDocumentFor($form))->assertOk()->assertJson(['status' => 'saved', 'version' => 1]);
        $this->assertSame($before, FormVersion::count(), 'saving an unchanged document is not a new version');
    }

    // ------------------------------------------------------------ add/remove/reorder

    public function test_adding_a_field_saves_the_next_version_and_the_old_one_is_untouched(): void
    {
        $form = $this->makeForm($this->business);
        $v1 = $form->currentVersion();
        $v1Fields = $v1->fields;

        $this->edit($form, function (array $d) {
            $d['fields'][] = ['key' => 'budget', 'label' => 'Budget', 'type' => 'currency', 'page' => 'page_1', 'required' => false, 'options' => [], 'contact_name' => false];

            return $d;
        })->assertOk()->assertJson(['status' => 'saved', 'version' => 2]);

        $form->refresh();
        $this->assertContains('budget', $this->keys($form));
        $this->assertSame($v1Fields, FormVersion::find($v1->id)->fields, 'version 1 is immutable');
        $this->assertSame(2, FormVersion::where('form_id', $form->id)->count());
    }

    public function test_removing_a_field_removes_it_from_the_next_version_only(): void
    {
        $form = $this->makeForm($this->business);

        $this->edit($form, function (array $d) {
            $d['fields'] = array_values(array_filter($d['fields'], fn ($f) => $f['key'] !== 'message'));

            return $d;
        })->assertOk();

        $this->assertNotContains('message', $this->keys($form));
        $this->assertContains('message', array_column(FormVersion::where('form_id', $form->id)->where('version', 1)->first()->fields, 'key'));
    }

    public function test_reordering_fields_is_a_new_version_with_the_new_order(): void
    {
        $form = $this->makeForm($this->business);
        $original = $this->keys($form);

        $this->edit($form, function (array $d) {
            $d['fields'] = array_reverse($d['fields']);

            return $d;
        })->assertOk()->assertJson(['version' => 2]);

        $this->assertSame(array_reverse($original), $this->keys($form));
    }

    public function test_pages_can_be_added_moved_between_and_reordered(): void
    {
        $form = $this->makeForm($this->business, $this->questionnaireInput(), true);
        $this->assertSame(['page_1', 'page_2', 'page_3'], $form->currentVersion()->pageKeys());

        // Move "Message" to page 1 and put the pages in the order 3,1,2 (page 3 is then empty and drops out).
        $this->edit($form, function (array $d) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'message') {
                    $f['page'] = 'page_1';
                }
                if ($f['key'] === 'i_agree') {
                    $f['page'] = 'page_3';
                }
            }
            unset($f);
            $d['pages'] = [['key' => 'page_2', 'title' => 'Your event', 'position' => 1], ['key' => 'page_1', 'title' => 'About you', 'position' => 2], ['key' => 'page_3', 'title' => 'Details', 'position' => 3]];

            return $d;
        })->assertOk()->assertJson(['version' => 2]);

        $v2 = $form->fresh()->currentVersion();
        $this->assertSame(['page_2', 'page_1', 'page_3'], $v2->pageKeys());
        $this->assertContains('message', array_column($v2->fieldsOnPage('page_1'), 'key'));
    }

    public function test_an_ordinary_form_stays_one_page_and_a_page_with_no_questions_is_dropped(): void
    {
        $form = $this->makeForm($this->business);

        $this->edit($form, function (array $d) {
            $d['pages'][] = ['key' => 'page_2', 'title' => 'Empty', 'position' => 2];

            return $d;
        })->assertOk()->assertJson(['version' => 1]);   // dropped => same content => no new version

        $this->assertFalse($form->fresh()->currentVersion()->isMultiPage());
    }

    // ------------------------------------------------------------------ inspector

    public function test_the_inspector_settings_are_saved_with_the_field(): void
    {
        $form = $this->makeForm($this->business);

        $this->edit($form, function (array $d) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'message') {
                    $f = array_merge($f, ['label' => 'Tell us more', 'placeholder' => 'Anything helps', 'help' => 'No sales calls.', 'width' => 'half', 'required' => true, 'default' => 'Hello']);
                }
            }
            unset($f);

            return $d;
        })->assertOk();

        $saved = $this->field(['fields' => $form->fresh()->currentVersion()->fields], 'message');
        $this->assertSame('Tell us more', $saved['label']);
        $this->assertSame('Anything helps', $saved['placeholder']);
        $this->assertSame('No sales calls.', $saved['help']);
        $this->assertSame('half', $saved['width']);
        $this->assertSame('Hello', $saved['default']);
        $this->assertTrue($saved['required']);
        $this->assertSame('message', $saved['key'], 'relabelling never changes the stable key');
    }

    public function test_a_default_for_a_choice_must_be_one_of_its_options(): void
    {
        $form = $this->makeForm($this->business);

        $this->edit($form, function (array $d) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'event_type') {
                    $f['default'] = 'Nonsense';
                }
            }
            unset($f);

            return $d;
        })->assertStatus(422)->assertJson(['status' => 'error']);

        $this->assertSame(1, $form->fresh()->current_version);
    }

    public function test_a_choice_field_needs_two_options_and_the_refusal_writes_nothing(): void
    {
        $form = $this->makeForm($this->business);

        $this->edit($form, function (array $d) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'event_type') {
                    $f['options'] = ['Only one'];
                }
            }
            unset($f);

            return $d;
        })->assertStatus(422)->assertJsonPath('message', '"Event type" needs at least two options to pick from.');

        $this->assertSame(1, $form->fresh()->current_version);
    }

    // --------------------------------------------------------------- new elements

    public function test_the_new_element_types_are_validated_and_stored_and_content_blocks_collect_nothing(): void
    {
        $form = $this->makeForm($this->business);
        $this->edit($form, function (array $d) {
            array_push(
                $d['fields'],
                ['key' => 'h1', 'label' => 'About the party', 'type' => 'heading', 'page' => 'page_1'],
                ['key' => 'p1', 'label' => 'We reply within a day.', 'type' => 'paragraph', 'page' => 'page_1'],
                ['key' => 'd1', 'label' => '', 'type' => 'divider', 'page' => 'page_1'],
                ['key' => 's1', 'label' => '', 'type' => 'spacer', 'page' => 'page_1'],
                ['key' => 'guests', 'label' => 'Guests', 'type' => 'number', 'page' => 'page_1', 'required' => false],
                ['key' => 'budget', 'label' => 'Budget', 'type' => 'currency', 'page' => 'page_1', 'required' => false],
                ['key' => 'starts', 'label' => 'Start time', 'type' => 'datetime', 'page' => 'page_1', 'required' => false],
                ['key' => 'extras', 'label' => 'Extras', 'type' => 'multi_select', 'page' => 'page_1', 'options' => ['Props', 'Prints', 'Guestbook'], 'required' => false],
                ['key' => 'venue', 'label' => 'Venue type', 'type' => 'radio', 'page' => 'page_1', 'options' => ['Indoor', 'Outdoor'], 'required' => false],
                ['key' => 'insured', 'label' => 'Need insurance?', 'type' => 'yes_no', 'page' => 'page_1', 'required' => false],
            );

            return $d;
        })->assertOk();
        $deployment = $this->deploy($this->business, app(FormManager::class)->activate($this->business, $form), $this->downtown);

        $submission = $this->submit($deployment, [
            'guests' => '120', 'budget' => '1500.5', 'starts' => '2027-06-01T18:30',
            'extras' => ['Guestbook', 'Props'], 'venue' => 'Outdoor', 'insured' => 'Yes',
        ]);

        $this->assertSame(120, $submission->values['guests']);
        $this->assertSame('1500.50', $submission->values['budget']);
        $this->assertSame('2027-06-01T18:30', $submission->values['starts']);
        $this->assertSame(['Props', 'Guestbook'], $submission->values['extras'], 'stored in the owner\'s option order');
        $this->assertSame('Outdoor', $submission->values['venue']);
        $this->assertSame('Yes', $submission->values['insured']);
        foreach (['h1', 'p1', 'd1', 's1'] as $content) {
            $this->assertArrayNotHasKey($content, $submission->values, 'content blocks store no answer');
        }

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, ['venue' => 'Underwater']));
    }

    public function test_at_least_one_question_is_required_and_content_blocks_do_not_count(): void
    {
        $form = $this->makeForm($this->business);

        $this->edit($form, fn (array $d) => array_merge($d, ['fields' => [['key' => 'h1', 'label' => 'Just a heading', 'type' => 'heading', 'page' => 'page_1']]]))
            ->assertStatus(422)->assertJsonPath('message', 'Add at least one question.');
    }

    // ------------------------------------------------------- Contact + Custom Field

    public function test_a_canonical_custom_field_is_mapped_by_uid_and_the_answer_reaches_the_contact(): void
    {
        $eventDate = $this->customFields->create($this->business, 'Event Date', 'date');
        $form = $this->makeForm($this->business);
        $this->edit($form, function (array $d) use ($eventDate) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'event_date') {
                    $f['custom_field_uid'] = $eventDate->uid;
                }
            }
            unset($f);

            return $d;
        })->assertOk();
        $deployment = $this->deploy($this->business, app(FormManager::class)->activate($this->business, $form), $this->downtown);

        $contact = $this->submit($deployment)->contact;

        $this->assertSame($eventDate->uid, $this->field(['fields' => $form->fresh()->currentVersion()->fields], 'event_date')['custom_field_uid']);
        $value = app(CustomFieldValueService::class)->valuesFor($this->business, $contact)->firstWhere(fn ($e) => $e['definition']->id === $eventDate->id)['value'] ?? null;
        $this->assertSame('2027-06-01', $value);
    }

    public function test_the_toolbox_offers_active_custom_fields_with_the_right_input_type_and_never_archived_ones(): void
    {
        $form = $this->makeForm($this->business);
        $eventDate = $this->customFields->create($this->business, 'Event Date', 'date');
        $kind = $this->customFields->create($this->business, 'Event Kind', 'select', ['Wedding', 'Corporate']);
        $old = $this->customFields->create($this->business, 'Old Field', 'text');
        $this->customFields->archive($this->business, $old);

        $state = $this->pageState($form);
        $custom = collect($state['toolbox'])->firstWhere('id', 'custom')['items'];
        $byLabel = collect($custom)->keyBy('label');

        $this->assertEqualsCanonicalizing(['Event Date', 'Event Kind'], $byLabel->keys()->all(), 'archived fields are not offered for new insertion');
        $this->assertSame('date', $byLabel['Event Date']['element']['type']);
        $this->assertSame($eventDate->uid, $byLabel['Event Date']['element']['custom_field_uid']);
        $this->assertSame('{{contact.event_date}}', $byLabel['Event Date']['token']);
        $this->assertSame('select', $byLabel['Event Kind']['element']['type']);
        $this->assertSame(['Wedding', 'Corporate'], $byLabel['Event Kind']['element']['options']);
        $this->assertSame($kind->uid, $byLabel['Event Kind']['element']['custom_field_uid']);
        $this->assertArrayHasKey($old->uid, $state['customFields'], 'the registry still knows archived fields so existing mappings can be named');
        $this->assertTrue($state['customFields'][$old->uid]['archived']);
    }

    public function test_an_archived_field_is_refused_for_new_mappings_but_an_existing_mapping_still_renders_and_saves(): void
    {
        $eventDate = $this->customFields->create($this->business, 'Event Date', 'date');
        $other = $this->customFields->create($this->business, 'Other Date', 'date');
        $form = $this->makeForm($this->business);
        $this->edit($form, function (array $d) use ($eventDate) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'event_date') {
                    $f['custom_field_uid'] = $eventDate->uid;
                }
            }
            unset($f);

            return $d;
        })->assertOk();
        $this->customFields->archive($this->business, $eventDate);
        $this->customFields->archive($this->business, $other);

        // The existing mapping still opens, shows as archived, and re-saves.
        $state = $this->pageState($form);
        $this->assertTrue($state['customFields'][$eventDate->uid]['archived']);
        $this->assertSame($eventDate->uid, $this->field($state['doc'], 'event_date')['custom_field_uid']);
        $this->edit($form, fn (array $d) => array_merge($d, ['name' => 'Renamed']))->assertOk();

        // A NEW mapping to an archived field is refused.
        $this->edit($form, function (array $d) use ($other) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'message') {
                    $f['custom_field_uid'] = $other->uid;
                }
            }
            unset($f);

            return $d;
        })->assertStatus(422)->assertJsonFragment(['status' => 'error']);
    }

    public function test_built_in_contact_name_parts_are_explicit_and_create_the_contact_name(): void
    {
        $form = app(FormManager::class)->create($this->business, [
            'name' => 'Names', 'fields' => [
                ['key' => 'first', 'label' => 'First name', 'type' => 'text', 'required' => true, 'contact_part' => 'first_name'],
                ['key' => 'last', 'label' => 'Last name', 'type' => 'text', 'required' => false, 'contact_part' => 'last_name'],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
            ],
        ]);
        $deployment = $this->deploy($this->business, app(FormManager::class)->activate($this->business, $form), $this->downtown);

        $submission = app(FormSubmissionService::class)->submit($deployment->uid, [
            'first' => 'Ada', 'last' => 'Lovelace', 'phone' => '+1 415 555 7777', FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($deployment),
        ])->submission->fresh();

        $this->assertDatabaseHas('contacts_custom_field', ['contact_id' => $submission->contact_id, 'value' => 'Ada']);
        $this->assertDatabaseHas('contacts_custom_field', ['contact_id' => $submission->contact_id, 'value' => 'Lovelace']);
    }

    public function test_a_full_name_and_separate_name_parts_cannot_be_mixed_and_parts_are_never_guessed_from_labels(): void
    {
        $form = $this->makeForm($this->business);

        $this->edit($form, function (array $d) {
            $d['fields'][] = ['key' => 'first', 'label' => 'First name', 'type' => 'text', 'page' => 'page_1', 'contact_part' => 'first_name'];

            return $d;
        })->assertStatus(422);

        // A text question merely LABELLED "First name" is not an identity mapping.
        $plain = app(FormManager::class)->create($this->business, ['name' => 'Plain', 'fields' => [
            ['key' => 'fn', 'label' => 'First name', 'type' => 'text'],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
        ]]);
        $deployment = $this->deploy($this->business, app(FormManager::class)->activate($this->business, $plain), $this->uptown);
        $submission = app(FormSubmissionService::class)->submit($deployment->uid, ['fn' => 'Grace', 'phone' => '+1 415 555 8888', FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($deployment)])->submission->fresh();
        $this->assertDatabaseMissing('contacts_custom_field', ['contact_id' => $submission->contact_id, 'value' => 'Grace']);
    }

    // -------------------------------------------------------------- consent

    public function test_consent_is_separate_never_pre_checked_stored_with_the_submission_and_versioned(): void
    {
        $form = $this->makeForm($this->business);
        $this->edit($form, function (array $d) {
            array_push(
                $d['fields'],
                ['key' => 'tx', 'label' => 'I agree to be contacted about this request.', 'type' => 'consent_transactional', 'page' => 'page_1', 'required' => true],
                ['key' => 'mk', 'label' => 'Send me offers.', 'type' => 'consent_marketing', 'page' => 'page_1', 'required' => false],
            );

            return $d;
        })->assertOk();
        $deployment = $this->deploy($this->business, app(FormManager::class)->activate($this->business, $form), $this->downtown);

        // Never pre-checked: neither checkbox carries `checked` on a fresh render.
        $html = $this->get(route('public.forms.show', [$deployment->uid]))->assertOk()->assertSee('I agree to be contacted about this request.')->getContent();
        $this->assertSame(1, preg_match('#<input type="checkbox" name="tx" value="1"([^>]*)>#', $html, $tx));
        $this->assertSame(1, preg_match('#<input type="checkbox" name="mk" value="1"([^>]*)>#', $html, $mk));
        $this->assertStringNotContainsString('checked', $tx[1]);
        $this->assertStringNotContainsString('checked', $mk[1]);
        $this->assertStringContainsString('required', $tx[1]);

        // Required transactional consent is enforced; marketing stays optional and is stored separately.
        try {
            app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment));
            $this->fail('required consent must be ticked');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('tx', $e->errors());
        }

        $submission = $this->submit($deployment, ['tx' => '1']);
        $this->assertTrue($submission->values['tx']);
        $this->assertFalse($submission->values['mk']);

        $second = $this->submit($deployment, ['tx' => '1', 'mk' => '1', FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($deployment)]);
        $this->assertTrue($second->values['mk']);

        // The wording is versioned: reword it and the old response still reads against the old statement.
        $oldVersionId = $submission->form_version_id;
        $this->edit($form, function (array $d) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'tx') {
                    $f['label'] = 'Reworded consent.';
                }
            }
            unset($f);

            return $d;
        })->assertOk();
        $this->get($this->formsRoute('submissions.show', $this->workspace, $this->business, [$submission->uid]))
            ->assertOk()->assertSee('I agree to be contacted about this request.')->assertDontSee('Reworded consent.')->assertSee('Agreed');
        $this->assertSame($oldVersionId, $submission->fresh()->form_version_id);
    }

    public function test_a_questionnaire_carries_array_answers_and_required_consent_across_pages(): void
    {
        $form = app(FormManager::class)->create($this->business, [
            'name' => 'Two steps',
            'pages' => [['key' => 'page_1', 'title' => 'You', 'position' => 1], ['key' => 'page_2', 'title' => 'Agree', 'position' => 2]],
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'contact_name' => true, 'page' => 'page_1'],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true, 'page' => 'page_1'],
                ['key' => 'extras', 'label' => 'Extras', 'type' => 'multi_select', 'options' => ['Props', 'Prints', 'Guestbook'], 'page' => 'page_1'],
                ['key' => 'note', 'label' => 'Read this', 'type' => 'paragraph', 'page' => 'page_2'],
                ['key' => 'tx', 'label' => 'I agree.', 'type' => 'consent_transactional', 'required' => true, 'page' => 'page_2'],
            ],
        ]);
        $deployment = $this->deploy($this->business, app(FormManager::class)->activate($this->business, $form), $this->downtown);
        $token = FormOperationToken::issue($deployment);
        $service = app(FormSubmissionService::class);

        $service->submit($deployment->uid, [FormSubmissionService::TOKEN_FIELD => $token, FormSubmissionService::PAGE_FIELD => 'page_1', 'name' => 'Ada', 'phone' => '+1 415 555 3333', 'extras' => ['Guestbook', 'Props']]);

        try {
            $service->submit($deployment->uid, [FormSubmissionService::TOKEN_FIELD => $token, FormSubmissionService::PAGE_FIELD => 'page_2']);
            $this->fail('the last page requires the consent');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('tx', $e->errors());
        }
        $this->assertSame(0, FormSubmission::count(), 'a refused final step stores nothing');

        $final = $service->submit($deployment->uid, [FormSubmissionService::TOKEN_FIELD => $token, FormSubmissionService::PAGE_FIELD => 'page_2', 'tx' => '1'])->submission->fresh();

        $this->assertSame(['Props', 'Guestbook'], $final->values['extras']);
        $this->assertTrue($final->values['tx']);
        $this->assertArrayNotHasKey('note', $final->values);
    }

    public function test_at_most_one_of_each_consent_type(): void
    {
        $form = $this->makeForm($this->business);

        $this->edit($form, function (array $d) {
            array_push(
                $d['fields'],
                ['key' => 'a', 'label' => 'Consent A', 'type' => 'consent_marketing', 'page' => 'page_1'],
                ['key' => 'b', 'label' => 'Consent B', 'type' => 'consent_marketing', 'page' => 'page_1'],
            );

            return $d;
        })->assertStatus(422);
    }

    // ------------------------------------------------------------ autosave safety

    public function test_a_stale_tab_gets_a_conflict_and_writes_nothing(): void
    {
        $form = $this->makeForm($this->business);
        $tabA = $this->builderDocumentFor($form);
        $tabB = $this->builderDocumentFor($form);

        $tabA['name'] = 'From tab A';
        $this->save($form, $tabA)->assertOk()->assertJson(['version' => 1]);   // name-only: no new version, still v1
        $tabA['fields'][] = ['key' => 'extra', 'label' => 'Extra', 'type' => 'text', 'page' => 'page_1'];
        $this->save($form, $tabA)->assertOk()->assertJson(['version' => 2]);

        $tabB['fields'][] = ['key' => 'other', 'label' => 'Other', 'type' => 'text', 'page' => 'page_1'];   // still based on version 1
        $this->save($form, $tabB)->assertStatus(409)->assertJson(['status' => 'conflict', 'current_version' => 2]);

        $this->assertNotContains('other', $this->keys($form));
        $this->assertContains('extra', $this->keys($form));
        $this->assertSame(2, $form->fresh()->current_version);

        // Reloading and saving on top of the latest version works.
        $this->edit($form, fn (array $d) => array_merge($d, ['intro' => 'Fresh intro']))->assertOk()->assertJson(['version' => 3]);
    }

    public function test_the_classic_form_post_keeps_working_without_a_base_version(): void
    {
        $form = $this->makeForm($this->business);
        $before = $form->current_version;

        $this->post($this->formsRoute('update', $this->workspace, $this->business, [$form->uid]), array_merge(
            $this->leadFormInput(['name' => 'Classic']),
            ['fields' => array_map(fn ($f, $k) => array_merge($f, ['key' => $form->currentVersion()->fields[$k]['key'], 'required' => ! empty($f['required']) ? '1' : '0', 'contact_name' => ! empty($f['contact_name']) ? '1' : '0']), $this->leadFormInput()['fields'], array_keys($this->leadFormInput()['fields']))],
        ))->assertRedirect();

        $this->assertSame('Classic', $form->fresh()->name);
        $this->assertSame($before, $form->fresh()->current_version);
    }

    // ------------------------------------------------------- versions & submissions

    public function test_a_submission_stays_tied_to_the_version_it_was_answered_against_when_the_form_is_edited(): void
    {
        [$form, $deployment] = $this->liveBuilderForm();
        $submission = $this->submit($deployment);
        $versionId = $submission->form_version_id;

        $this->edit($form, function (array $d) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'message') {
                    $f['label'] = 'A completely different question';
                }
            }
            unset($f);
            $d['fields'] = array_values(array_filter($d['fields'], fn ($f) => $f['key'] !== 'event_date'));

            return $d;
        })->assertOk()->assertJson(['version' => 2]);

        $this->assertSame($versionId, $submission->fresh()->form_version_id);
        $this->assertSame(['your_name', 'phone', 'email', 'event_date', 'event_type', 'message'], array_column(FormVersion::find($versionId)->fields, 'key'));

        $this->get($this->formsRoute('submissions.show', $this->workspace, $this->business, [$submission->uid]))
            ->assertOk()->assertSee('Message')->assertSee('Event date')->assertDontSee('A completely different question');

        // And a visitor who rendered version 1 still finishes against version 1.
        $token = FormOperationToken::issue($deployment, FormVersion::find($versionId));
        $late = app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, ['event_date' => '2028-01-01'], $token))->submission;
        $this->assertSame($versionId, $late->form_version_id);
    }

    public function test_the_response_list_shows_time_contact_location_key_answers_and_status(): void
    {
        [$form, $deployment] = $this->liveBuilderForm();
        $submission = $this->submit($deployment, ['message' => 'Need a booth for 80 guests']);

        $this->get($this->formsRoute('submissions.index', $this->workspace, $this->business).'?form='.$form->uid)
            ->assertOk()
            ->assertSee('data-role="forms-tabs"', false)
            ->assertSee($submission->uid)
            ->assertSee('Ada Lovelace')
            ->assertSee('Downtown')
            ->assertSee('Email')
            ->assertSee('Contact created');
    }

    public function test_the_form_is_one_business_wide_definition_and_deployments_stay_location_scoped(): void
    {
        [$form, $atDowntown] = $this->liveBuilderForm();
        $atUptown = $this->deploy($this->business, $form, $this->uptown);

        $this->edit($form, fn (array $d) => array_merge($d, ['intro' => 'Edited once']))->assertOk();

        $this->assertSame(1, Form::where('business_id', $this->business->id)->count(), 'editing never copies the form per Location');
        $this->assertSame(2, FormDeployment::where('form_id', $form->id)->count());
        $this->assertSame('Edited once', $form->fresh()->currentVersion()->intro);

        $one = $this->submit($atDowntown);
        $two = $this->submit($atUptown, [FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($atUptown)]);
        $this->assertSame((int) $this->downtown->id, (int) $one->business_location_id);
        $this->assertSame((int) $this->uptown->id, (int) $two->business_location_id);
    }

    // ----------------------------------------------------------- style & preview

    public function test_the_form_style_is_versioned_validated_and_rendered_by_the_public_page(): void
    {
        [$form, $deployment] = $this->liveBuilderForm();
        $this->assertNull($form->currentVersion()->getAttribute('design'), 'no style set: nothing stored, hash unchanged');

        $this->edit($form, fn (array $d) => array_merge($d, ['design' => ['accent' => '#AA3355', 'button_align' => 'center', 'radius' => 'lg', 'width' => 'wide', 'background' => '#eef2ff'], 'submit_label' => 'CHECK AVAILABILITY NOW']))
            ->assertOk()->assertJson(['version' => 2]);

        // (MySQL JSON does not preserve key order, so compare as a map.)
        $this->assertEquals(['accent' => '#aa3355', 'background' => '#eef2ff', 'button_align' => 'center', 'radius' => 'lg', 'width' => 'wide'], $form->fresh()->currentVersion()->style());

        $html = $this->get(route('public.forms.show', [$deployment->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('--pf-accent:#aa3355', $html);
        $this->assertStringContainsString('--pf-radius:16px', $html);
        $this->assertStringContainsString('--pf-width:52rem', $html);
        $this->assertStringContainsString('pf-align-center', $html);
        $this->assertStringContainsString('CHECK AVAILABILITY NOW', $html);

        foreach (['accent' => 'red', 'radius' => 'huge', 'width' => 'enormous', 'button_align' => 'diagonal'] as $key => $bad) {
            $this->edit($form, fn (array $d) => array_merge($d, ['design' => [$key => $bad]]))->assertStatus(422);
        }
    }

    public function test_a_form_without_a_style_hashes_exactly_as_before_the_style_existed(): void
    {
        $content = app(\App\Library\Forms\FormDefinitionNormalizer::class)->content($this->business, $this->leadFormInput());

        $this->assertArrayNotHasKey('design', $content);
        $this->assertArrayNotHasKey('placeholder', $content['fields'][0]);
        $this->assertSame(['key', 'label', 'type', 'required', 'options', 'contact_name', 'page'], array_keys($content['fields'][0]));
    }

    public function test_the_preview_renders_the_unsaved_document_with_the_very_markup_the_public_page_uses(): void
    {
        [$form, $deployment] = $this->liveBuilderForm();
        $document = $this->builderDocumentFor($form);
        $document['fields'][] = ['key' => 'budget', 'label' => 'Budget', 'type' => 'currency', 'page' => 'page_1', 'placeholder' => '0.00'];

        $before = FormVersion::count();
        $preview = $this->postJson($this->formsRoute('builder.preview', $this->workspace, $this->business, [$form->uid]), $document)->assertOk();
        $this->assertSame($before, FormVersion::count(), 'a preview writes nothing');
        $html = $preview->getContent();
        $this->assertStringContainsString('Budget', $html);
        $this->assertStringContainsString('placeholder="0.00"', $html);
        $this->assertStringNotContainsString('operation_token', $html, 'a preview has no token and submits nothing');

        // Parity: save it, then the public page renders the SAME field markup.
        $this->save($form, $document)->assertOk();
        $public = $this->get(route('public.forms.show', [$deployment->uid]))->assertOk()->getContent();
        $extract = fn (string $page): string => preg_replace('/\s+/', ' ', (string) (preg_match('#<div class="pf-row">(.*?)</div>\s*<div class="pf-actions#s', $page, $m) ? $m[1] : ''));
        $this->assertNotSame('', $extract($html));
        $this->assertSame($extract($html), $extract($public), 'preview and public form share one renderer');
    }

    public function test_an_invalid_document_is_not_previewed_and_not_saved(): void
    {
        $form = $this->makeForm($this->business);
        $document = $this->builderDocumentFor($form);
        $document['fields'][0]['type'] = 'telepathy';

        $this->postJson($this->formsRoute('builder.preview', $this->workspace, $this->business, [$form->uid]), $document)->assertStatus(422);
        $this->save($form, $document)->assertStatus(422);
    }

    // ------------------------------------------------------- forged ids fail closed

    public function test_forged_business_form_and_field_ids_fail_closed(): void
    {
        [, $other, $otherWorkspace] = $this->formsTenant(WorkspacePlanTier::Core, 'Other Business');
        $theirs = $this->makeForm($other);
        $mine = $this->makeForm($this->business);
        $theirField = $this->customFields->create($other, 'Their Date', 'date');

        // A foreign form through my Business, my form through their Business: both 404, nothing written.
        $this->save($theirs, $this->builderDocumentFor($theirs))->assertNotFound();
        $this->postJson($this->formsRoute('builder.save', $otherWorkspace, $other, [$mine->uid]), $this->builderDocumentFor($mine))->assertNotFound();
        $this->postJson($this->formsRoute('builder.preview', $this->workspace, $this->business, [$theirs->uid]), $this->builderDocumentFor($theirs))->assertNotFound();
        $this->get($this->formsRoute('analytics', $this->workspace, $this->business, [$theirs->uid]))->assertNotFound();
        $this->get($this->formsRoute('notifications', $this->workspace, $this->business, [$theirs->uid]))->assertNotFound();
        $this->get($this->formsRoute('edit', $this->workspace, $this->business, [$theirs->uid]))->assertNotFound();
        $this->assertSame(1, $theirs->fresh()->current_version);

        // Another Business's Custom Field uid cannot be mapped.
        $this->edit($mine, function (array $d) use ($theirField) {
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === 'event_date') {
                    $f['custom_field_uid'] = $theirField->uid;
                }
            }
            unset($f);

            return $d;
        })->assertStatus(422);

        // A page/field referencing a page that does not exist, and a reserved key, are refused.
        $this->edit($mine, function (array $d) {
            $d['fields'][0]['page'] = 'page_9';

            return $d;
        })->assertStatus(422);
        $this->edit($mine, function (array $d) {
            $d['fields'][0]['key'] = 'operation_token';

            return $d;
        })->assertStatus(422);
        $this->assertSame(1, $mine->fresh()->current_version);
    }

    public function test_the_builder_routes_follow_the_forms_capability_and_entitlement(): void
    {
        $form = $this->makeForm($this->business);
        $document = $this->builderDocumentFor($form);

        $this->authenticateWithoutFormsCapability($this->owner);
        $this->save($form, $document)->assertStatus(401);

        $this->authenticateAs($this->owner);
        $this->denyFeature($this->workspace, \App\Enums\Entitlement\PlatformFeature::Forms);
        $this->save($form, $document)->assertNotFound();
    }

    // ------------------------------------------------- notifications/analytics/integrate

    public function test_analytics_shows_only_measured_numbers_and_never_invents_views(): void
    {
        [$form, $deployment] = $this->liveBuilderForm();
        $this->submit($deployment);

        $page = $this->get($this->formsRoute('analytics', $this->workspace, $this->business, [$form->uid]))->assertOk();
        $page->assertSee('data-role="stat-total"', false)->assertSee('Not measured yet')->assertDontSee('Views')->assertDontSee('Conversion');
        $this->assertSame(1, preg_match('#data-role="stat-total">(\d+)<#', $page->getContent(), $m));
        $this->assertSame('1', $m[1]);
    }

    public function test_analytics_counts_only_the_locations_the_member_may_see(): void
    {
        [$form, $atDowntown] = $this->liveBuilderForm();
        $atUptown = $this->deploy($this->business, $form, $this->uptown);
        $this->submit($atDowntown);
        $this->submit($atDowntown, [FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($atDowntown)]);
        $this->submit($atUptown, [FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($atUptown)]);

        $staff = $this->staffGrantedOnly($this->workspace, $this->uptown);
        $this->authenticateAs($staff);
        $page = $this->get($this->formsRoute('analytics', $this->workspace, $this->business, [$form->uid]))->assertOk();
        $this->assertSame(1, preg_match('#data-role="stat-total">(\d+)<#', $page->getContent(), $m));
        $this->assertSame('1', $m[1], 'a Location-limited member sees only their Location\'s response');
        $page->assertDontSee('Downtown');
    }

    public function test_notifications_points_to_automations_and_claims_nothing_it_does_not_have(): void
    {
        $form = $this->makeForm($this->business);

        $this->get($this->formsRoute('notifications', $this->workspace, $this->business, [$form->uid]))
            ->assertOk()->assertSee('data-role="forms-notifications"', false)->assertSee('A form is submitted')->assertSee('Not available yet');
    }

    public function test_integrate_lists_location_links_and_embed_code_for_enabled_deployments_only(): void
    {
        [$form, $atDowntown] = $this->liveBuilderForm();
        $off = $this->deploy($this->business, $form, $this->uptown);
        app(FormManager::class)->setDeployment($this->business, $form, $this->uptown, false);

        $html = $this->get($this->formsRoute('edit', $this->workspace, $this->business, [$form->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="forms-integrate-link"', $html);
        $this->assertStringContainsString('value="'.route('public.forms.show', [$atDowntown->uid]).'"', $html);
        $this->assertStringContainsString('<iframe src="'.route('public.forms.show', [$atDowntown->uid]).'"', html_entity_decode($html));
        $this->assertStringNotContainsString('value="'.route('public.forms.show', [$off->uid]).'"', $html, 'a switched-off deployment has no link to copy');
    }

    // ------------------------------------------------------------- creation

    public function test_a_new_form_is_created_from_a_starting_point_and_opens_in_the_builder(): void
    {
        $this->get($this->formsRoute('create', $this->workspace, $this->business))->assertOk()->assertSee('Starting point')->assertSee('Check availability');

        $response = $this->post($this->formsRoute('store', $this->workspace, $this->business), ['name' => 'Photo Booth', 'starter' => 'availability']);
        $form = Form::where('name', 'Photo Booth')->firstOrFail();
        $response->assertRedirect($this->formsRoute('edit', $this->workspace, $this->business, [$form->uid]));

        $this->assertSame(['event_date', 'event_type', 'full_name', 'email', 'phone'], $this->keys($form));
        $this->assertSame('Check availability', $form->currentVersion()->submit_label);
        $this->assertSame('draft', $form->lifecycle_state->value);
    }

    public function test_every_custom_field_type_maps_to_an_input_the_server_accepts(): void
    {
        foreach (['text', 'long_text', 'number', 'currency', 'date', 'datetime', 'boolean', 'email', 'phone'] as $type) {
            $this->customFields->create($this->business, 'CF '.$type, $type);
        }
        $this->customFields->create($this->business, 'CF select', 'select', ['A', 'B']);
        $this->customFields->create($this->business, 'CF multi', 'multi_select', ['A', 'B']);

        $form = $this->makeForm($this->business);
        $custom = collect($this->pageState($form)['toolbox'])->firstWhere('id', 'custom')['items'];
        $this->assertCount(11, $custom);

        $document = $this->builderDocumentFor($form);
        foreach ($custom as $i => $item) {
            $document['fields'][] = array_merge($item['element'], ['key' => 'cf'.$i, 'page' => 'page_1']);
        }

        $this->save($form, $document)->assertOk()->assertJson(['status' => 'saved', 'version' => 2]);
        $this->assertCount(11, array_filter($form->fresh()->currentVersion()->fields, fn ($f) => isset($f['custom_field_uid'])));
    }
}
