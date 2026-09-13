<?php

namespace Tests\Feature\Automations\Workflow\Http;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Http\Controllers\Customer\Business\Concerns\WorkflowFeatureQueryScope;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDefinitionValidator;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowReferenceCatalog;
use App\Library\Automation\Workflow\WorkflowReferenceCatalogLoader;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\ContactGroupFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
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
    use BuildsLogicWorkflows;
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

    /*
     * THE RULE (§18, owner decision on PR #280):
     *
     *   V2-E FEATURE-OWNED budgets — workflow list ≤ 2 queries, Builder ≤ 4.
     *   Shared platform request overhead (customer context, Account/Business
     *   authorization, the entitlement snapshot, app_config, and the customer
     *   shell: theme, languages, notifications) is measured independently and is
     *   NOT charged against the feature budget. Whole-request totals are still
     *   measured and reported as diagnostics; they are not V2-E pass/fail
     *   thresholds.
     *
     * HOW A STATEMENT IS CLASSIFIED — never by its text. Production code marks the
     * boundary explicitly (WorkflowFeatureQueryScope): the scope opens when the
     * canonical tenancy chain has finished and closes when the controller action
     * returns. A statement is FEATURE-OWNED if and only if it executes while the
     * scope is open, and SHARED otherwise. That classification can overstate the
     * feature's cost — any SQL a workflow action runs is charged to it, whatever
     * table it reads — but it can never file feature SQL under shared, which is
     * what a table-name match would eventually do.
     */

    /** §18 "workflow list ≤ 2", feature-owned. */
    private const FEATURE_LIST_BUDGET = 2;

    /** §18 "workflow Builder ≤ 4", feature-owned. */
    private const FEATURE_BUILDER_BUDGET = 4;

    /**
     * Every statement one whole request issues, each tagged with the ownership
     * the production seam assigned it at the instant it ran.
     *
     * The FIRST request in a test pays one-time costs — session, permission and
     * config rows warming, and a draft being created on first open — so each
     * measurement follows an unmeasured warm-up of the same URL.
     *
     * @return array{shared: list<string>, feature: list<string>, total: int}
     */
    private function classifiedStatements(string $method, string $url, bool $json): array
    {
        $json ? $this->json($method, $url)->assertOk() : $this->call($method, $url)->assertOk();

        $shared = [];
        $feature = [];
        $seen = 0;

        DB::listen(function ($query) use (&$shared, &$feature, &$seen): void {
            $seen++;
            $sql = preg_replace('/\s+/', ' ', (string) $query->sql);

            if (WorkflowFeatureQueryScope::isActive()) {
                $feature[] = $sql;
            } else {
                $shared[] = $sql;
            }
        });

        $json ? $this->json($method, $url)->assertOk() : $this->call($method, $url)->assertOk();

        return ['shared' => $shared, 'feature' => $feature, 'total' => $seen];
    }

    /**
     * Record a request's diagnostic totals where every run shows them. They are
     * reported, not asserted against a number: shared overhead has its own
     * independent budget (tests/Feature/QueryBudget), and §18 no longer charges it
     * to V2-E.
     *
     * @param array{shared: list<string>, feature: list<string>, total: int} $measured
     */
    private function recordDiagnostic(string $request, array $measured): void
    {
        fwrite(STDERR, sprintf(
            "\n[§18 diagnostic] %s: whole request %d = shared %d + V2-E feature-owned %d\n",
            $request,
            $measured['total'],
            count($measured['shared']),
            count($measured['feature']),
        ));
    }

    /**
     * @param array{shared: list<string>, feature: list<string>, total: int} $measured
     */
    private function assertFeatureOwnedWithin(string $request, int $budget, array $measured): void
    {
        // Exhaustive: no statement escaped classification.
        $this->assertSame(
            $measured['total'],
            count($measured['shared']) + count($measured['feature']),
            "{$request}: every statement must be classified exactly once.",
        );

        // The feature did real work, so an empty feature set would mean the seam
        // never opened — a broken boundary passing vacuously.
        $this->assertNotEmpty($measured['feature'], "{$request}: the feature-owned scope never opened.");

        $this->assertLessThanOrEqual(
            $budget,
            count($measured['feature']),
            sprintf(
                "%s: V2-E feature-owned SQL is %d, budget %d.\n  %s",
                $request,
                count($measured['feature']),
                $budget,
                implode("\n  ", $measured['feature']),
            ),
        );
    }

    /** A — feature-owned: the list page a person loads. */
    public function test_the_list_page_feature_owned_sql_is_within_budget(): void
    {
        $t = $this->signedInTenant();
        $measured = $this->classifiedStatements('GET', $this->routeUrl('index', $t['workspace'], $t['business']), false);

        $this->assertFeatureOwnedWithin('Workflow list (page)', self::FEATURE_LIST_BUDGET, $measured);
        $this->recordDiagnostic('Workflow list (page)', $measured);
    }

    /** A — feature-owned: the list JSON the builder fetches. */
    public function test_the_list_json_feature_owned_sql_is_within_budget(): void
    {
        $t = $this->signedInTenant();
        $measured = $this->classifiedStatements('GET', $this->routeUrl('index', $t['workspace'], $t['business']), true);

        $this->assertFeatureOwnedWithin('Workflow list (JSON)', self::FEATURE_LIST_BUDGET, $measured);
        $this->recordDiagnostic('Workflow list (JSON)', $measured);
    }

    /**
     * A — feature-owned: the Builder. This draft references no contact data; the
     * reference-count proofs further down hold the same budget for drafts that
     * reference one field, ten fields, and fifty mixed references.
     */
    public function test_the_builder_feature_owned_sql_is_within_budget(): void
    {
        $t = $this->signedInTenant();
        $measured = $this->classifiedStatements('GET', $this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']), false);

        $this->assertFeatureOwnedWithin('Builder', self::FEATURE_BUILDER_BUDGET, $measured);
        $this->recordDiagnostic('Builder', $measured);
    }

    /**
     * B — the classification is real, not a label. The shared portion contains the
     * canonical tenancy and shell work and none of the workflow tables; the
     * feature portion contains the workflow reads. Checked here against the
     * measured statements, AFTER classification, so it verifies the seam rather
     * than defining it.
     */
    public function test_the_ownership_seam_separates_shared_and_feature_sql_correctly(): void
    {
        $t = $this->signedInTenant();
        $measured = $this->classifiedStatements('GET', $this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']), false);

        $sharedSql = implode("\n", $measured['shared']);
        $featureSql = implode("\n", $measured['feature']);

        // Shared carries the canonical chain and the shell…
        $this->assertStringContainsString('workspace_plan_assignments', $sharedSql, 'The entitlement snapshot is shared.');
        $this->assertStringContainsString('platform_theme_presets', $sharedSql, 'The shell is shared.');
        // …and never the workflow feature's own reads.
        $this->assertStringNotContainsString('automation_workflows', $sharedSql, 'No workflow read may be filed as shared.');
        $this->assertStringNotContainsString('automation_workflow_versions', $sharedSql);

        // Feature carries the workflow reads, and none of the shell.
        $this->assertStringContainsString('automation_workflows', $featureSql);
        $this->assertStringNotContainsString('platform_theme_presets', $featureSql, 'Layout rendering happens after the action returns.');
        $this->assertStringNotContainsString('workspace_plan_assignments', $featureSql, 'Tenancy completes before the scope opens.');
    }

    /** B — diagnostic, not a threshold: the scope is always closed after a response. */
    public function test_the_feature_scope_never_leaks_past_a_response(): void
    {
        $t = $this->signedInTenant();

        foreach ([
            ['GET', $this->routeUrl('index', $t['workspace'], $t['business']), true],
            ['GET', $this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']), false],
        ] as [$method, $url, $json]) {
            $json ? $this->json($method, $url)->assertOk() : $this->call($method, $url)->assertOk();
            $this->assertFalse(WorkflowFeatureQueryScope::isActive(), "The feature scope stayed open after {$url}.");
        }

        // A denied request closes it too — the scope is opened only after tenancy,
        // but must never survive any exit path.
        [, , $otherWorkspace] = $this->entitledTenant();
        $this->json('GET', $this->routeUrl('index', $otherWorkspace, $t['business']))->assertNotFound();
        $this->assertFalse(WorkflowFeatureQueryScope::isActive());
    }

    /** §18 "for any page size" — on the feature-owned budget, the count does not move. */
    public function test_the_list_feature_owned_sql_does_not_grow_with_page_size(): void
    {
        $t = $this->signedInTenant();
        $url = $this->routeUrl('index', $t['workspace'], $t['business']);

        $small = count($this->classifiedStatements('GET', $url, true)['feature']);

        for ($i = 0; $i < 12; $i++) {
            $this->publishWorkflow($t['business'], [$this->endStep()], name: 'Extra ' . $i);
        }

        // Half with an open draft — the field that was once a query per row.
        foreach (\App\Models\AutomationWorkflow::query()->where('business_id', $t['business']->id)->limit(6)->get() as $w) {
            app(\App\Library\Automation\Workflow\WorkflowDraftService::class)->ensureDraft($w);
        }

        $large = count($this->classifiedStatements('GET', $url, true)['feature']);

        $this->assertSame($small, $large, "Feature-owned list SQL must not grow with page size ({$small} for 1 row, {$large} for 13).");
    }

    /** §18 "independent of node count" — on the feature-owned budget, the count does not move. */
    public function test_the_builder_feature_owned_sql_does_not_grow_with_node_count(): void
    {
        $t = $this->signedInTenant();

        $small = count($this->classifiedStatements('GET', $this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']), false)['feature']);

        $steps = [];
        for ($i = 0; $i < 40; $i++) {
            $steps[] = ['key' => (string) Str::uuid(), 'type' => 'send_sms', 'config' => ['body' => 'Step ' . $i]];
        }
        $steps[] = $this->endStep();
        [$big] = $this->publishWorkflow($t['business'], $steps, name: 'Forty steps');

        $large = count($this->classifiedStatements('GET', $this->routeUrl('show', $t['workspace'], $t['business'], $big), false)['feature']);

        $this->assertSame($small, $large, "Feature-owned Builder SQL must not grow with node count ({$small} vs {$large}).");
    }

    // ---------------------------------------------------------------
    // §18 "independent of reference count" — the Builder's one catalog
    // ---------------------------------------------------------------

    private const GROUP_FOREIGN = 'That contact group does not belong to this business.';

    /**
     * §18 "independent of node and reference count", through the real Builder
     * request, over real autosaved drafts:
     *
     *   A  no contact reference at all;
     *   B  one UpdateContactField reference;
     *   C  ten UpdateContactField references;
     *   D  the #290 fifty-reference stress shape — nineteen field updates on the
     *      watched group, then fifteen `contact.in_group` and fifteen
     *      `contact.custom_field:{id}` conditions over fifteen other groups,
     *      nested in If/Else branches three levels deep;
     *   E  duplicates — one field updated twenty times, and one If/Else checking
     *      the same group three times and the same field twice.
     *
     * Every draft costs the SAME feature-owned SQL, within budget, and exactly one
     * of those statements reads the contact catalog. Each draft's validation is
     * also checked on the rendered page, so a constant count cannot come from
     * validation quietly not running.
     */
    public function test_the_builder_feature_owned_sql_does_not_grow_with_reference_count(): void
    {
        $t = $this->signedInTenant();
        $business = $t['business'];

        $one = $this->contactGroup($business, 'One field');
        $ten = $this->contactGroup($business, 'Ten fields');
        $dup = $this->contactGroup($business, 'Duplicates');
        $dupField = $this->textField($dup, 'REPEATED');
        [$stress, $stressReferences] = $this->fiftyReferenceDocument($business);
        $this->assertSame(50, $stressReferences, 'Precondition: fifty distinct references.');

        $cases = [
            'A: 0 references' => $this->referenceDocument(null, [$this->smsStep()]),
            'B: 1 field reference' => $this->referenceDocument((int) $one->id, [
                $this->updateFieldStep((int) $this->textField($one, 'ONLY')->id),
            ]),
            'C: 10 field references' => $this->referenceDocument((int) $ten->id, array_map(
                fn (int $i): array => $this->updateFieldStep((int) $this->textField($ten, 'FIELD_' . $i)->id),
                range(1, 10),
            )),
            'D: 50 mixed references' => $stress,
            'E: duplicated references' => $this->referenceDocument((int) $dup->id, [
                ...array_map(fn (): array => $this->updateFieldStep((int) $dupField->id), range(1, 20)),
                $this->ifElseStep([
                    ...array_map(fn (): array => $this->condition('contact.in_group', 'equals', (string) $dup->id), range(1, 3)),
                    ...array_map(fn (): array => $this->condition('contact.custom_field:' . $dupField->id, 'equals', 'x'), range(1, 2)),
                ]),
            ]),
        ];

        $counts = [];

        foreach ($cases as $label => $definition) {
            $url = $this->routeUrl('show', $t['workspace'], $business, $this->workflowWithDraft($business, $definition));
            $request = "Builder ({$label})";

            $this->assertSame([], $this->builderData($url)['draft']['errors'], "{$request}: every reference belongs to this Business, so the draft is valid.");

            $measured = $this->classifiedStatements('GET', $url, false);

            $this->assertFeatureOwnedWithin($request, self::FEATURE_BUILDER_BUDGET, $measured);
            $this->assertOneCatalogRead($request, $measured['feature']);
            $this->recordDiagnostic($request, $measured);

            $counts[$label] = count($measured['feature']);
        }

        $this->assertCount(1, array_unique($counts), 'Feature-owned Builder SQL must not grow with reference count: ' . json_encode($counts));
    }

    /**
     * The constant cost is not bought by weakening the checks. The same fifty-
     * reference shape built from ANOTHER Business's groups and fields opens at the
     * same feature-owned cost, and the Builder reports every foreign reference.
     */
    public function test_the_builder_still_refuses_foreign_references_at_the_same_cost(): void
    {
        $t = $this->signedInTenant();
        $other = $this->tenantWithWorkflow();

        [$ownDefinition] = $this->fiftyReferenceDocument($t['business']);
        [$foreignDefinition] = $this->fiftyReferenceDocument($other['business']);

        $ownUrl = $this->routeUrl('show', $t['workspace'], $t['business'], $this->workflowWithDraft($t['business'], $ownDefinition));
        $foreignUrl = $this->routeUrl('show', $t['workspace'], $t['business'], $this->workflowWithDraft($t['business'], $foreignDefinition));

        $messages = collect($this->builderData($foreignUrl)['draft']['errors'])->flatten();

        $this->assertContains(self::GROUP_FOREIGN, $messages->all(), 'The foreign watched group is refused.');
        $this->assertSame(19, $messages->filter(fn (string $m): bool => $m === 'That field does not belong to the contact group this workflow watches.')->count());
        $this->assertSame(15, $messages->filter(fn (string $m): bool => str_ends_with($m, 'checks a contact group that does not belong to this business.'))->count());
        $this->assertSame(15, $messages->filter(fn (string $m): bool => str_ends_with($m, 'checks a contact field that does not belong to this business.'))->count());

        $own = $this->classifiedStatements('GET', $ownUrl, false);
        $foreign = $this->classifiedStatements('GET', $foreignUrl, false);

        $this->assertFeatureOwnedWithin('Builder (50 foreign references)', self::FEATURE_BUILDER_BUDGET, $foreign);
        $this->assertOneCatalogRead('Builder (50 foreign references)', $foreign['feature']);
        $this->assertSame(count($own['feature']), count($foreign['feature']), 'Refusing references costs no more than accepting them.');
    }

    /**
     * The REAL Builder request reads one catalog and hands that very object to the
     * compiler. The loader and compiler the controller receives are spies wrapping
     * the real classes — the spy compiler is built on the spy loader, so a lazy
     * load inside validate() would be counted too. Two page loads prove "once per
     * request", not once per test.
     */
    public function test_the_builder_loads_one_catalog_and_validates_against_that_same_object(): void
    {
        $t = $this->signedInTenant();
        [$definition] = $this->fiftyReferenceDocument($t['business']);
        $url = $this->routeUrl('show', $t['workspace'], $t['business'], $this->workflowWithDraft($t['business'], $definition));

        $loader = new class () extends WorkflowReferenceCatalogLoader {
            /** @var list<WorkflowReferenceCatalog> */
            public array $loaded = [];

            public function forBusiness(Business|int $business): WorkflowReferenceCatalog
            {
                return $this->loaded[] = parent::forBusiness($business);
            }
        };

        $compiler = new class (app(WorkflowDefinitionValidator::class), app(NodeTypeRegistry::class), $loader) extends WorkflowCompiler {
            /** @var list<WorkflowReferenceCatalog|null> */
            public array $validatedWith = [];

            public function validate(AutomationWorkflowVersion $version, ?WorkflowReferenceCatalog $catalog = null): array
            {
                $this->validatedWith[] = $catalog;

                return parent::validate($version, $catalog);
            }
        };

        // Bound before this test's first request, so the controller is built on them.
        $this->app->instance(WorkflowReferenceCatalogLoader::class, $loader);
        $this->app->instance(WorkflowCompiler::class, $compiler);

        foreach ([1, 2] as $load) {
            $data = $this->builderData($url);

            $this->assertCount($load, $loader->loaded, "Page load {$load}: the catalog is loaded once per Builder request.");
            $this->assertCount($load, $compiler->validatedWith, "Page load {$load}: the draft is validated once.");

            $catalog = $loader->loaded[$load - 1];

            $this->assertSame($catalog, $compiler->validatedWith[$load - 1], "Page load {$load}: validate() receives the very catalog the pickers came from.");
            $this->assertSame($t['business']->id, $catalog->businessId);

            // …and the pickers on the page are that catalog's answers.
            $this->assertSame(array_column($catalog->groups(), 'id'), array_column($data['catalogs']['contactGroups'], 'id'));
            $this->assertSame(array_column($catalog->dateFields(), 'id'), array_column($data['catalogs']['dateFields'], 'id'));
            $this->assertSame(array_column($catalog->writableFields(), 'id'), array_column($data['catalogs']['writableFields'], 'id'));
            $this->assertNotEmpty($data['catalogs']['writableFields']);
            $this->assertSame([], $data['draft']['errors']);
        }
    }

    /**
     * Exactly one feature-owned statement reads contact groups or fields, and it
     * is the catalog's single Business-scoped join. Checked on statements the seam
     * has ALREADY classified — it counts catalog reads, it never classifies.
     *
     * @param list<string> $feature
     */
    private function assertOneCatalogRead(string $request, array $feature): void
    {
        $contactReads = array_values(array_filter(
            $feature,
            static fn (string $sql): bool => str_contains($sql, '`contact_groups`') || str_contains($sql, '`contact_group_fields`'),
        ));

        $this->assertCount(1, $contactReads, "{$request}: exactly one contact catalog read, observed:\n  " . implode("\n  ", $contactReads));
        $this->assertStringContainsString('left join `contact_group_fields`', $contactReads[0]);
        $this->assertStringContainsString('`contact_groups`.`business_id` = ?', $contactReads[0]);
    }

    /** The builder bootstrap blob a page load renders. @return array<string, mixed> */
    private function builderData(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        preg_match('#<script type="application/json" id="wf-builder-data">(.*?)</script>#s', $html, $match);
        $this->assertNotEmpty($match, 'The builder bootstrap blob must be rendered.');

        return json_decode(html_entity_decode($match[1]), true);
    }

    /** A new workflow whose draft holds $definition, saved the way the builder saves it. */
    private function workflowWithDraft(Business $business, array $definition): AutomationWorkflow
    {
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'References ' . Str::random(6), WorkflowTriggerType::ContactCreated);
        $draft = $workflow->draftVersion();

        $drafts->autosave($draft, $definition, (int) $draft->definition_revision);

        return $workflow->fresh();
    }

    /**
     * The #290 stress document (CompilerReferenceCatalogTest), for any owner.
     *
     * @return array{0: array<string, mixed>, 1: int} the document and its distinct reference count
     */
    private function fiftyReferenceDocument(Business $owner): array
    {
        $watched = $this->contactGroup($owner, 'Watched');
        $updates = array_map(fn (int $i): array => $this->updateFieldStep((int) $this->textField($watched, 'UPDATE_' . $i)->id), range(1, 19));

        $conditions = [];

        foreach (range(1, 15) as $i) {
            $group = $this->contactGroup($owner, 'Segment ' . $i);
            $conditions[] = $this->condition('contact.in_group', 'equals', (string) $group->id);
            $conditions[] = $this->condition('contact.custom_field:' . $this->textField($group, 'CONDITION_' . $i)->id, 'equals', 'x');
        }

        [$a, $b, $c, $d, $e, $f] = array_chunk($conditions, 5);

        $tree = $this->ifElseStep(
            $a,
            [$this->ifElseStep($b, [$this->ifElseStep($c)], [$this->ifElseStep($d)])],
            [$this->ifElseStep($e, [$this->ifElseStep($f)])],
        );

        return [$this->referenceDocument((int) $watched->id, [...$updates, $tree]), 1 + count($updates) + count($conditions)];
    }

    /** @return array<string, mixed> a Contact created document, watching $groupId when given */
    private function referenceDocument(?int $groupId, array $steps): array
    {
        $definition = app(WorkflowDraftService::class)->starterDefinition(WorkflowTriggerType::ContactCreated);

        if ($groupId !== null) {
            $definition['root']['config']['contact_group_id'] = $groupId;
        }

        $definition['root']['next'] = $steps;

        return $definition;
    }

    private function smsStep(): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'send_sms', 'config' => ['body' => 'Hello']];
    }

    private function updateFieldStep(int $fieldId): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'update_contact_field', 'config' => ['field_id' => $fieldId, 'value' => 'touched']];
    }

    /**
     * Shared-overhead regression guard that already existed on this file: a page
     * must resolve entitlement for its Business once, not once in the controller
     * and again for the menu. It asserts a SHARED property, so it matches on the
     * entitlement table by name — it never classifies feature SQL.
     */
    public function test_a_page_resolves_entitlement_for_its_business_only_once(): void
    {
        $t = $this->signedInTenant();

        foreach ([
            $this->routeUrl('index', $t['workspace'], $t['business']),
            $this->routeUrl('show', $t['workspace'], $t['business'], $t['workflow']),
        ] as $url) {
            $measured = $this->classifiedStatements('GET', $url, false);
            $assignmentReads = array_filter(
                [...$measured['shared'], ...$measured['feature']],
                static fn (string $sql): bool => str_contains($sql, 'from `workspace_plan_assignments`'),
            );

            $this->assertCount(1, $assignmentReads, "Entitlement must be resolved once per page, not twice ({$url}).");
        }
    }
}
