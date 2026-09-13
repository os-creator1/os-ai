<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\WorkflowDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Automations V2 (contract §13.1) — the builder a customer actually works in.
 *
 * The canvas, step picker, inspector and Test panel are client-rendered and
 * are exercised in the browser smoke; what this suite pins is everything the
 * server decides or the source guarantees:
 *
 *   - the header offers exactly the lifecycle actions the workflow's status
 *     allows (Publish; Pause only when live; Resume only when paused; nothing
 *     for an archived one);
 *   - configuration is a panel docked beside the canvas, and Test workflow has
 *     its own — no overlay drawer covering the flow;
 *   - every icon the builder's scripts draw is rendered by the design system's
 *     icon seam, so no card or picker entry is ever drawn without one;
 *   - nothing asks a person to type an identifier, and nothing technical leaks
 *     into the copy;
 *   - "New workflow" sends the create body §20.2 fixes.
 */
class BuilderExperienceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
    }

    private function renderFor(string $status): string
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        $definition = (new WorkflowDraftService())->starterDefinition(WorkflowTriggerType::ContactCreated);
        $path = '/__test/wf-builder-' . $status;

        Route::middleware('web')->get($path, fn () => view('customer.Automations.Workflows.builder', [
            'workspaceUid' => 'ws-uid',
            'businessUid' => 'biz-uid',
            'basePath' => '/workspaces/ws-uid/businesses/biz-uid/automations/workflows',
            'workflow' => (object) ['uid' => 'wf-1', 'name' => 'Welcome flow', 'status' => $status],
            'draft' => ['definition' => $definition, 'revision' => 1, 'errors' => []],
            'contactGroups' => [(object) ['id' => 1, 'name' => 'Leads']],
            'dateFields' => [],
            'writableFields' => [],
        ]));

        return $this->get($path)->assertOk()->getContent();
    }

    private function openingTag(string $html, string $role): string
    {
        $this->assertMatchesRegularExpression('/<[a-z]+[^>]*data-role="' . preg_quote($role, '/') . '"[^>]*>/', $html, "Missing [{$role}].");
        preg_match('/<[a-z]+[^>]*data-role="' . preg_quote($role, '/') . '"[^>]*>/', $html, $match);

        return $match[0];
    }

    private function isHidden(string $html, string $role): bool
    {
        return preg_match('/\shidden(\s|=|>)/', $this->openingTag($html, $role)) === 1;
    }

    /** @return array<string, string> file => source, comments stripped */
    private function builderScripts(): array
    {
        $sources = [];

        foreach (glob(base_path('resources/js/automations/workflow-builder/*.js')) as $file) {
            $source = (string) file_get_contents($file);
            $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
            $sources[basename($file)] = (string) preg_replace('#^\s*//.*$#m', '', $source);
        }

        $this->assertNotEmpty($sources);

        return $sources;
    }

    public function test_the_header_offers_only_the_actions_each_status_allows(): void
    {
        $expected = [
            // status      => [publish hidden, pause hidden, resume hidden, label]
            'draft' => [false, true, true, 'Publish'],
            'published' => [false, false, true, 'Publish changes'],
            'paused' => [false, true, false, 'Publish changes'],
            'archived' => [true, true, true, null],
        ];

        foreach ($expected as $status => [$publishHidden, $pauseHidden, $resumeHidden, $label]) {
            $html = $this->renderFor($status);

            $this->assertSame($publishHidden, $this->isHidden($html, 'wf-publish'), "{$status}: Publish");
            $this->assertSame($pauseHidden, $this->isHidden($html, 'wf-pause'), "{$status}: Pause");
            $this->assertSame($resumeHidden, $this->isHidden($html, 'wf-resume'), "{$status}: Resume");
            $this->assertStringContainsString('data-status="' . $status . '"', $this->openingTag($html, 'wf-status'));

            if ($label !== null) {
                $this->assertMatchesRegularExpression('/data-role="wf-publish-label">\s*' . preg_quote($label, '/') . '\s*</', $html, "{$status}: publish label");
            }

            // Back, name, save state, undo/redo and Test are always there.
            foreach (['wf-back', 'wf-name', 'wf-save-state', 'wf-undo', 'wf-redo', 'wf-test-workflow'] as $role) {
                $this->openingTag($html, $role);
            }
        }
    }

    public function test_configuration_and_testing_are_panels_docked_beside_the_canvas(): void
    {
        $html = $this->renderFor('draft');

        $this->assertStringContainsString('<aside class="wf-panel" data-role="wf-drawer"', $html);
        $this->assertStringContainsString('data-role="wf-test-panel"', $html);
        $this->assertTrue($this->isHidden($html, 'wf-drawer'), 'The inspector opens when a step is selected.');
        $this->assertStringNotContainsString('offcanvas', $html, 'No overlay drawer covers the flow being configured.');

        // Canvas and panels share one workspace, so the flow stays in view.
        $this->assertMatchesRegularExpression('/data-role="wf-workspace".*data-role="wf-canvas-root".*data-role="wf-drawer".*data-role="wf-test-panel"/s', $html);
    }

    public function test_every_icon_the_builder_draws_is_rendered_by_the_icon_seam(): void
    {
        $html = $this->renderFor('draft');
        $names = [];

        foreach ($this->builderScripts() as $source) {
            preg_match_all("/icon\\('([a-z0-9-]+)'/", $source, $direct);
            preg_match_all("/icon: '([a-z0-9-]+)'/", $source, $catalog);
            preg_match_all("/\\|\\| '([a-z0-9-]+)'\\s*,\\s*`wf-tone/", $source, $fallback);
            $names = [...$names, ...$direct[1], ...$catalog[1], ...$fallback[1]];
        }

        $names = array_values(array_unique($names));
        $this->assertContains('zap', $names, 'Precondition: the trigger icon is among those found.');

        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/<template id="wf-icon-' . preg_quote($name, '/') . '">\s*<svg/', $html, "The builder draws [{$name}], so the page must render it.");
        }
    }

    public function test_nothing_asks_a_person_to_type_an_identifier(): void
    {
        foreach ($this->builderScripts() as $file => $source) {
            $this->assertStringNotContainsString('prompt(', $source, "{$file} must not ask for input through a browser prompt.");
            // Copy lives in string literals; an identifier such as randomUUID() is not copy.
            $this->assertDoesNotMatchRegularExpression("/(['\"`])[^'\"`\\n]*\\b(UID|uid|ID)\\b[^'\"`\\n]*\\1/", $source, "{$file} must never show an identifier to a person.");
        }

        // The builder's own copy: from its root to its form templates.
        $html = $this->renderFor('draft');
        $start = strpos($html, 'id="wf-builder"');
        $builder = substr($html, $start, strpos($html, 'id="wf-node-form-trigger"') - $start);
        $visible = strip_tags((string) preg_replace('#<(script|template)\b[^>]*>.*?</\1>#s', '', $builder));

        foreach (['V2-E', 'endpoint', 'wired up', 'uid', 'JSON', 'node'] as $jargon) {
            $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($jargon, '/') . '\b/i', $visible, "Customer-facing copy must not mention [{$jargon}].");
        }
    }

    /** V2-F capabilities, offered in customer words by the scripts that summarise and edit steps. */
    public function test_the_builder_speaks_about_replies_in_customer_words(): void
    {
        $scripts = $this->builderScripts();

        $this->assertStringContainsString("value: 'message_received'", $scripts['constants.js']);
        $this->assertStringContainsString("title: 'Customer sends a text'", $scripts['constants.js']);
        $this->assertStringContainsString("'Customer replied'", $scripts['conditions.js']);
        $this->assertStringContainsString("REPLIED_SUBJECT = 'contact.replied_since_enrollment'", $scripts['conditions.js']);
    }

    /** §20.2 — create takes a name and a trigger type; the old `{mode}` body was refused with a 422. */
    public function test_new_workflow_sends_the_create_body_the_contract_fixes(): void
    {
        $index = $this->builderScripts()['index.js'];

        $this->assertStringContainsString('api.create({ name, trigger_type: triggerType })', $index);
        $this->assertStringNotContainsString("mode: 'scratch'", $index);
        $this->assertStringNotContainsString("mode: 'recipe'", $index);
    }
}
