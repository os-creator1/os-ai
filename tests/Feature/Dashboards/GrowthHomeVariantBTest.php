<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Opportunity\OpportunityActionHash;
use App\Models\Business;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Growth Home — the approved Variant B layout (numbers first, red Best Next Move, Ask, MotionGrove at work, right column).
 * Presentation over the existing Home model: every fact below comes from the real readers, nothing is sample data, and no AI is called.
 */
class GrowthHomeVariantBTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;
    use AssistantTestSupport; // fake AI completion client + stray-HTTP guard (no live AI)

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootAssistant();
        $this->freezeClock();
        config(['business.onboarding.enabled' => true, 'opportunity.enabled' => true]);
    }

    /** An ordinary Home GET never reaches the AI provider: the fake completion client recorded no request. */
    private function noAi(): void
    {
        $this->assertSame(0, $this->aiCalls(), 'Home GET must not call the AI provider.');
    }

    /** A Growth Business whose Best Next Move is the missing description, with a working enquiry path so reach does not outrank it. */
    private function withDescriptionMove(): array
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Variant B Booths', 'Variant B Account');
        app(BusinessLocationRepository::class)->upsertPrimary($business, ['name' => 'Main', 'service_mode' => 'service_area', 'city' => 'Orange', 'region' => 'CA', 'country_code' => 'US']);
        $this->primaryService($business);
        $this->adoptSeoFirstPolicy($business);
        $locationId = (int) DB::table('business_locations')->where('business_id', $business->id)->value('id');
        $formId = DB::table('forms')->insertGetId(['uid' => (string) Str::uuid(), 'business_id' => $business->id, 'name' => 'Enquiry', 'lifecycle_state' => 'active', 'current_version' => 1, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('form_versions')->insert(['form_id' => $formId, 'version' => 1, 'content_hash' => str_repeat('a', 64), 'submit_label' => 'Send', 'success_message' => 'Thanks', 'fields' => '[]', 'created_at' => now()]);
        DB::table('form_deployments')->insert(['uid' => (string) Str::uuid(), 'form_id' => $formId, 'business_location_id' => $locationId, 'source' => 'direct_link', 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $action = ['schema_version' => 1, 'action_key' => 'add_description', 'parameters' => [], 'approval_required' => true, 'completion_policy' => 'system_verified'];
        $this->recommendation($business, [
            'type' => 'missing_description', 'title' => 'Add a description',
            'recommended_action' => json_encode($action), 'recommended_action_hash' => (new OpportunityActionHash())->compute($action), 'action_schema_version' => 1,
        ]);
        $this->authenticateAs($customer);

        return [$customer, $business];
    }

    private function html(): string
    {
        return $this->get(route('user.home'))->assertOk()->getContent();
    }

    public function test_the_page_follows_the_approved_order_and_has_one_compact_performance_card_with_its_period(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Variant B Order', 'Variant B Order Account');
        $this->authenticateAs($customer);
        $html = $this->html();
        $this->noAi();

        // The real reading order. `growth-performance` is the ENCLOSING section of the greeting and the metrics, so it opens before the
        // greeting by design: the greeting is compared with the KPI contents, and the section with what follows it.
        $order = [
            'greeting' => 'id="gh-greeting"',
            'metrics' => 'class="gh-metrics"',
            'next move' => 'data-role="growth-next"',
            'ask' => 'data-role="home-ask"',
            'work' => 'data-role="growth-activity"',
            'recent chats (supporting column)' => 'data-role="recent-chats"',
        ];
        $last = -1;
        foreach ($order as $name => $marker) {
            $at = strpos($html, $marker);
            $this->assertNotFalse($at, $name . ' is missing.');
            $this->assertGreaterThan($last, $at, $name . ' is out of the approved order.');
            $last = $at;
        }
        $this->assertLessThan(strpos($html, 'id="gh-greeting"'), strpos($html, 'data-role="growth-performance"'), 'The performance section encloses the greeting.');
        $this->assertLessThan(strpos($html, 'data-role="growth-next"'), strpos($html, 'class="gh-metrics"'), 'Performance comes before the next move.');
        $this->assertStringContainsString('aria-label="Setup and recent chats"', $html, 'The supporting column renders.');
        $this->assertSame(1, substr_count($html, 'class="gh-metrics"'), 'One compact performance card.');
        $this->assertSame(1, substr_count($html, 'data-role="home-ask"'), 'One assistant.');
        $this->assertStringContainsString('data-role="headline-period"', $html, 'The reporting period is always labelled.');
    }

    /** The page shows whichever move the existing selector chose, and that card carries exactly one valid, authorised action. */
    public function test_the_red_card_carries_exactly_one_valid_action_whatever_the_move(): void
    {
        $this->withDescriptionMove();
        $html = $this->html();
        $this->noAi();

        $this->assertStringContainsString('data-role="next-best-move"', $html);
        $card = substr($html, (int) strpos($html, 'data-role="next-best-move"'), (int) strpos($html, 'data-role="home-ask"') - (int) strpos($html, 'data-role="next-best-move"'));
        $inline = substr_count($card, 'data-role="description-proposal"');
        $link = preg_match_all('#<a class="gh-btn gh-btn--light gh-btn--lg" href="(/|https?://)[^"]+"#', $card);

        $this->assertSame(1, $inline + $link, 'One primary action: the inline description proposal OR the move\'s own authorised link, never both or neither.');
        $this->assertStringNotContainsString('Undo', $card);
        $this->assertStringNotContainsString('from your website', $card);
    }

    /** The shared partial, rendered for each layout with a fixed proposal: Growth is Variant B, Core keeps its original presentation; one behaviour. */
    public function test_the_description_proposal_partial_is_layout_aware_and_keeps_every_protection(): void
    {
        $needs = ['description' => ['uid' => 'opp-1', 'text' => 'Orange Glam Booths provides Photo Booth Rental in Orange, CA.', 'fromFacts' => true, 'base' => str_repeat('a', 64), 'approveUrl' => '/approve', 'improveUrl' => '/improve']];
        $growth = view('customer.dashboard.home.v2._description-proposal', ['needs' => $needs, 'layout' => 'growth', 'next' => ['title' => 'Choose keywords', 'url' => '/keywords']])->render();
        $core = view('customer.dashboard.home.v2._description-proposal', ['needs' => $needs])->render();

        // Growth: the Variant B markup, truthful wording, idle (hidden) status, no Undo.
        $this->assertStringContainsString('class="gh-desc"', $growth);
        $this->assertStringContainsString('Approve &amp; save', $growth);
        $this->assertStringContainsString('Edit first', $growth);
        $this->assertStringContainsString('Prepared by MotionGrove · from your saved business details', $growth);
        $this->assertStringContainsString('Saves to your MotionGrove profile only', $growth);
        $this->assertStringNotContainsString('from your website', $growth);
        $this->assertStringNotContainsString('Undo', $growth);
        $this->assertMatchesRegularExpression('/data-role="description-status" data-state="idle" hidden/', $growth);

        // Core: its original compact presentation, with none of the Growth-only classes in its markup.
        $this->assertStringContainsString('class="hv2-desc"', $core);
        $this->assertStringContainsString('Edit description', $core);
        $this->assertStringContainsString('Saved to your MotionGrove business profile. This does not automatically update Google or publish your website.', $core);
        $this->assertStringNotContainsString('class="gh-', $core);
        $this->assertStringNotContainsString('Undo', $core);

        // Both layouts post the shown-description hash and share one script with every #596 protection and truthful state.
        foreach ([$growth, $core] as $html) {
            $this->assertStringContainsString('data-base="' . str_repeat('a', 64) . '"', $html);
            $this->assertStringContainsString("j.state === 'completed'", $html);
            $this->assertStringContainsString('prevReadOnly', $html);
            $this->assertStringContainsString('Your text changed while AI was working', $html);
            $this->assertStringContainsString("'pending'", $html);
            $this->assertStringContainsString("'failed'", $html);
        }
    }

    public function test_work_feed_invents_nothing_for_a_new_business_and_never_claims_running_or_verified(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Variant B Quiet', 'Variant B Quiet Account');
        $this->authenticateAs($customer);
        $html = $this->html();
        $this->noAi();

        $this->assertStringContainsString('data-role="growth-activity"', $html);
        $this->assertStringNotContainsString('data-role="activity-item"', $html);
        $this->assertStringNotContainsString('data-role="current-work-item"', $html);
        $this->assertStringNotContainsString('gh-st gh-tone-running', $html, 'Nothing is Running unless the backend records active work.');
        $this->assertStringNotContainsString('>Verified<', $html);
    }

    public function test_recent_chats_and_setup_are_never_sample_content(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Variant B Side', 'Variant B Side Account');
        $this->authenticateAs($customer);
        $html = $this->html();
        $this->noAi();

        // Chats are filled from the customer's own saved conversations (the existing history endpoint); the server renders none.
        $this->assertStringContainsString('data-role="recent-chats-list"></div>', $html);
        $this->assertStringContainsString('data-role="recent-chats-empty"', $html);
        $this->assertStringNotContainsString('Is my website ready to go live?', $html);
        $this->assertStringNotContainsString('What should I set up first?', $html);

        $setup = $this->dashboardFor($customer->user)->band(DashboardSnapshot::BAND_COMMAND)['setup'];
        if ($setup === null) {
            $this->assertStringNotContainsString('data-role="growth-setup"', $html, 'An established business never sees onboarding setup.');
        } else {
            $this->assertStringContainsString('<span data-role="growth-setup-count">' . $setup['done'] . ' of ' . $setup['total'] . '</span>', $html, 'The count comes from the journey, never a constant.');
        }
    }

    public function test_core_home_is_unchanged(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core, 'Variant B Core', 'Variant B Core Account');
        $this->authenticateAs($customer);
        $html = $this->html();

        // Markup-only markers: the shared Home stylesheet legitimately mentions `.gh-root` selectors, so class names alone prove nothing.
        foreach (['data-role="growth-home"', 'class="gh-metrics"', 'class="gh-nbm"', 'data-role="recent-chats"', 'data-role="ask-history-link"', 'class="gh-desc"'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, $marker);
        }
        $this->assertStringContainsString('data-role="home-greeting"', $html);
    }
}
