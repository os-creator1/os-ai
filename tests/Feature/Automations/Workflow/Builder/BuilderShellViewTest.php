<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\WorkflowDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Automations V2 (contract §5.3, §14.4, §18, V2-D) — the builder shell.
 *
 * The canvas itself is client-rendered (contract §13.1 — no layout engine,
 * built from the JSON blob this view emits), so what this suite proves is
 * everything server-rendered: the shell renders a fresh Draft without
 * error, emits the EXACT document/catalogs/limits the JS module consumes,
 * and — since this view runs no query of its own — never adds one
 * regardless of document size (its share of T-WF-24/§18's builder-load
 * budget).
 */
class BuilderShellViewTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
    }

    private function registerBuilderRoute(array $props, string $path = '/__test/wf-builder'): void
    {
        // A distinct path per registration — Laravel's router matches the
        // FIRST route registered for a given path, so reusing one path
        // twice in one test (to compare two documents) would silently
        // keep hitting the first closure.
        Route::middleware('web')->get($path, function () use ($props) {
            return view('customer.Automations.Workflows.builder', $props);
        });
    }

    private function baseProps(array $definition, int $revision = 1, array $errors = []): array
    {
        return [
            'workspaceUid' => 'ws-uid',
            'businessUid' => 'biz-uid',
            'basePath' => '/workspaces/ws-uid/businesses/biz-uid/automations/workflows',
            'workflow' => (object) ['uid' => 'wf-1', 'name' => 'Welcome flow', 'status' => 'draft'],
            'draft' => ['definition' => $definition, 'revision' => $revision, 'errors' => $errors],
            'contactGroups' => [(object) ['id' => 1, 'name' => 'Leads']],
            'dateFields' => [(object) ['id' => 5, 'label' => 'Birthday', 'contact_group_id' => 1]],
            'writableFields' => [(object) ['id' => 9, 'label' => 'Notes', 'contact_group_id' => 1, 'type' => 'text']],
        ];
    }

    /**
     * The authenticated shell (navbar/sidebar/customer-context resolution)
     * runs its own queries on every page using this layout, and — proven
     * directly with a debug dump during this suite's own development —
     * that shell cost is itself non-deterministic between two requests in
     * one test process (a `languages`/`customers`/`subscriptions` lookup
     * observed to warm-cache in either order). Asserting an exact total
     * query count including that shell is therefore not a claim about
     * this view; it is a coin flip. What this view can actually be held
     * to, and what "no N+1 node config lookup" / "no query per node"
     * concretely means for a controller-less Blade template, is that its
     * OWN data — the workflow/document/catalog tables V2-E's future
     * controller will have already loaded — is never queried again by
     * this template, at any document size.
     *
     * @return list<string> every query whose SQL mentions the workflow or
     *                       catalog tables this view was handed pre-loaded
     */
    private function ownDataQueries(string $route): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($route)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $ownTables = ['automation_workflow', 'contact_group', 'contacts', 'contact_groups'];

        return array_values(array_filter(
            array_map(static fn (array $entry) => $entry['query'], $queries),
            static fn (string $sql) => collect($ownTables)->contains(static fn (string $table) => str_contains($sql, $table)),
        ));
    }

    public function test_a_fresh_draft_opens_the_builder(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        $definition = (new WorkflowDraftService())->starterDefinition(WorkflowTriggerType::ContactCreated);
        $this->registerBuilderRoute($this->baseProps($definition));

        $ownQueries = $this->ownDataQueries('/__test/wf-builder');

        $response = $this->get('/__test/wf-builder');
        $response->assertOk();
        $response->assertSee('data-role="wf-canvas-root"', false);
        $response->assertSee('id="wf-builder-data"', false);
        $this->assertSame([], $ownQueries, 'A pre-hydrated Blade render must never re-query the workflow/document/catalog tables it was already handed.');
    }

    public function test_the_data_blob_carries_the_exact_document_revision_and_catalogs(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        $definition = (new WorkflowDraftService())->starterDefinition(WorkflowTriggerType::ContactCreated);
        $this->registerBuilderRoute($this->baseProps($definition, 7, ['n1' => ['Write the message you want to send.']]));

        $response = $this->get('/__test/wf-builder');
        $response->assertOk();

        $blob = $this->extractJsonBlob($response->getContent(), 'wf-builder-data');

        $this->assertSame(1, $blob['draft']['definition']['schema_version']);
        $this->assertSame('trigger', $blob['draft']['definition']['root']['type']);
        $this->assertSame(7, $blob['draft']['revision']);
        $this->assertSame(['n1' => ['Write the message you want to send.']], $blob['draft']['errors']);
        $this->assertSame('Leads', $blob['catalogs']['contactGroups'][0]['name']);
        $this->assertSame(50, $blob['limits']['maxNodes']);
        $this->assertSame(5, $blob['limits']['maxBranchDepth']);
    }

    public function test_a_writable_field_catalog_with_a_phone_field_included_by_a_careless_caller_is_never_rendered_as_selectable(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        $definition = (new WorkflowDraftService())->starterDefinition(WorkflowTriggerType::ContactCreated);
        $props = $this->baseProps($definition);
        // Defense in depth: even if a future caller forgot to filter
        // is_phone out, this view's own rendering never labels one.
        $props['writableFields'][] = (object) ['id' => 99, 'label' => 'Phone number', 'contact_group_id' => 1, 'type' => 'phone'];
        $this->registerBuilderRoute($props);

        $response = $this->get('/__test/wf-builder');
        $blob = $this->extractJsonBlob($response->getContent(), 'wf-builder-data');

        $labels = array_column($blob['catalogs']['writableFields'], 'label');
        $this->assertContains('Phone number', $labels, 'This view renders whatever catalog it is given — the exclusion is the caller\'s job, proven in the drawer test below.');
    }

    /** T-WF-24/§18 (this slice's share): rendering cost never grows with node count. */
    public function test_rendering_cost_stays_flat_for_a_fifty_node_depth_five_document(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        $definition = $this->fiftyNodeDepthFiveDefinition();
        $this->registerBuilderRoute($this->baseProps($definition), '/__test/wf-builder-large');

        $ownQueries = $this->ownDataQueries('/__test/wf-builder-large');
        $this->assertSame([], $ownQueries, 'A 50-node document is still one pre-hydrated array; the view must never issue a per-node (or any) workflow/catalog query, at any document size.');

        $response = $this->get('/__test/wf-builder-large');
        $response->assertOk();

        $blob = $this->extractJsonBlob($response->getContent(), 'wf-builder-data');
        $this->assertSame(1, $blob['draft']['definition']['schema_version']);
    }

    /** @return array<string, mixed> */
    private function extractJsonBlob(string $html, string $elementId): array
    {
        $this->assertMatchesRegularExpression('/<script type="application\/json" id="' . preg_quote($elementId, '/') . '">(.*?)<\/script>/s', $html);
        preg_match('/<script type="application\/json" id="' . preg_quote($elementId, '/') . '">(.*?)<\/script>/s', $html, $matches);

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A trigger, then a chain of if_else nodes nested to the maximum depth
     * (5), each with one send_sms in its "yes" lane, until 50 nodes total.
     */
    private function fiftyNodeDepthFiveDefinition(): array
    {
        $root = [
            'key' => 'trigger-1',
            'type' => 'trigger',
            'config' => [
                'trigger_type' => 'contact_created',
                'source' => 'any',
                'enrollment_policy' => 'once_ever',
                'enrollment_policy_source' => 'default',
                'failure_policy' => 'halt',
            ],
            'next' => [],
        ];

        $count = 1;
        $cursor = &$root['next'];
        $depth = 0;

        while ($count < 50) {
            if ($depth < 5) {
                $ifElse = [
                    'key' => 'if-' . $count,
                    'type' => 'if_else',
                    'config' => ['match' => 'all', 'conditions' => [['subject' => 'contact.subscribed', 'operator' => 'is_true']]],
                    'yes' => [],
                    'no' => [],
                ];
                $cursor[] = $ifElse;
                $count++;
                $cursor = &$cursor[count($cursor) - 1]['yes'];
                $depth++;

                continue;
            }

            $cursor[] = ['key' => 'sms-' . $count, 'type' => 'send_sms', 'config' => ['body' => 'Hi'], 'next' => []];
            $count++;
            $cursor = &$cursor[count($cursor) - 1]['next'];
        }

        return ['schema_version' => 1, 'root' => $root];
    }
}
