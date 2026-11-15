<?php

namespace Tests\Feature\Forms;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * The MotionGrove Forms redesign: the six redesigned screens render from real
 * records, the new corner option round-trips through the versioned definition, and
 * the Notifications cards create only a DRAFT automation pointed at the form.
 */
class FormsRedesignTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private $owner;

    private Business $business;

    private $workspace;

    private BusinessLocation $downtown;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->formsPipeline($this->business);
        $this->authenticateAs($this->owner);
    }

    private function url(string $name, array $extra = []): string
    {
        return $this->formsRoute($name, $this->workspace, $this->business, $extra);
    }

    public function test_the_forms_list_carries_the_new_form_dialog_and_the_fallback_page_still_works(): void
    {
        $form = $this->makeForm($this->business, [], true);

        $this->get($this->url('index'))->assertOk()
            ->assertSee('data-role="forms-new-dialog"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('Create &amp; open builder', false)
            ->assertSee('Contact me')->assertSee('Check availability')->assertSee('Blank')
            ->assertSee('1 form')->assertSee('0 responses');

        $this->get($this->url('create'))->assertOk()
            ->assertSee('data-role="forms-create-form"', false)
            ->assertDontSee('role="dialog"', false);

        $response = $this->post($this->url('store'), ['name' => 'Brand new', 'starter' => 'availability']);
        $created = Form::query()->where('business_id', $this->business->id)->where('name', 'Brand new')->firstOrFail();
        $response->assertRedirect($this->url('edit', [$created->uid]));
        $this->assertFalse($created->isActive(), 'a form made from the dialog is a draft');
        $this->assertContains('event_date', array_column($created->currentVersion()->fields, 'key'));
        $this->assertNotNull($form);
    }

    public function test_a_blank_name_reopens_the_dialog_with_the_refusal(): void
    {
        $this->from($this->url('index'))->post($this->url('store'), ['name' => '', 'starter' => 'blank'])
            ->assertRedirect($this->url('index'))->assertSessionHasErrors('name');

        $this->followingRedirects()->from($this->url('index'))->post($this->url('store'), ['name' => '', 'starter' => 'blank'])
            ->assertSee('data-open="1"', false);
    }

    public function test_the_pill_corner_option_is_stored_in_the_versioned_definition_and_rendered(): void
    {
        [$form, $deployment] = (function () {
            $form = $this->makeForm($this->business, [], true);

            return [$form, $this->deploy($this->business, $form, $this->downtown)];
        })();

        $document = $this->builderDocumentFor($form);
        $document['design'] = ['radius' => 'pill', 'accent' => '#b4514a'];

        $this->postJson($this->url('builder.save', [$form->uid]), $document)->assertOk()->assertJson(['status' => 'saved']);
        $this->assertSame('pill', $form->fresh()->currentVersion()->style()['radius']);

        $this->get(route('public.forms.show', [$deployment->uid]))->assertOk()->assertSee('--pf-radius:999px', false);

        $document = $this->builderDocumentFor($form);
        $document['design'] = ['radius' => 'blob'];
        $this->postJson($this->url('builder.save', [$form->uid]), $document)->assertStatus(422);
    }

    public function test_the_settings_pane_exposes_the_real_controls_and_live_preview(): void
    {
        $form = $this->makeForm($this->business);

        $this->get($this->url('edit', [$form->uid]))->assertOk()->assertSee('Offer here', false)->assertDontSee('Copy embed code');

        $this->deploy($this->business, $form, $this->downtown);

        $this->get($this->url('edit', [$form->uid]))->assertOk()
            ->assertSee('data-bind="intro"', false)->assertSee('data-bind="success_message"', false)
            ->assertSee('data-design-seg="radius"', false)->assertSee('data-v="pill"', false)
            ->assertSee('id="fb-s-opp"', false)->assertSee('data-role="forms-settings-preview"', false)
            ->assertSee('data-role="forms-activate"', false)
            ->assertSee('Stop offering here', false)->assertSee('Copy embed code')->assertSee('Copy link');
    }

    public function test_all_responses_page_is_empty_then_populated_and_distinct_from_the_form_tab(): void
    {
        $form = $this->makeForm($this->business, ['name' => 'Gallery inquiry'], true);
        $other = $this->makeForm($this->business, ['name' => 'Second form'], true);
        $deployment = $this->deploy($this->business, $form, $this->downtown);

        $this->get($this->url('submissions.index'))->assertOk()
            ->assertSee('Form responses')->assertSee('No responses yet')->assertSee('Go to your forms')
            ->assertSee('Gallery inquiry')->assertSee('Second form');

        app(\App\Library\Forms\FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, ['Your name' => 'Ada Lovelace', 'Phone' => '5557654321']));

        $all = $this->get($this->url('submissions.index'))->assertOk();
        $all->assertDontSee('No responses yet')->assertSee('Ada Lovelace')->assertSee('Gallery inquiry · Downtown', false)->assertSee('data-role="forms-submissions"', false);

        $this->get($this->url('submissions.index').'?form='.$form->uid)->assertOk()
            ->assertSee('Ada Lovelace')->assertSee('data-role="forms-tabs"', false)->assertDontSee('Form responses');
        $this->get($this->url('submissions.index').'?form='.$other->uid)->assertOk()
            ->assertDontSee('Ada Lovelace')->assertSee('No responses yet')->assertSee('Choose where to offer it');
    }

    public function test_the_form_submissions_empty_state_offers_a_copy_link_only_for_a_live_link(): void
    {
        $form = $this->makeForm($this->business, [], true);
        $deployment = $this->deploy($this->business, $form, $this->downtown);

        $this->get($this->url('submissions.index').'?form='.$form->uid)->assertOk()
            ->assertSee('data-role="forms-copy-link"', false)
            ->assertSee(route('public.forms.show', [$deployment->uid]), false)
            ->assertDontSee('Export');

        $deployment->forceFill(['is_enabled' => false])->save();

        $this->get($this->url('submissions.index').'?form='.$form->uid)->assertOk()
            ->assertDontSee('data-role="forms-copy-link"', false)->assertSee('Choose where to offer it');
    }

    public function test_analytics_empty_then_populated_uses_only_recorded_numbers(): void
    {
        $form = $this->makeForm($this->business, [], true);
        $deployment = $this->deploy($this->business, $form, $this->downtown);

        $this->get($this->url('analytics', [$form->uid]))->assertOk()
            ->assertSee('Your chart starts with the first response')
            ->assertSee('Linked to a contact')->assertSee('Became an opportunity')->assertSee('By location')->assertSee('Downtown');

        app(\App\Library\Forms\FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment));

        $page = $this->get($this->url('analytics', [$form->uid]))->assertOk();
        $page->assertDontSee('Your chart starts with the first response')->assertSee('data-role="forms-analytics-chart"', false);
        $this->assertSame(1, preg_match('#data-role="stat-total">(\d+)<#', $page->getContent(), $m));
        $this->assertSame('1', $m[1]);
    }

    public function test_a_notification_card_creates_only_a_draft_automation_for_this_form(): void
    {
        $form = $this->makeForm($this->business, ['name' => 'Gallery inquiry'], true);

        $this->get($this->url('notifications', [$form->uid]))->assertOk()
            ->assertSee('Notify my team')->assertSee('Text them back')->assertSee('Email them back')
            ->assertSee('data-role="forms-notifications-none"', false);

        $response = $this->post($this->url('notifications.start', [$form->uid, 'team']));

        $workflow = AutomationWorkflow::query()->where('business_id', $this->business->id)->firstOrFail();
        $response->assertRedirect(route('customer.workspaces.businesses.automations.workflows.show', [$this->workspace->uid, $this->business->uid, $workflow->uid]));

        $this->assertSame('draft', $workflow->status->value, 'nothing is enabled before it is published');
        $this->assertNull($workflow->published_version_id);

        $draft = AutomationWorkflowVersion::query()->where('workflow_id', $workflow->id)->where('state', 'draft')->firstOrFail();
        $root = $draft->definition['root'];
        $this->assertSame('form_submitted', $root['config']['trigger_type']);
        $this->assertSame($form->id, $root['config']['form_id']);
        $this->assertSame('internal_notification', $root['next'][0]['type']);

        $this->get($this->url('notifications', [$form->uid]))->assertOk()->assertSee('data-role="forms-notifications-none"', false);
    }

    public function test_a_notification_card_for_an_unknown_action_or_foreign_form_is_a_404_and_creates_nothing(): void
    {
        $form = $this->makeForm($this->business, [], true);
        [, $foreign] = $this->formsTenant(\App\Enums\Entitlement\WorkspacePlanTier::Core, 'Other Studio');
        $foreignForm = $this->makeForm($foreign, [], true);

        $this->post($this->url('notifications.start', [$form->uid, 'carrier-pigeon']))->assertNotFound();
        $this->post($this->url('notifications.start', [$foreignForm->uid, 'team']))->assertNotFound();

        $this->assertSame(0, AutomationWorkflow::query()->count());
    }

    public function test_a_text_card_is_refused_with_the_reason_when_messaging_is_not_set_up(): void
    {
        $form = $this->makeForm($this->business, [], true);

        $this->post($this->url('notifications.start', [$form->uid, 'sms']))->assertSessionHasErrors('forms');

        $this->assertSame(0, AutomationWorkflow::query()->count(), 'a channel that cannot send never gets a workflow');
    }
}
