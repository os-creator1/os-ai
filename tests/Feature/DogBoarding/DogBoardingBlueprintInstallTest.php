<?php

namespace Tests\Feature\DogBoarding;

use App\Enums\Automation\Workflow\WorkflowVersionState;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\BusinessVertical;
use App\Models\CrmPipeline;
use App\Models\Form;
use App\Models\NicheBlueprintVersion;
use App\Models\QuestionnaireDefinition;
use App\Models\WebsiteTemplate;
use Database\Seeders\DogBoardingWebsiteSetupQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acquisition\Support\InstallsEducationNiches;
use Tests\TestCase;

class DogBoardingBlueprintInstallTest extends TestCase
{
    use InstallsEducationNiches;
    use RefreshDatabase;

    public function test_the_niche_is_a_selectable_active_vertical_with_a_template_and_a_questionnaire(): void
    {
        $this->seedNiche('dog_boarding');

        $vertical = BusinessVertical::query()->where('key', 'dog_boarding')->firstOrFail();
        $this->assertTrue($vertical->is_active);
        $this->assertSame('Dog Boarding', $vertical->display_name);

        $this->assertSame(3, WebsiteTemplate::query()->where('niche_key', 'dog_boarding')->where('is_active', true)->count());
        $definition = QuestionnaireDefinition::query()->where('niche_key', 'dog_boarding')->firstOrFail();
        $this->assertNotNull($definition->publishedVersion());
    }

    public function test_install_creates_the_stay_request_pipeline_forms_and_workflow_drafts(): void
    {
        $this->seedNiche('dog_boarding');
        [, $business] = $this->businessInstalledFrom('dog_boarding');

        $records = $this->installationRecords($business);
        $states = $records->map(fn ($r) => $r->component_key.'='.$r->state->value.' '.$r->error_code)->values()->all();

        foreach (['dogboard_pipeline_stays', 'dogboard_form_stay_request', 'dogboard_form_meet_greet', 'dogboard_wf_new_inquiry', 'dogboard_wf_booking_confirmed'] as $key) {
            $this->assertSame('installed', $records->get($key)?->state->value, $key.' => '.json_encode($states));
        }

        $pipeline = CrmPipeline::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('Stay Requests', $pipeline->name);
        $this->assertSame(
            ['New Inquiry', 'Awaiting Pet Details', 'Meet & Greet / Assessment', 'Pending Approval', 'Booking Confirmed', 'Stay Completed'],
            $pipeline->stages->sortBy('position')->pluck('name')->values()->all(),
        );
        $this->assertSame('new_inquiry', $pipeline->stages->sortBy('position')->first()->semantic_key);

        $form = Form::query()->where('business_id', $business->id)->where('name', 'Request a Stay')->firstOrFail();
        $this->assertSame($pipeline->id, $form->currentVersion()->opportunity_pipeline_id);
        $this->assertStringContainsString('not a confirmed booking', json_encode($form->currentVersion()->toArray()));
    }

    public function test_every_workflow_is_a_draft_and_nothing_is_published_or_sent(): void
    {
        $this->seedNiche('dog_boarding');
        [, $business] = $this->businessInstalledFrom('dog_boarding');

        $workflows = AutomationWorkflow::query()->where('business_id', $business->id)->get();
        $this->assertGreaterThanOrEqual(7, $workflows->count());

        foreach ($workflows as $workflow) {
            $states = AutomationWorkflowVersion::query()->where('workflow_id', $workflow->getKey())->pluck('state')->map(fn ($s) => $s instanceof \BackedEnum ? $s->value : $s)->unique()->all();
            $this->assertSame([WorkflowVersionState::Draft->value], $states, $workflow->name.' must stay a draft until the owner publishes it');
        }

        $this->assertContains('Pending Approval Review', $workflows->pluck('name')->all());
    }

    public function test_the_pending_approval_workflow_never_messages_the_customer(): void
    {
        $steps = collect(\App\Library\NicheBlueprint\Niches\DogBoardingBlueprint::workflows())
            ->firstWhere('key', 'dogboard_wf_pending_approval')['payload']['steps'];

        $this->assertSame(['internal_notification'], array_values(array_unique(array_column($steps, 'type'))));
    }

    public function test_no_workflow_message_claims_availability_or_a_confirmed_stay_before_the_owner_confirms(): void
    {
        foreach (\App\Library\NicheBlueprint\Niches\DogBoardingBlueprint::workflows() as $component) {
            if ($component['key'] === 'dogboard_wf_booking_confirmed') {
                continue;
            }

            foreach ($component['payload']['steps'] as $step) {
                foreach (['body', 'subject'] as $field) {
                    $text = strtolower((string) ($step[$field] ?? ''));
                    $this->assertStringNotContainsString('your stay is confirmed', str_replace('not confirmed', '', $text), $component['key']);
                    $this->assertStringNotContainsString('we have availability', $text);
                    $this->assertStringNotContainsString('spots available', $text);
                }
            }
        }
    }

