<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\TestCase;

/**
 * The "Start a new workflow" chooser redesign: the server-rendered frame and the
 * data contract the script groups templates from. Template cards themselves are
 * JS-rendered, so the grouping/counts are proven in the browser run; here the
 * inputs they are derived from (capabilities, labels, links) are pinned.
 */
class ChooserRedesignTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use CallsWorkflowRoutes;

    private function page(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $html = $this->get($this->routeUrl('create', $workspace, $business))->assertOk()->getContent();

        return [$html, $workspace, $business];
    }

    private function scriptJson(string $html, string $id): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" id="' . $id . '"[^>]*>(.*?)<\/script>/s', $html, $m), $id);

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_header_has_a_working_back_link_title_and_subtitle(): void
    {
        [$html, $workspace, $business] = $this->page();

        $back = route('customer.workspaces.businesses.automations.workflows.index', [$workspace->uid, $business->uid]);
        $this->assertStringContainsString('data-role="wf-chooser-back" href="' . $back . '"', $html);
        $this->assertStringContainsString('Back to automations', $html);
        $this->assertStringContainsString('<h1 class="wf-chooser-title">Start a new workflow</h1>', $html);
        $this->assertStringContainsString('Name it, then start from scratch or pick a template.', $html);
        $this->get($back)->assertOk();
    }

    public function test_creation_card_keeps_the_optional_name_and_scratch_selectors(): void
    {
        [$html] = $this->page();

        $this->assertStringContainsString('data-role="wf-chooser-name"', $html);
        $this->assertStringContainsString('(optional)', $html);
        $this->assertStringContainsString('data-role="wf-chooser-scratch"', $html);
        $this->assertStringContainsString('data-role="wf-chooser-error"', $html);
        $this->assertStringContainsString('data-role="wf-chooser-recipes"', $html);
        $this->assertStringContainsString('data-role="wf-chooser-setup-recipes"', $html);
    }

    public function test_no_template_counts_are_hardcoded_into_the_page(): void
    {
        [$html] = $this->page();

        $this->assertDoesNotMatchRegularExpression('/>\s*\d+ templates?\s*</', $html);
        $config = $this->scriptJson($html, 'wf-chooser-config');
        $this->assertStringContainsString(':count', $config['text']['template_count_other']);
        $this->assertStringContainsString(':count', $config['text']['banner_title_other']);
    }

    public function test_capabilities_and_labels_for_every_template_are_carried_as_data(): void
    {
        [$html] = $this->page();

        $capabilities = $this->scriptJson($html, 'wf-capabilities');
        foreach (['sms', 'email', 'crm', 'calendar', 'forms', 'documents', 'catalog', 'payments'] as $key) {
            $this->assertArrayHasKey($key, $capabilities);
            $this->assertArrayHasKey('available', $capabilities[$key]);
        }
        $this->assertCount(9, $this->scriptJson($html, 'wf-recipe-labels'));
    }

    public function test_an_unconnected_account_gets_real_setup_reasons_and_no_setup_link_it_may_not_open(): void
    {
        [$html, $workspace, $business] = $this->page();

        $capabilities = $this->scriptJson($html, 'wf-capabilities');
        $this->assertFalse($capabilities['sms']['available']);
        $this->assertNotEmpty($capabilities['sms']['reason']);

        $links = $this->scriptJson($html, 'wf-chooser-config')['setupLinks'];
        // Whatever is offered is a real Settings destination for this Business.
        foreach ($links as $capability => $url) {
            $this->assertContains($capability, ['sms', 'email', 'payments']);
            $this->assertStringContainsString($business->uid, $url);
            $this->assertStringStartsWith('http', $url);
        }
    }

    public function test_the_script_still_only_creates_through_the_existing_endpoint(): void
    {
        [$html, $workspace, $business] = $this->page();

        $basePath = route('customer.workspaces.businesses.automations.workflows.index', [$workspace->uid, $business->uid], false);
        $this->assertStringContainsString('data-create-url="' . $basePath . '"', $html);
        $this->assertStringContainsString('initChooser', $html);
    }
}
