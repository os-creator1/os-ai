<?php

namespace Tests\Feature\Automations\Workflow\Http;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Models\AutomationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * The Automations list: one card per workflow, with counts, guidance, filters and
 * search all coming from the canonical workflow data — never recalculated in the view.
 */
class WorkflowIndexPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use CallsWorkflowRoutes;

    private function draft(array $t, string $name): AutomationWorkflow
    {
        return app(WorkflowDraftService::class)->createWorkflowWithDraft($t['business'], $name, WorkflowTriggerType::ContactCreated);
    }

    /** @return array{customer: \App\Models\Customer, business: \App\Models\Business, workspace: \App\Models\Workspace} */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        return compact('customer', 'business', 'workspace');
    }

    private function indexUrl(array $t, array $query = []): string
    {
        return $this->routeUrl('index', $t['workspace'], $t['business']) . ($query ? '?' . http_build_query($query) : '');
    }

    /** Give a draft a step that cannot publish (an SMS with no message). */
    private function breakDraft(AutomationWorkflow $workflow): void
    {
        $draft = $workflow->draftVersion();
        $definition = $draft->definition;
        $definition['root']['next'] = [['key' => (string) Str::uuid(), 'type' => 'send_sms', 'config' => ['body' => '']]];
        $draft->forceFill(['definition' => $definition])->save();
    }

    public function test_summary_uses_real_counts_and_zero_live_shows_the_guidance_banner(): void
    {
        $t = $this->tenant();
        $this->draft($t, 'Alpha');
        $this->draft($t, 'Beta');
        $this->draft($t, 'Gamma');

        $html = $this->get($this->indexUrl($t))->assertOk()->getContent();

        $this->assertStringContainsString('3 workflows', $html);
        $this->assertStringContainsString('0 live', $html);
        $this->assertStringContainsString('3 drafts', $html);
        $this->assertStringContainsString('data-role="wf-list-guidance"', $html);
        $this->assertStringContainsString('Nothing is running yet.', $html);
        $this->assertStringContainsString('All three workflows are drafts.', $html);
        $this->assertStringContainsString('Open one and publish it to turn it on.', $html);
    }

    public function test_banner_is_absent_when_a_workflow_is_live_and_the_filters_count_each_state(): void
    {
        $t = $this->tenant();
        $this->publishWorkflow($t['business'], [$this->endStep()], name: 'Running one');
        $this->draft($t, 'Still a draft');

        $html = $this->get($this->indexUrl($t))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="wf-list-guidance"', $html);
        $this->assertStringNotContainsString('Nothing is running yet', $html);
        $this->assertMatchesRegularExpression('/data-filter="all"[^>]*>All 2</', $html);
        $this->assertMatchesRegularExpression('/data-filter="published"[^>]*>Live 1</', $html);
        $this->assertMatchesRegularExpression('/data-filter="draft"[^>]*>Drafts 1</', $html);
        $this->assertStringContainsString('data-role="wf-list-search-form"', $html);
    }

    public function test_rows_show_status_scope_updated_time_and_open_route(): void
    {
        $t = $this->tenant();
        $t['business']->forceFill(['timezone' => 'America/Chicago'])->save();
        $workflow = $this->draft($t, 'Review request after the event');
        $workflow->forceFill(['updated_at' => Carbon::create(2026, 10, 8, 9, 10, 0, config('app.timezone'))])->saveQuietly();

        $html = $this->get($this->indexUrl($t))->assertOk()->getContent();

        $this->assertStringContainsString('Review request after the event', $html);
        $this->assertStringContainsString('Draft', $html);
        $this->assertStringContainsString('Applies to', $html);
        $this->assertStringContainsString('Whole business', $html);
        $this->assertStringContainsString('Last updated', $html);
        $this->assertStringContainsString('Oct 8, 2026, 8:10 AM', $html, 'Shown in the Business timezone, not raw.');
        $this->assertStringNotContainsString('2026-10-08', $html);
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.automations.workflows.show', [$t['workspace']->uid, $t['business']->uid, $workflow->uid], false) . '"', $html);
    }

    public function test_the_issue_badge_comes_from_the_validator_and_only_where_issues_exist(): void
    {
        $t = $this->tenant();
        $broken = $this->draft($t, 'Broken one');
        $this->breakDraft($broken);
        $this->draft($t, 'Fine one');

        $html = $this->get($this->indexUrl($t))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-role="wf-list-issues"'));
        $this->assertMatchesRegularExpression('/\d+ issues? to fix before publishing/', $html);

        $brokenCard = Str::between($html, 'data-workflow-uid="' . $broken->uid . '"', 'data-role="wf-list-open-button"');
        $this->assertStringContainsString('wf-list-issues', $brokenCard);
    }

    public function test_both_new_workflow_actions_use_the_one_creation_route(): void
    {
        $t = $this->tenant();
        $this->draft($t, 'Something');
        $new = route('customer.workspaces.businesses.automations.workflows.create', [$t['workspace']->uid, $t['business']->uid], false);

        $html = $this->get($this->indexUrl($t))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="wf-new-workflow"', $html);
        $this->assertStringContainsString('data-role="wf-new-workflow-bottom"', $html);
        $this->assertSame(2, substr_count($html, 'href="' . $new . '"'));
    }

    public function test_status_filter_and_search_narrow_the_list_without_touching_the_counts(): void
    {
        $t = $this->tenant();
        $this->publishWorkflow($t['business'], [$this->endStep()], name: 'Live Reminder');
        $this->draft($t, 'Draft Welcome');
        $this->draft($t, 'Draft Follow-up');

        $drafts = $this->get($this->indexUrl($t, ['status' => 'draft']))->assertOk();
        $drafts->assertSee('Draft Welcome')->assertSee('Draft Follow-up')->assertDontSee('Live Reminder');
        $this->assertMatchesRegularExpression('/data-filter="all"[^>]*>All 3</', $drafts->getContent());

        $live = $this->get($this->indexUrl($t, ['status' => 'published']))->assertOk();
        $live->assertSee('Live Reminder')->assertDontSee('Draft Welcome');

        $search = $this->get($this->indexUrl($t, ['q' => 'welcome']))->assertOk();
        $search->assertSee('Draft Welcome')->assertDontSee('Draft Follow-up')->assertDontSee('Live Reminder');

        $none = $this->get($this->indexUrl($t, ['q' => 'zzz-nothing']))->assertOk();
        $none->assertSee('No workflows match.');
        $this->assertSame(3, AutomationWorkflow::query()->count(), 'Filtering never changes records.');
    }

    /**
     * §18.1: the list is 2 feature-owned statements. Showing "N issues to fix" on drafts adds
     * exactly the validator's two reads (the page's drafts, one reference catalog) — and only
     * when the page holds a draft — however many drafts there are.
     */
    public function test_issue_badges_cost_a_fixed_two_extra_statements_not_one_per_draft(): void
    {
        $t = $this->tenant();
        $url = $this->indexUrl($t);
        $this->draft($t, 'First draft');
        $this->get($url)->assertOk(); // warm-up

        $measure = function () use ($url): int {
            $feature = 0;
            \Illuminate\Support\Facades\DB::listen(function () use (&$feature): void {
                if (\App\Http\Controllers\Customer\Business\Concerns\WorkflowFeatureQueryScope::isActive()) {
                    $feature++;
                }
            });
            $this->get($url)->assertOk();

            return $feature;
        };

        $one = $measure();

        for ($i = 0; $i < 8; $i++) {
            $this->breakDraft($this->draft($t, 'Draft ' . $i));
        }

        $this->assertSame($one, $measure(), 'Feature-owned SQL must not grow with the number of drafts.');
        $this->assertLessThanOrEqual(4, $one);
    }

    public function test_a_business_with_no_workflows_gets_the_empty_state_without_filters_or_banner(): void
    {
        $t = $this->tenant();

        $html = $this->get($this->indexUrl($t))->assertOk()->getContent();

        $this->assertStringContainsString('0 workflows', $html);
        $this->assertStringContainsString('No workflows yet', $html);
        $this->assertStringContainsString('Automate repetitive follow-up and customer communication.', $html);
        $this->assertStringNotContainsString('data-role="wf-list-guidance"', $html);
        $this->assertStringNotContainsString('data-role="wf-list-filters"', $html);
        $this->assertStringContainsString('data-role="wf-new-workflow"', $html);
    }

    public function test_another_business_is_still_refused_and_the_json_listing_is_unchanged(): void
    {
        $t = $this->tenant();
        $this->draft($t, 'Mine');
        $other = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($t['customer']);

        $this->get($this->routeUrl('index', $t['workspace'], $other['business']))->assertNotFound();

        $json = $this->getJson($this->indexUrl($t))->assertOk()->json();
        $this->assertSame(['workflows', 'meta'], array_keys($json));
        $this->assertSame('Mine', $json['workflows'][0]['name']);
    }
}
