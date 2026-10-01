<?php

namespace Tests\Feature\Forms;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Forms\FormLifecycleState;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Library\Forms\FormDefinitionNormalizer;
use App\Library\Forms\FormManager;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Forms V1 — the definition, its versions, its lifecycle and its Location
 * deployments, through the one writer (FormManager).
 */
class FormManagerTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private FormManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(FormManager::class);
    }

    public function test_a_form_is_created_as_a_business_scoped_draft_with_a_first_version(): void
    {
        [$owner, $business] = $this->formsTenant();

        $form = $this->manager->create($business, $this->leadFormInput(), (int) $owner->user_id);

        $this->assertSame((int) $business->id, (int) $form->business_id);
        $this->assertSame((int) $owner->user_id, (int) $form->created_by_user_id);
        $this->assertSame(FormLifecycleState::Draft, $form->lifecycle_state);
        $this->assertSame(1, $form->current_version);
        $this->assertNotEmpty($form->uid);
        $this->assertSame(1, FormVersion::where('form_id', $form->id)->count());

        $version = $form->currentVersion();
        $this->assertSame(1, $version->version);
        $this->assertSame('Send', $version->submit_label);
        $this->assertCount(6, $version->fields);
        $this->assertSame(['your_name', 'phone', 'email', 'event_date', 'event_type', 'message'], array_column($version->fields, 'key'));
        $this->assertSame(['Wedding', 'Corporate', 'Birthday'], $version->fields[4]['options']);
    }

    public function test_the_lifecycle_state_cannot_be_mass_assigned(): void
    {
        [, $business] = $this->formsTenant();

        $form = Form::create(['business_id' => $business->id, 'name' => 'Probe', 'lifecycle_state' => 'active', 'current_version' => 9]);

        $this->assertSame(FormLifecycleState::Draft, $form->fresh()->lifecycle_state);
        $this->assertSame(1, $form->fresh()->current_version);
    }

    public function test_editing_changed_content_writes_a_new_immutable_version_and_unchanged_content_writes_none(): void
    {
        [, $business] = $this->formsTenant();
        $form = $this->manager->create($business, $this->leadFormInput());

        // Same meaning, different whitespace: no new version.
        $same = $this->manager->update($business, $form, $this->leadFormInput(['name' => '  Quote request  ']));
        $this->assertSame(1, $same->current_version);
        $this->assertSame(1, FormVersion::where('form_id', $form->id)->count());

        $fields = $this->leadFormInput()['fields'];
        $fields[5]['label'] = 'Anything else?';
        $edited = $this->manager->update($business, $form, $this->leadFormInput(['fields' => $fields]));

        $this->assertSame(2, $edited->current_version);
        $this->assertSame(2, FormVersion::where('form_id', $form->id)->count());
        // The old version still says what it always said.
        $this->assertSame('Message', FormVersion::where('form_id', $form->id)->where('version', 1)->first()->fields[5]['label']);
        $this->assertSame('Anything else?', $edited->currentVersion()->fields[5]['label']);
    }

    public function test_a_form_version_is_never_updated_or_deleted(): void
    {
        [, $business] = $this->formsTenant();
        $version = $this->manager->create($business, $this->leadFormInput())->currentVersion();

        try {
            $version->update(['submit_label' => 'Changed']);
            $this->fail('A version update must be refused.');
        } catch (LogicException) {
            $this->assertSame('Send', $version->fresh()->submit_label);
        }

        $this->expectException(LogicException::class);
        $version->delete();
    }

    public function test_the_definition_is_bounded(): void
    {
        [, $business] = $this->formsTenant();
        $cases = [
            'no questions' => ['fields' => []],
            'blank questions only' => ['fields' => [['label' => '  ', 'type' => 'text']]],
            'unknown type' => ['fields' => [['label' => 'Q', 'type' => 'file_upload']]],
            'two phone fields' => ['fields' => [['label' => 'A', 'type' => 'phone'], ['label' => 'B', 'type' => 'phone']]],
            'select with one option' => ['fields' => [['label' => 'Pick', 'type' => 'select', 'options' => 'Only']]],
            'name flag on a non-text field' => ['fields' => [['label' => 'Mail', 'type' => 'email', 'contact_name' => true]]],
            'two name fields' => ['fields' => [['label' => 'A', 'type' => 'text', 'contact_name' => true], ['label' => 'B', 'type' => 'text', 'contact_name' => true]]],
            'duplicate key' => ['fields' => [['key' => 'dup', 'label' => 'A', 'type' => 'text'], ['key' => 'dup', 'label' => 'B', 'type' => 'text']]],
            'reserved key' => ['fields' => [['key' => 'form_hp', 'label' => 'A', 'type' => 'text']]],
            'invalid key' => ['fields' => [['key' => 'Bad Key!', 'label' => 'A', 'type' => 'text']]],
            'opportunity without a phone field' => ['create_opportunity' => true, 'fields' => [['label' => 'A', 'type' => 'text']]],
            'blank name' => ['name' => ' '],
            'too many questions' => ['fields' => array_map(fn ($i) => ['label' => 'Q'.$i, 'type' => 'text'], range(1, FormDefinitionNormalizer::MAX_FIELDS_PER_PAGE + 1))],
        ];

        foreach ($cases as $label => $override) {
            try {
                $this->manager->create($business, $this->leadFormInput($override));
                $this->fail("Expected a refusal: {$label}");
            } catch (FormRuleException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, Form::count(), 'a refused definition leaves no row behind');
    }

    public function test_the_phone_requirement_and_the_maximum_number_of_questions_are_accepted_at_the_boundary(): void
    {
        [, $business] = $this->formsTenant();
        $this->formsPipeline($business);

        $max = array_map(fn ($i) => ['label' => 'Q'.$i, 'type' => 'text'], range(1, FormDefinitionNormalizer::MAX_FIELDS_PER_PAGE));
        $this->assertCount(FormDefinitionNormalizer::MAX_FIELDS_PER_PAGE, $this->manager->create($business, $this->leadFormInput(['fields' => $max]))->currentVersion()->fields);

        $withDeal = $this->manager->create($business, $this->leadFormInput(['create_opportunity' => true]));
        $this->assertTrue($withDeal->currentVersion()->create_opportunity);
    }

    public function test_an_opportunity_pipeline_must_be_an_active_pipeline_of_this_business(): void
    {
        [, $business] = $this->formsTenant();
        [, $other] = $this->formsTenant(name: 'Other Studio');
        $mine = $this->formsPipeline($business);
        $foreign = $this->formsPipeline($other);

        $ok = $this->manager->create($business, $this->leadFormInput(['create_opportunity' => true, 'opportunity_pipeline_id' => $mine->id]));
        $this->assertSame((int) $mine->id, (int) $ok->currentVersion()->opportunity_pipeline_id);

        $this->expectException(FormRuleException::class);
        $this->manager->create($business, $this->leadFormInput(['create_opportunity' => true, 'opportunity_pipeline_id' => $foreign->id]));
    }

    public function test_definitions_are_isolated_between_businesses(): void
    {
        [, $a] = $this->formsTenant(name: 'Studio A');
        [, $b] = $this->formsTenant(name: 'Studio B');
        $formA = $this->makeForm($a);
        $this->makeForm($b);

        $this->assertSame(1, Form::where('business_id', $a->id)->count());
        $this->assertSame(1, Form::where('business_id', $b->id)->count());

        foreach (['update', 'activate', 'deactivate'] as $method) {
            try {
                $method === 'update'
                    ? $this->manager->update($b, $formA, $this->leadFormInput())
                    : $this->manager->{$method}($b, $formA);
                $this->fail("{$method} of a foreign form must be refused");
            } catch (FormRuleException) {
                $this->assertTrue(true);
            }
        }

        // The caller's copy of the model is never trusted for ownership: a form
        // whose in-memory business_id was rewritten is still judged by the DB row.
        $forged = Form::find($formA->id);
        $forged->business_id = $b->id;
        try {
            $this->manager->activate($b, $forged);
            $this->fail('a forged in-memory business_id must not win');
        } catch (FormRuleException) {
            $this->assertSame(FormLifecycleState::Draft, $formA->fresh()->lifecycle_state);
        }
    }

    public function test_the_lifecycle_moves_draft_active_inactive_and_back(): void
    {
        [, $business] = $this->formsTenant();
        $form = $this->makeForm($business);

        try {
            $this->manager->deactivate($business, $form);
            $this->fail('a draft has nothing to switch off');
        } catch (FormRuleException) {
            $this->assertSame(FormLifecycleState::Draft, $form->fresh()->lifecycle_state);
        }

        $active = $this->manager->activate($business, $form);
        $this->assertSame(FormLifecycleState::Active, $active->lifecycle_state);
        $this->assertNotNull($active->activated_at);
        $this->assertSame(FormLifecycleState::Active, $this->manager->activate($business, $form)->lifecycle_state, 'idempotent');

        $this->assertSame(FormLifecycleState::Inactive, $this->manager->deactivate($business, $form)->lifecycle_state);
        $this->assertSame(FormLifecycleState::Inactive, $this->manager->deactivate($business, $form)->lifecycle_state, 'idempotent');
        $this->assertSame(FormLifecycleState::Active, $this->manager->activate($business, $form)->lifecycle_state);
    }

    public function test_one_form_is_offered_at_many_locations_without_duplicating_the_definition(): void
    {
        [, $business] = $this->formsTenant();
        $one = $this->formsLocation($business, 'Downtown');
        $two = $this->formsLocation($business, 'Uptown');
        $form = $this->makeForm($business, [], true);

        $a = $this->deploy($business, $form, $one);
        $b = $this->deploy($business, $form, $two);

        $this->assertNotSame($a->uid, $b->uid);
        $this->assertSame(1, Form::where('business_id', $business->id)->count(), 'no per-Location copy of the form');
        $this->assertSame(2, FormDeployment::where('form_id', $form->id)->count());

        // Idempotent: enabling again is the same row.
        $this->assertSame($a->id, $this->deploy($business, $form, $one)->id);
        $this->assertSame(2, FormDeployment::where('form_id', $form->id)->count());
    }

    public function test_a_foreign_location_can_never_be_bound(): void
    {
        [, $business] = $this->formsTenant();
        [, $other] = $this->formsTenant(name: 'Other Studio');
        $foreign = $this->formsLocation($other, 'Elsewhere');
        $form = $this->makeForm($business, [], true);

        try {
            $this->manager->setDeployment($business, $form, $foreign, true);
            $this->fail('a Location of another Business must be refused');
        } catch (FormRuleException) {
            $this->assertSame(0, FormDeployment::count());
        }

        // The caller's Location model is not trusted either: forging its
        // business_id in memory changes nothing.
        $forged = $foreign->replicate();
        $forged->id = $foreign->id;
        $forged->business_id = $business->id;
        $forged->exists = true;
        try {
            $this->manager->setDeployment($business, $form, $forged, true);
            $this->fail('a forged in-memory business_id must not bind a foreign Location');
        } catch (FormRuleException) {
            $this->assertSame(0, FormDeployment::count());
        }
    }

    public function test_an_archived_location_cannot_newly_offer_a_form_but_can_always_be_switched_off(): void
    {
        [, $business] = $this->formsTenant();
        $location = $this->formsLocation($business);
        $form = $this->makeForm($business, [], true);
        $deployment = $this->deploy($business, $form, $location);

        $location->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived, 'archived_at' => now()])->save();

        try {
            $this->manager->setDeployment($business, $form, $location->fresh(), true);
            $this->fail('re-enabling at an archived Location must be refused');
        } catch (FormRuleException) {
            $this->assertTrue((bool) $deployment->fresh()->is_enabled);
        }

        $this->manager->setDeployment($business, $form, $location->fresh(), false);
        $this->assertFalse((bool) $deployment->fresh()->is_enabled);

        $fresh = $this->makeForm($business, [], true);
        $this->expectException(FormRuleException::class);
        $this->manager->setDeployment($business, $fresh, $location->fresh(), true);
    }

    public function test_switching_off_a_deployment_that_never_existed_is_a_no_op(): void
    {
        [, $business] = $this->formsTenant();
        $location = $this->formsLocation($business);
        $form = $this->makeForm($business);

        $this->assertNull($this->manager->setDeployment($business, $form, $location, false));
        $this->assertSame(0, FormDeployment::count());
    }
}
