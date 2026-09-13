<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Automations V2 (contract §13.3, V2-D) — the create/recipe chooser.
 */
class ChooserViewTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
    }

    public function test_the_chooser_offers_start_from_scratch_and_carries_the_create_endpoint(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        Route::middleware('web')->get('/__test/wf-chooser', fn () => view('customer.Automations.Workflows.chooser', [
            'workspaceUid' => 'ws-uid',
            'businessUid' => 'biz-uid',
            'basePath' => '/workspaces/ws-uid/businesses/biz-uid/automations/workflows',
        ]));

        $response = $this->get('/__test/wf-chooser')->assertOk();

        $response->assertSee('data-role="wf-chooser-scratch"', false);
        $response->assertSee('data-create-url="/workspaces/ws-uid/businesses/biz-uid/automations/workflows"', false);
    }

    /** Recipe labels are pre-localized server-side and handed to JS as data, never a raw translation key. */
    public function test_recipe_labels_are_localized_and_carried_as_data(): void
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        Route::middleware('web')->get('/__test/wf-chooser-labels', fn () => view('customer.Automations.Workflows.chooser', [
            'workspaceUid' => 'ws-uid',
            'businessUid' => 'biz-uid',
            'basePath' => '/workspaces/ws-uid/businesses/biz-uid/automations/workflows',
        ]));

        $html = $this->get('/__test/wf-chooser-labels')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<script type="application\/json" id="wf-recipe-labels">(.*?)<\/script>/s', $html);
        preg_match('/<script type="application\/json" id="wf-recipe-labels">(.*?)<\/script>/s', $html, $matches);
        $labels = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Welcome a new contact', $labels['welcome_new_contact']['title']);
        $this->assertArrayHasKey('description', $labels['welcome_new_contact']);
        $this->assertCount(4, $labels, 'Exactly the four V2-scoped recipes — never the withheld B4-era catalogue.');
    }
}
