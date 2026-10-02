<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations Location run-scope — the builder's one control, through the real
 * routes: the picker carries only this Business's Locations, a draft naming a
 * foreign one cannot publish, and a valid scope publishes and is reported.
 */
class LocationBuilderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use CallsWorkflowRoutes;

    private function location(Business $business, string $name): BusinessLocation
    {
        return BusinessLocation::create(['business_id' => $business->id, 'name' => $name, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    /** @return array<string, mixed> */
    private function builderData(string $html): array
    {
        preg_match('#<script type="application/json" id="wf-builder-data">(.*?)</script>#s', $html, $match);
        $this->assertNotEmpty($match);

        return json_decode(html_entity_decode($match[1]), true);
    }

    private function draftWithScope(array $tenant, mixed $locationId, int $expectStatus = 200): AutomationWorkflow
    {
        $created = $this->callJson('POST', $this->routeUrl('store', $tenant['workspace'], $tenant['business']), ['name' => 'Scoped flow', 'trigger_type' => 'manual_enrollment'])->assertCreated();
        $workflow = AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();
        $draft = $this->callJson('GET', $this->routeUrl('draft.show', $tenant['workspace'], $tenant['business'], $workflow))->assertOk()->json();

        $definition = $draft['definition'];
        $definition['root']['config']['business_location_id'] = $locationId;
        $definition['root']['next'] = [$this->endStep()];

        $this->callJson('PUT', $this->routeUrl('draft.autosave', $tenant['workspace'], $tenant['business'], $workflow), [
            'definition' => $definition,
            'definition_revision' => $draft['revision'],
        ])->assertStatus($expectStatus);

        return $workflow;
    }

    public function test_the_picker_carries_only_this_businesss_locations_in_the_one_catalog_read(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $mine = $this->location($t['business'], 'Downtown');
        $closed = $this->location($t['business'], 'Closed');
        DB::table('business_locations')->where('id', $closed->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);

        $other = $this->tenantWithWorkflow();
        $theirs = $this->location($other['business'], 'Their Location');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get($this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']))->assertOk()->getContent();
        $reads = array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => str_contains($sql, 'from `business_locations`'));
        DB::disableQueryLog();

        $catalog = $this->builderData($html)['catalogs']['locations'];

        $this->assertEqualsCanonicalizing([(int) $mine->id, (int) $closed->id], array_column($catalog, 'id'));
        $this->assertNotContains((int) $theirs->id, array_column($catalog, 'id'));
        $this->assertSame(['id', 'name', 'active'], array_keys($catalog[0]), 'Ids, names and the lifecycle flag only.');
        $this->assertFalse((bool) collect($catalog)->firstWhere('id', $closed->id)['active'], 'An archived Location is flagged so the picker can hide it.');
        $this->assertCount(1, array_filter($reads, fn (string $sql) => str_contains($sql, 'union all')), 'Locations ride the one reference-catalog statement.');

        preg_match('#<template id="wf-node-form-trigger">(.*?)</template>#s', $html, $trigger);
        $this->assertStringContainsString('data-role="wf-location-scope-select"', $trigger[1]);
        $this->assertStringContainsString('Where it applies', $trigger[1]);
    }

    public function test_a_draft_naming_another_businesss_location_cannot_publish(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $other = $this->tenantWithWorkflow();
        $theirs = $this->location($other['business'], 'Their Location');

        // Forged through the route: refused at save, so it never reaches the draft.
        $workflow = $this->draftWithScope($t, (int) $theirs->id, 422);
        $this->assertNull($workflow->fresh()->draftVersion()->definition['root']['config']['business_location_id'] ?? null);

        // Forged straight into storage: the compiler still names it, and publish still refuses.
        $draft = $workflow->fresh()->draftVersion();
        $definition = $draft->definition;
        $definition['root']['config']['business_location_id'] = (int) $theirs->id;
        $definition['root']['next'] = [$this->endStep()];
        DB::table('automation_workflow_versions')->where('id', $draft->id)->update(['definition' => json_encode($definition)]);

        // A scope that is not even this Business's can still be opened (and fixed or
        // discarded) by an actor who reaches every Location, so it is never stranded.
        $shown = $this->callJson('GET', $this->routeUrl('draft.show', $t['workspace'], $t['business'], $workflow))->assertOk()->json();
        $this->assertContains('That location does not belong to this business.', collect($shown['errors'])->flatten()->all());

        $this->callJson('POST', $this->routeUrl('publish', $t['workspace'], $t['business'], $workflow))->assertStatus(422);

        $this->assertNull($workflow->fresh()->published_version_id);
    }

    public function test_a_valid_scope_publishes_and_is_reported_by_settings_and_the_list(): void
    {
        $t = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);
        $downtown = $this->location($t['business'], 'Downtown');

        $workflow = $this->draftWithScope($t, (int) $downtown->id);

        $this->callJson('POST', $this->routeUrl('publish', $t['workspace'], $t['business'], $workflow))->assertOk()->assertJsonPath('status', 'published');

        $this->callJson('GET', $this->routeUrl('settings', $t['workspace'], $t['business'], $workflow))
            ->assertOk()
            ->assertJsonPath('published.business_location_id', (int) $downtown->id);

        $row = collect($this->callJson('GET', $this->routeUrl('index', $t['workspace'], $t['business']))->assertOk()->json('workflows'))->firstWhere('uid', $workflow->uid);
        $this->assertSame('Downtown', $row['scope_location_name']);
    }
}
