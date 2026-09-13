<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Automations V2 (contract §13.3, V2-D) — the workflow list view.
 *
 * V2-E's controller does not exist yet (contract §16: "D can build against
 * fixtures while E builds the real controllers"), so every test here
 * registers its OWN ad-hoc route inside the test process only — nothing is
 * written to routes/customer.php, which stays entirely V2-E's file. This
 * proves the view itself: given the props a real controller will one day
 * supply, does it render the canonical states truthfully and nothing else.
 */
class WorkflowListViewTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
    }

    private function registerListRoute(array $workflows): void
    {
        Route::middleware('web')->get('/__test/wf-list', function () use ($workflows) {
            return view('customer.Automations.Workflows.index', [
                'workspaceUid' => 'ws-uid',
                'businessUid' => 'biz-uid',
                'basePath' => '/workspaces/ws-uid/businesses/biz-uid/automations/workflows',
                'workflows' => $workflows,
            ]);
        });
    }

    private function row(string $name, string $status): object
    {
        return (object) ['uid' => 'wf-' . $name, 'name' => $name, 'status' => $status, 'updated_at' => now()];
    }

    /** T1 — every canonical V2 lifecycle state renders, and nothing is fabricated. */
    public function test_the_list_shows_canonical_states_and_fabricates_nothing(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        $this->registerListRoute([
            $this->row('Welcome flow', 'draft'),
            $this->row('Reminder flow', 'published'),
            $this->row('Old campaign', 'paused'),
        ]);

        $response = $this->get('/__test/wf-list');

        $response->assertOk();
        $response->assertSee('Draft', false);
        $response->assertSee('Published', false);
        $response->assertSee('Paused', false);
        $response->assertSee('Welcome flow');
        $response->assertSee('Reminder flow');
        $response->assertSee('Old campaign');

        $body = $response->getContent();
        foreach (['conversion', 'leads generated', 'revenue', 'booking rate', 'success rate', '% success'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $body, "The list must never fabricate \"{$forbidden}\".");
        }
    }

    public function test_an_empty_list_shows_the_empty_state_not_a_zero_table(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        $this->registerListRoute([]);

        $response = $this->get('/__test/wf-list');

        $response->assertOk();
        $response->assertSee('No workflows yet');
        $this->assertStringNotContainsString('data-role="wf-list-row"', $response->getContent());
    }

    public function test_new_workflow_button_links_to_the_chooser(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        $this->registerListRoute([]);

        $response = $this->get('/__test/wf-list');

        $response->assertSee('href="/workspaces/ws-uid/businesses/biz-uid/automations/workflows/new"', false);
    }
}
