<?php

namespace Tests\Feature\Automations\Workflow\Http;

use App\Library\Crm\TagManager;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations x merged foundations — through the real Builder routes.
 *
 * The page offers the new triggers and steps in customer words and hands the
 * inspector only this Business's tags and forms; a draft naming another
 * Business's tag cannot publish; a valid one does; and Test workflow on a draft
 * with the new steps writes nothing.
 */
class WorkflowFoundationBuilderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use CallsWorkflowRoutes;

    /** @return array<string, mixed> */
    private function builderData(string $html): array
    {
        preg_match('#<script type="application/json" id="wf-builder-data">(.*?)</script>#s', $html, $match);
        $this->assertNotEmpty($match, 'The builder bootstrap blob must be rendered.');

        return json_decode(html_entity_decode($match[1]), true);
    }

    private function form(Business $business, string $name): int
    {
        return (int) DB::table('forms')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'name' => $name,
            'lifecycle_state' => 'active',
            'current_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A draft on `$trigger` whose trigger config and steps are given. */
    private function draft(array $tenant, string $trigger, array $triggerConfig, array $steps): AutomationWorkflow
    {
        $created = $this->callJson('POST', $this->routeUrl('store', $tenant['workspace'], $tenant['business']), ['name' => 'Foundation flow', 'trigger_type' => $trigger])->assertCreated();
        $workflow = AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();
        $draft = $this->callJson('GET', $this->routeUrl('draft.show', $tenant['workspace'], $tenant['business'], $workflow))->assertOk()->json();

        $definition = $draft['definition'];
        $definition['root']['config'] = array_merge($definition['root']['config'], $triggerConfig);
        $definition['root']['next'] = $steps;

        $this->callJson('PUT', $this->routeUrl('draft.autosave', $tenant['workspace'], $tenant['business'], $workflow), [
            'definition' => $definition,
            'definition_revision' => $draft['revision'],
        ])->assertOk();

        return $workflow;
    }

    private function step(string $type, array $config): array
    {
        return ['key' => (string) Str::uuid(), 'type' => $type, 'config' => $config];
    }

    public function test_the_builder_offers_the_new_triggers_and_steps_in_customer_words(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);

        $html = $this->get($this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']))->assertOk()->getContent();

        preg_match('#<template id="wf-node-form-trigger">(.*?)</template>#s', $html, $trigger);
        $this->assertNotEmpty($trigger);

        foreach ([
            'contact_tag_added' => 'Tag added to a contact',
            'contact_tag_removed' => 'Tag removed from a contact',
            'form_submitted' => 'Form submitted',
            'appointment_scheduled' => 'Appointment booked',
            'appointment_cancelled' => 'Appointment cancelled',
            'appointment_rescheduled' => 'Appointment rescheduled',
        ] as $value => $words) {
            $this->assertStringContainsString('name="wf-trigger-type" value="' . $value . '"', $trigger[1], $value);
            $this->assertStringContainsString($words, $trigger[1], $value);
        }

        foreach (['wf-tag-filter-select', 'wf-form-filter-select'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $trigger[1]);
        }

        // Payments, documents and proposals are other lanes: not offered.
        foreach (['payment', 'invoice', 'proposal', 'contract_signed'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $trigger[1]);
        }

        foreach (['send_email' => 'data-field="subject"', 'add_tag' => 'data-field="tag_id"', 'remove_tag' => 'data-field="tag_id"'] as $type => $field) {
            $this->assertMatchesRegularExpression('#<template id="wf-node-form-' . $type . '">(.*?)</template>#s', $html, $type);
            preg_match('#<template id="wf-node-form-' . $type . '">(.*?)</template>#s', $html, $form);
            $this->assertStringContainsString($field, $form[1], $type);
        }

        // The email form stores no sender, address or Location.
        preg_match('#<template id="wf-node-form-send_email">(.*?)</template>#s', $html, $email);
        foreach (['account', 'provider', 'from', 'to_email', 'location'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase('data-field="' . $forbidden, $email[1]);
        }

        $this->assertStringContainsString('data-role="wf-condition-tag-group"', $html, 'If / Else offers the Business\'s tags as a subject group.');
        $this->assertDoesNotMatchRegularExpression('/ContactTag(Added|Removed)|FormSubmissionRecorded|Appointment(Scheduled|Cancelled|Rescheduled)/', strip_tags($html), 'No internal event class is ever shown.');
    }

    public function test_the_pickers_carry_only_this_businesss_tags_and_forms_in_one_catalog_read(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $mine = app(TagManager::class)->createTag($t['business'], 'VIP');
        $archived = app(TagManager::class)->createTag($t['business'], 'Old');
        app(TagManager::class)->archiveTag($t['business'], $archived);
        $myForm = $this->form($t['business'], 'Contact us');

        $other = $this->tenantWithWorkflow();
        $theirs = app(TagManager::class)->createTag($other['business'], 'Theirs');
        $theirForm = $this->form($other['business'], 'Their form');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get($this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']))->assertOk()->getContent();
        $reads = array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => str_contains($sql, 'from `tags`') || str_contains($sql, 'from `forms`'));
        DB::disableQueryLog();

        $catalogs = $this->builderData($html)['catalogs'];

        $this->assertEqualsCanonicalizing([(int) $mine->id, (int) $archived->id], array_column($catalogs['tags'], 'id'));
        $this->assertNotContains((int) $theirs->id, array_column($catalogs['tags'], 'id'));
        $this->assertSame([$myForm], array_column($catalogs['forms'], 'id'));
        $this->assertNotContains($theirForm, array_column($catalogs['forms'], 'id'));
        $this->assertSame(['id', 'name', 'archived'], array_keys($catalogs['tags'][0]), 'Ids, names and the archive flag only.');
        $this->assertSame(['id', 'name', 'lifecycle'], array_keys($catalogs['forms'][0]));
        $this->assertTrue((bool) collect($catalogs['tags'])->firstWhere('id', $archived->id)['archived'], 'An archived tag is flagged, so the picker can hide it.');

        $this->assertCount(1, $reads, 'Tags and forms cost no query of their own: they ride the one reference-catalog statement.');
    }

    public function test_a_draft_naming_another_businesss_tag_or_form_cannot_publish(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $other = $this->tenantWithWorkflow();
        $foreignTag = app(TagManager::class)->createTag($other['business'], 'Theirs');
        $foreignForm = $this->form($other['business'], 'Their form');

        $tagWorkflow = $this->draft($t, 'contact_tag_added', ['tag_id' => (int) $foreignTag->id], [$this->step('add_tag', ['tag_id' => (int) $foreignTag->id]), $this->endStep()]);
        $formWorkflow = $this->draft($t, 'form_submitted', ['form_id' => $foreignForm], [$this->endStep()]);

        $draft = $this->callJson('GET', $this->routeUrl('draft.show', $t['workspace'], $t['business'], $tagWorkflow))->assertOk()->json();
        $this->assertContains('That tag does not belong to this business.', collect($draft['errors'])->flatten()->all());

        $this->callJson('POST', $this->routeUrl('publish', $t['workspace'], $t['business'], $tagWorkflow))
            ->assertStatus(422)
            ->assertJsonFragment(['That tag does not belong to this business.']);
        $this->callJson('POST', $this->routeUrl('publish', $t['workspace'], $t['business'], $formWorkflow))
            ->assertStatus(422)
            ->assertJsonFragment(['That form does not belong to this business.']);

        $this->assertNull($tagWorkflow->fresh()->published_version_id);
        $this->assertNull($formWorkflow->fresh()->published_version_id);
    }

    public function test_valid_foundation_workflows_publish_through_the_route(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $tag = app(TagManager::class)->createTag($t['business'], 'VIP');
        $form = $this->form($t['business'], 'Contact us');

        $workflow = $this->draft($t, 'form_submitted', ['form_id' => $form], [
            $this->step('send_email', ['subject' => 'Thanks {first_name}', 'body' => 'We got your form.']),
            $this->step('add_tag', ['tag_id' => (int) $tag->id]),
            $this->step('remove_tag', ['tag_id' => (int) $tag->id]),
            $this->endStep(),
        ]);

        $this->callJson('POST', $this->routeUrl('publish', $t['workspace'], $t['business'], $workflow))
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $this->assertSame(
            ['trigger', 'send_email', 'add_tag', 'remove_tag', 'end'],
            DB::table('automation_workflow_nodes')->where('version_id', $workflow->fresh()->published_version_id)->orderBy('id')->pluck('node_type')->all(),
        );
    }

    public function test_test_workflow_on_the_new_steps_writes_nothing(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $tag = app(TagManager::class)->createTag($t['business'], 'VIP');

        $workflow = $this->draft($t, 'contact_tag_added', ['tag_id' => (int) $tag->id], [
            $this->step('send_email', ['subject' => 'Hello', 'body' => 'There']),
            $this->step('add_tag', ['tag_id' => (int) $tag->id]),
            $this->endStep(),
        ]);

        $counts = fn (): array => [
            'contact_tags' => DB::table('contact_tags')->count(),
            'business_email_messages' => DB::table('business_email_messages')->count(),
            'automation_enrollments' => DB::table('automation_enrollments')->count(),
            'automation_step_runs' => DB::table('automation_step_runs')->count(),
        ];
        $before = $counts();

        $body = $this->callJson('POST', $this->routeUrl('simulate', $t['workspace'], $t['business'], $workflow), ['contact_uid' => $t['contact']->uid])
            ->assertOk()
            ->json();

        $this->assertSame(['trigger', 'send_email', 'add_tag', 'end'], array_column($body['path'], 'type'));
        $this->assertSame([], collect($body['validation'])->flatten()->all());
        $this->assertSame($before, $counts(), 'No tag, email, enrollment or step run is written.');
    }
}