    public function test_every_customer_message_is_preceded_by_an_opt_out_check_and_a_send_window_after_any_wait(): void
    {
        foreach (\App\Library\NicheBlueprint\Niches\DogBoardingBlueprint::workflows() as $component) {
            $checked = false;
            foreach ($component['payload']['steps'] as $step) {
                if (in_array($step['type'], ['wait', 'wait_for_send_window'], true)) {
                    $checked = false;
                } elseif ($step['type'] === 'end_if_unsubscribed') {
                    $checked = true;
                } elseif (in_array($step['type'], ['send_email', 'send_sms'], true)) {
                    $this->assertTrue($checked, $component['key'].' sends without an opt-out check');
                }
            }
        }
    }

    public function test_reinstalling_does_not_duplicate_pipelines_forms_or_workflows(): void
    {
        $this->seedNiche('dog_boarding');
        [, $business] = $this->businessInstalledFrom('dog_boarding');

        $count = fn () => [
            CrmPipeline::query()->where('business_id', $business->id)->count(),
            Form::query()->where('business_id', $business->id)->count(),
            AutomationWorkflow::query()->where('business_id', $business->id)->count(),
        ];
        $before = $count();

        app(NicheBlueprintInstaller::class)->installForBusiness($business->fresh());
        app(NicheBlueprintInstaller::class)->installForBusiness($business->fresh());

        $this->assertSame($before, $count());
        $this->assertSame(1, $before[0]);
    }

    public function test_seeding_twice_keeps_one_published_blueprint_version_and_one_questionnaire_version(): void
    {
        $this->seedNiche('dog_boarding');
        $this->artisan('blueprint:seed-niche', ['niche' => 'dog_boarding'])->assertExitCode(0);
        (new DogBoardingWebsiteSetupQuestionnaireSeeder())->run();

        $this->assertSame(1, NicheBlueprintVersion::query()->count());
        $definition = QuestionnaireDefinition::query()->where('niche_key', 'dog_boarding')->firstOrFail();
        $this->assertSame(1, $definition->versions()->count());
    }

    public function test_a_second_business_receives_its_own_records_and_never_the_first_ones(): void
    {
        $this->seedNiche('dog_boarding');
        [, $first] = $this->businessInstalledFrom('dog_boarding');
        [, $second] = $this->businessInstalledFrom('dog_boarding');

        $firstIds = CrmPipeline::query()->where('business_id', $first->id)->pluck('id')->all();
        $secondIds = CrmPipeline::query()->where('business_id', $second->id)->pluck('id')->all();

        $this->assertCount(1, $firstIds);
        $this->assertCount(1, $secondIds);
        $this->assertSame([], array_intersect($firstIds, $secondIds));
        $this->assertSame(0, Form::query()->where('business_id', $second->id)->whereIn('id', Form::query()->where('business_id', $first->id)->pluck('id'))->count());
    }

    public function test_the_meet_and_greet_booking_type_waits_for_a_location_instead_of_inventing_one(): void
    {
        $this->seedNiche('dog_boarding');
        [, $business] = $this->businessInstalledFrom('dog_boarding');

        $record = $this->installationRecords($business)->get('dogboard_booking_meet_greet');
        $this->assertSame('failed', $record->state->value, 'A booking type belongs to a Location; with none the component is a retryable failure, never a fake one.');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('booking_types')->count());

        // The rest of the niche still installed: a missing Location never blocks the request workflow.
        $this->assertSame('installed', $this->installationRecords($business)->get('dogboard_form_stay_request')->state->value);
    }

    public function test_once_a_location_exists_the_booking_type_installs_inactive_and_never_publicly_bookable(): void
    {
        $this->seedNiche('dog_boarding');
        [, $business] = $this->businessInstalledFrom('dog_boarding');
        \App\Models\BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);

        app(NicheBlueprintInstaller::class)->installForBusiness($business->fresh());

        $this->assertSame('installed', $this->installationRecords($business)->get('dogboard_booking_meet_greet')->state->value);
        $type = \Illuminate\Support\Facades\DB::table('booking_types')->first();
        $this->assertNotNull($type);
        $this->assertSame('Meet & greet', $type->name);
        $this->assertSame(0, (int) $type->is_active, 'Installed inactive: it is bookable only after the owner reviews it, adds staff and activates it.');
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('booking_types')->count());
    }
}
