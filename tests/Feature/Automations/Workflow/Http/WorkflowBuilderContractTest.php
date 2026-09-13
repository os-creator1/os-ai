<?php

namespace Tests\Feature\Automations\Workflow\Http;

use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Models\ContactGroupFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-E — the contract V2-D's merged builder actually consumes.
 *
 * WHY THIS FILE EXISTS. V2-D landed while V2-E was in progress, and a clean
 * textual merge hid a real break: this slice's first draft wrapped every response
 * in a `data` envelope, while resources/js/automations/workflow-builder reads
 * `body.revision`, `body.errors`, `body.path` and `body.workflow.uid` at the top
 * level — exactly the flat shapes §20.2 fixes. With the envelope, the builder
 * would have shown no validation errors after an autosave and enabled Publish on
 * an invalid document, without a single failing test.
 *
 * So each assertion below names the JavaScript that reads the field it pins. If
 * either side changes, this is where it should fail.
 */
class WorkflowBuilderContractTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use CallsWorkflowRoutes;

    /** @return array<string, mixed> */
    private function signedInTenant(): array
    {
        $tenant = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($tenant['customer']);

        return $tenant;
    }

    // ---------------------------------------------------------------
    // JSON shapes the builder reads
    // ---------------------------------------------------------------

    /** autosave.js:97 reads `body.revision`; index.js:59 reads `body.errors`. */
    public function test_autosave_answers_with_top_level_revision_and_errors(): void
    {
        $t = $this->signedInTenant();
        $draft = $this->callJson('GET', $this->routeUrl('draft.show', $t['workspace'], $t['business'], $t['workflow']))->json();

        $response = $this->callJson('PUT', $this->routeUrl('draft.autosave', $t['workspace'], $t['business'], $t['workflow']), [
            'definition' => $draft['definition'],
            'definition_revision' => $draft['revision'],
        ])->assertOk();

        $body = $response->json();

        $this->assertArrayHasKey('revision', $body, 'autosave.js reads body.revision at the top level.');
        $this->assertArrayHasKey('errors', $body, 'index.js reads body.errors at the top level.');
        $this->assertArrayNotHasKey('data', $body, 'An envelope would hide both from the builder.');
        $this->assertSame($draft['revision'] + 1, $body['revision']);
    }

    /** index.js publish handler reads `body.errors` on a 422. */
    public function test_a_failed_publish_answers_with_top_level_errors(): void
    {
        $t = $this->signedInTenant();
        $draft = $this->callJson('GET', $this->routeUrl('draft.show', $t['workspace'], $t['business'], $t['workflow']))->json();

        $key = (string) Str::uuid();
        $definition = $draft['definition'];
        $definition['root']['next'] = [['key' => $key, 'type' => 'send_sms', 'config' => ['body' => '']]];

        $this->callJson('PUT', $this->routeUrl('draft.autosave', $t['workspace'], $t['business'], $t['workflow']), [
            'definition' => $definition,
            'definition_revision' => $draft['revision'],
        ])->assertOk();

        $body = $this->callJson('POST', $this->routeUrl('publish', $t['workspace'], $t['business'], $t['workflow']))
            ->assertStatus(422)
            ->json();

        $this->assertArrayHasKey($key, $body['errors'], 'index.js renders errors[node.key].');
    }

    /** index.js Test button reads `body.path`, mapping `step.label`. */
    public function test_simulate_answers_with_a_top_level_path_of_labelled_steps(): void
    {
        $t = $this->signedInTenant();

        $body = $this->callJson('POST', $this->routeUrl('simulate', $t['workspace'], $t['business'], $t['workflow']), [
            'contact_uid' => $t['contact']->uid,
        ])->assertOk()->json();

        $this->assertIsArray($body['path'] ?? null, 'index.js reads body.path.');
        $this->assertNotEmpty($body['path']);
        $this->assertArrayHasKey('label', $body['path'][0], 'index.js maps step.label.');
        $this->assertSame($body['steps'], $body['path'], 'path is the simulator\'s own steps, not a second shape.');
    }

    /** index.js initChooser reads `body.redirect`, else `body.workflow.uid`. */
    public function test_create_answers_with_the_workflow_and_a_redirect_to_its_builder(): void
    {
        $t = $this->signedInTenant();

        $body = $this->callJson('POST', $this->routeUrl('store', $t['workspace'], $t['business']), [
            'name' => 'From the chooser',
            'trigger_type' => 'contact_created',
        ])->assertCreated()->json();

        $this->assertNotEmpty($body['workflow']['uid'] ?? null, 'initChooser reads body.workflow.uid.');
        $this->assertStringEndsWith('/automations/workflows/' . $body['workflow']['uid'], (string) ($body['redirect'] ?? ''), 'initChooser navigates to body.redirect.');
    }

    // ---------------------------------------------------------------
    // Pages a person navigates to
    // ---------------------------------------------------------------

    public function test_the_list_page_renders_this_businesss_workflows(): void
    {
        $t = $this->signedInTenant();
        $other = $this->tenantWithWorkflow();
        DB::table('automation_workflows')->where('id', $other['workflow']->id)->update(['name' => 'Someone else entirely']);

        $this->get($this->routeUrl('index', $t['workspace'], $t['business']))
            ->assertOk()
            ->assertSee('Tenant workflow')
            ->assertSee('/new', false)
            ->assertDontSee('Someone else entirely');
    }

    /** The list's "New workflow" button points at {basePath}/new. */
    public function test_the_new_workflow_page_renders_and_is_not_read_as_a_workflow_uid(): void
    {
        $t = $this->signedInTenant();

        $html = $this->get($this->routeUrl('create', $t['workspace'], $t['business']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-role="wf-chooser"', $html, 'The chooser rendered, not the builder.');

        // basePath is a relative path, which is what initChooser POSTs to.
        $basePath = route('customer.workspaces.businesses.automations.workflows.index', [$t['workspace']->uid, $t['business']->uid], false);
        $this->assertStringContainsString('data-create-url="' . $basePath . '"', $html);
    }

    /**
     * The builder shell: V2-D's view, fed one JSON blob. The catalogs in it must
     * be this Business's only, and must never offer the phone field for writing.
     */
    public function test_the_builder_page_renders_with_business_scoped_catalogs(): void
    {
        $t = $this->signedInTenant();

        $group = $this->contactGroup($t['business'], 'Members');
        $note = $this->textField($group, 'NOTE');

        $other = $this->tenantWithWorkflow();
        $foreignGroup = $this->contactGroup($other['business'], 'Their group');

        $html = $this->get($this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']))
            ->assertOk()
            ->getContent();

        preg_match('#<script type="application/json" id="wf-builder-data">(.*?)</script>#s', $html, $match);
        $this->assertNotEmpty($match, 'The builder bootstrap blob must be rendered.');

        $data = json_decode(html_entity_decode($match[1]), true);

        $this->assertSame($t['workflow']->uid, $data['workflow']['uid']);
        $this->assertIsInt($data['draft']['revision'], 'index.js hands draft.revision to autosave.');
        $this->assertStringEndsWith('/automations/workflows/' . $t['workflow']->uid, $data['basePath']);

        $groupIds = array_column($data['catalogs']['contactGroups'], 'id');
        $this->assertContains((int) $group->id, $groupIds);
        $this->assertNotContains((int) $foreignGroup->id, $groupIds, 'Another Business\'s groups must never be offered.');

        $writable = array_column($data['catalogs']['writableFields'], 'id');
        $this->assertContains((int) $note->id, $writable);

        $phoneIds = ContactGroupFields::query()->where('contact_group_id', $group->id)->where('is_phone', true)->pluck('id')->all();
        $this->assertNotEmpty($phoneIds, 'A new group ships with a phone field.');

        foreach ($phoneIds as $phoneId) {
            $this->assertNotContains((int) $phoneId, $writable, 'The phone field is never writable, so never offered.');
        }
    }

    public function test_an_archived_workflow_opens_read_only_without_a_new_draft(): void
    {
        $t = $this->signedInTenant();
        app(WorkflowLifecycle::class)->archive($t['workflow']);

        $this->get($this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']))->assertOk();

        $this->assertNull($t['workflow']->fresh()->draftVersion(), 'Viewing a finished workflow must not resurrect an editable copy.');
    }

    /** A person is shown the app's own error pages, with real statuses. */
    public function test_a_browser_denial_gets_real_statuses(): void
    {
        $t = $this->tenantWithWorkflow();
        [, , $otherWorkspace] = $this->entitledTenant();

        $this->authenticateAsCustomer($t['customer']);

        foreach (['index', 'create'] as $page) {
            $this->get($this->routeUrl($page, $otherWorkspace, $t['business']))->assertNotFound();
        }

        $this->get($this->routeUrl('show', $otherWorkspace, $t['business'], $t['workflow']))->assertNotFound();

        $this->authenticateAsCustomer($t['customer'], []);

        $this->get($this->routeUrl('index', $t['workspace'], $t['business']))->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // §18 query budgets
    // ---------------------------------------------------------------

    /**
     * Queries issued by one request.
     *
     * The FIRST request in a test pays one-time costs — session, permission and
     * config rows warming — so every measurement is preceded by an unmeasured
     * warm-up of the same URL. Without it the first reading is inflated and the
     * comparison measures warm-up rather than the endpoint.
     */
    private function queriesFor(string $method, string $url, bool $json): int
    {
        $json ? $this->json($method, $url)->assertOk() : $this->call($method, $url)->assertOk();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $json ? $this->json($method, $url)->assertOk() : $this->call($method, $url)->assertOk();

        return $count;
    }

    /** §18 — the list costs the same whatever the page size. */
    public function test_the_list_query_count_does_not_grow_with_page_size(): void
    {
        $t = $this->signedInTenant();
        $url = $this->routeUrl('index', $t['workspace'], $t['business']);

        $small = $this->queriesFor('GET', $url, true);

        for ($i = 0; $i < 12; $i++) {
            $this->publishWorkflow($t['business'], [$this->endStep()], name: 'Extra ' . $i);
        }

        // Half of them with an open draft, the field that used to be one query per row.
        foreach (\App\Models\AutomationWorkflow::query()->where('business_id', $t['business']->id)->limit(6)->get() as $w) {
            app(\App\Library\Automation\Workflow\WorkflowDraftService::class)->ensureDraft($w);
        }

        $large = $this->queriesFor('GET', $url, true);

        fwrite(STDERR, "\n[§18] workflow list JSON: {$small} queries for 1 row, {$large} for 13 rows\n");

        $this->assertLessThanOrEqual($small, $large, "The list must not grow with its page size ({$small} for 1 row, {$large} for 13).");
    }

    /** §18 — loading the builder costs the same whatever the node count. */
    public function test_the_builder_load_query_count_does_not_grow_with_node_count(): void
    {
        $t = $this->signedInTenant();

        $small = $this->queriesFor('GET', $this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']), false);

        $steps = [];
        for ($i = 0; $i < 40; $i++) {
            $steps[] = ['key' => (string) Str::uuid(), 'type' => 'send_sms', 'config' => ['body' => 'Step ' . $i]];
        }
        $steps[] = $this->endStep();
        [$big] = $this->publishWorkflow($t['business'], $steps, name: 'Forty steps');

        $large = $this->queriesFor('GET', $this->routeUrl('show', $t['workspace'], $t['business'], $big), false);

        fwrite(STDERR, "\n[§18] builder page load: {$small} queries for a 1-node workflow, {$large} for 41 nodes\n");

        $this->assertLessThanOrEqual($small, $large, "Builder load must not grow with node count ({$small} vs {$large}).");
    }
}
