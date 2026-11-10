<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiGateway;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Opportunity\OpportunityActionHash;
use App\Models\Business;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['business.onboarding.enabled' => true, 'opportunity.enabled' => true]);
    }

    private function noAi(): void
    {
        $this->mock(AiGateway::class, fn (MockInterface $m) => $m->shouldNotReceive('complete'));
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
        $this->noAi();
        $html = $this->html();

        $order = ['data-role="home-greeting"', 'data-role="growth-performance"', 'data-role="growth-next"', 'data-role="home-ask"', 'data-role="growth-activity"', 'data-role="recent-chats"'];
        $last = -1;
        foreach ($order as $marker) {
            $at = strpos($html, $marker);
            $this->assertNotFalse($at, $marker);
            $this->assertGreaterThan($last, $at, $marker . ' is out of the approved order.');
            $last = $at;
        }
        $this->assertSame(1, substr_count($html, 'class="gh-metrics"'), 'One compact performance card.');
        $this->assertSame(1, substr_count($html, 'data-role="home-ask"'), 'One assistant.');
        $this->assertStringContainsString('data-role="headline-period"', $html, 'The reporting period is always labelled.');
    }

    public function test_the_description_move_shows_the_proposal_in_the_red_card_with_truthful_wording_and_no_undo(): void
    {
        $this->withDescriptionMove();
        $this->noAi();
        $html = $this->html();

        $this->assertStringContainsString('data-move="opportunity"', $html);
        $this->assertStringContainsString('data-role="description-proposal"', $html);
        $this->assertStringContainsString('Approve &amp; save', $html);
        $this->assertStringContainsString('Edit first', $html);
        $this->assertStringContainsString('Prepared by MotionGrove · from your saved business details', $html);
        $this->assertStringContainsString('Saves to your MotionGrove profile only', $html);
        // Not claimed: the draft is built from saved facts, not from the website; and no Undo exists for an approved profile change.
        $card = substr($html, (int) strpos($html, 'data-role="growth-next"'), (int) strpos($html, 'data-role="home-ask"') - (int) strpos($html, 'data-role="growth-next"'));
        $this->assertStringNotContainsString('from your website', $card);
        $this->assertStringNotContainsString('Undo', $card);
        // Success is only ever written by the script after the workflow reports `completed`; the markup itself starts hidden and idle.
        $this->assertMatchesRegularExpression('/data-role="description-status" data-state="idle" hidden/', $html);
        $this->assertStringContainsString("j.state === 'completed'", $html);
    }

    public function test_work_feed_invents_nothing_for_a_new_business_and_never_claims_running_or_verified(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Variant B Quiet', 'Variant B Quiet Account');
        $this->authenticateAs($customer);
        $html = $this->html();

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

        foreach (['gh-root', 'gh-metrics', 'gh-nbm', 'data-role="recent-chats"', 'gh-ask-history'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, $marker);
        }
        $this->assertStringContainsString('data-role="home-greeting"', $html);
    }
}
