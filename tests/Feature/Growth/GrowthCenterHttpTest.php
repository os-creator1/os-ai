<?php

namespace Tests\Feature\Growth;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Growth\Concerns\CreatesGrowthFixtures;
use Tests\TestCase;

/**
 * The Growth Center surface: the gate chain, what an owner sees, and that
 * Location ACL is applied before anything is counted.
 */
class GrowthCenterHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGrowthFixtures;

    private Customer $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['opportunity.enabled' => true]);
        [$this->owner, $this->business, $this->workspace] = $this->crmTenant();
        $this->primaryLocation = $this->growthLocation();
    }

    private function growth(string $name = 'index', array $extra = []): string
    {
        return route('customer.workspaces.businesses.growth.' . $name, array_merge([$this->workspace->uid, $this->business->uid], $extra));
    }

    /** Home IS the Growth Center: the owner-facing recommendations render here. */
    private function home(): string
    {
        return route('user.home');
    }

    private function asOwner(): void
    {
        $this->authenticateAs($this->owner, ['business_advisor', 'view_contact']);
    }

    // ── Gate chain ───────────────────────────────────────────────────────

    public function test_a_guest_never_sees_the_growth_center(): void
    {
        $response = $this->get($this->growth());

        $this->assertNotSame(200, $response->status());
        $this->assertStringNotContainsString('Growth Center', $response->getContent());
    }

    public function test_a_customer_without_the_advisor_capability_is_refused(): void
    {
        $this->authenticateAs($this->owner, ['view_contact']);

        $this->get($this->growth())->assertStatus(401);
    }

    public function test_a_stranger_gets_a_404_not_a_403(): void
    {
        [$stranger] = $this->crmTenant('Other Co', 'Other WS');
        $this->authenticateAs($stranger, ['business_advisor']);

        $this->get($this->growth())->assertNotFound();
    }

    public function test_every_tab_renders_for_the_owner(): void
    {
        $this->asOwner();

        foreach (['opportunities.index', 'score', 'insights', 'brief'] as $name) {
            $this->get($this->growth($name))->assertOk()->assertSee('Growth Center')->assertSee('See what is helping or holding back growth');
        }
    }

    public function test_the_old_growth_overview_is_an_alias_of_home_and_there_is_no_growth_sidebar_entry(): void
    {
        $this->asOwner();

        $this->get($this->growth())->assertRedirect(route('user.home'));

        $home = $this->get($this->home())->assertOk();
        $home->assertDontSee('href="' . $this->growth() . '"', false);
        $home->assertDontSee('>Results<', false);
    }

    // ── States ───────────────────────────────────────────────────────────

    public function test_first_run_explains_what_it_can_learn_from(): void
    {
        $this->asOwner();

        // Nothing evaluated yet: Home keeps its own next-best-move voice instead of an empty Growth band.
        $this->get($this->home())
            ->assertOk()
            ->assertDontSee('data-band="growth"', false);
    }

    public function test_engine_off_says_so_plainly(): void
    {
        config(['opportunity.enabled' => false]);
        $this->asOwner();

        $this->get($this->growth('opportunities.index'))->assertOk()->assertSee('data-role="engine-off"', false)->assertSee('Growth checks are not running yet');
    }

    public function test_a_healthy_business_sees_good_shape_not_a_blank_page(): void
    {
        $this->asOwner();
        $this->conversation([['incoming', 30], ['outgoing', 20]]);
        $this->conversation([['incoming', 50], ['outgoing', 40]]);
        $this->conversation([['incoming', 60], ['outgoing', 55]]);
        $this->evaluateGrowth();
        // Clear every opportunity the bare fixture legitimately has.
        DB::table('opportunities')->where('business_id', $this->business->id)->update(['freshness' => 'stale', 'stale_at' => now()]);

        $this->get($this->home())
            ->assertOk()
            ->assertSee('data-role="growth-all-clear"', false)
            ->assertSee("You're in good shape.", false);
    }

    public function test_the_overview_answers_the_five_questions(): void
    {
        $this->asOwner();
        $this->unansweredDeal(48, 70000, 'Sarah');
        $this->unansweredDeal(72, 90000, 'Mike');
        $this->unansweredDeal(30, 50000, 'Emily');
        $this->evaluateGrowth();

        $page = $this->get($this->home())->assertOk();

        $page->assertSee('data-band="growth"', false);
        $page->assertSee('Needs your attention');
        $page->assertSee('3 new leads have had no reply for 24+ hours — $2,100 in pipeline value.');   // the factual context
        $page->assertSee('data-role="growth-item-action"', false);                                     // one action
        $page->assertSee('View leads');
        $page->assertSee('data-role="growth-health-score"', false);                                    // health is secondary, still there
        $page->assertSee('data-role="growth-ask-advisor"', false);
        // Owner-facing: no engine vocabulary on Home.
        $page->assertDontSee('HIGH IMPACT');
        $page->assertDontSee('crm.unanswered_new_leads');
        $page->assertDontSee('confidence');
    }

    public function test_unscored_categories_say_not_enough_data_never_a_number(): void
    {
        $this->asOwner();
        $this->unansweredDeal();
        $this->evaluateGrowth();

        $html = $this->get($this->growth('score'))->getContent();

        $this->assertStringContainsString('data-category="ads"', $html);
        $this->assertStringContainsString('Not enough data', $html);
    }

    // ── Opportunities + actions ──────────────────────────────────────────

    public function test_the_opportunity_detail_shows_evidence_and_affected_records(): void
    {
        $this->asOwner();
        $this->unansweredDeal(48, 70000, 'Sarah wedding');
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();

        $this->get($this->growth('opportunities.show', [$o->uid]))
            ->assertOk()
            ->assertSee('Why this matters')
            ->assertSee('data-role="evidence-count"', false)
            ->assertSee('data-role="affected-records"', false)
            ->assertSee('Sarah wedding')
            ->assertSee('What to expect')
            ->assertSee('Nothing here is estimated.');
    }

    public function test_the_primary_action_hands_off_to_the_owning_module(): void
    {
        $this->asOwner();
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();

        $this->get($this->growth('opportunities.go', [$o->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.crm.board', [$this->workspace->uid, $this->business->uid]));
    }

    public function test_snooze_dismiss_and_reopen_use_the_engine_lifecycle(): void
    {
        $this->asOwner();
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();

        $this->post($this->growth('opportunities.snooze', [$o->uid]), ['duration' => '3_days'])->assertRedirect();
        $this->assertSame('snoozed', $o->fresh()->status->value);
        $this->assertNotNull($o->fresh()->snoozed_until);

        DB::table('opportunities')->where('id', $o->id)->update(['status' => 'open', 'snoozed_until' => null]);
        $this->post($this->growth('opportunities.dismiss', [$o->uid]), ['reason' => 'already_handled'])->assertRedirect();
        $this->assertSame('dismissed', $o->fresh()->status->value);
        $this->assertDatabaseHas('opportunity_transitions', ['opportunity_id' => $o->id, 'safe_note' => 'Dismissed: already handled.']);

        $this->post($this->growth('opportunities.reopen', [$o->uid]))->assertRedirect();
        $this->assertSame('open', $o->fresh()->status->value);
    }

    public function test_a_custom_snooze_date_is_validated(): void
    {
        $this->asOwner();
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();

        $this->post($this->growth('opportunities.snooze', [$o->uid]), ['duration' => 'custom', 'until' => now()->subDay()->toDateString()])->assertSessionHasErrors('until');
        $this->post($this->growth('opportunities.snooze', [$o->uid]), ['duration' => 'forever'])->assertSessionHasErrors('duration');
        $this->post($this->growth('opportunities.snooze', [$o->uid]), ['duration' => 'custom', 'until' => now()->addDays(10)->toDateString()])->assertRedirect();
        $this->assertSame('snoozed', $o->fresh()->status->value);
    }

    public function test_a_dismiss_reason_outside_the_closed_list_is_refused(): void
    {
        $this->asOwner();
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = Opportunity::where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();

        $this->post($this->growth('opportunities.dismiss', [$o->uid]), ['reason' => '<script>x</script>'])->assertSessionHasErrors('reason');
        $this->assertSame('open', $o->fresh()->status->value);
    }

    public function test_resolved_by_re_evaluation_shows_in_the_resolved_filter(): void
    {
        $this->asOwner();
        $deal = $this->unansweredDeal();
        $this->evaluateGrowth();
        $this->markDealContacted($deal);
        $this->evaluateGrowth();

        $this->get($this->growth('opportunities.index', [], ) . '?state=resolved')
            ->assertOk()
            ->assertSee('data-state="resolved"', false);
        $this->get($this->growth('opportunities.index') . '?state=open')->assertDontSee('data-rule="crm.unanswered_new_leads:v1"', false);
    }

    public function test_another_business_opportunity_is_never_reachable_by_uid(): void
    {
        $this->asOwner();
        [$otherOwner, $otherBusiness, $otherWorkspace] = $this->crmTenant('Other Co', 'Other WS');
        $this->business = $otherBusiness;
        $this->primaryLocation = $this->growthLocation();
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $foreign = Opportunity::where('business_id', $otherBusiness->id)->where('type', 'crm.unanswered_new_leads:v1')->firstOrFail();

        $this->get(route('customer.workspaces.businesses.growth.opportunities.show', [$this->workspace->uid, $this->business->uid, $foreign->uid]))->assertNotFound();
    }

    public function test_the_business_advisor_queue_never_lists_growth_opportunities(): void
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();

        $top = app(\App\Repositories\Contracts\OpportunityRepository::class)->topForCustomer($this->business->fresh(), 20);

        $this->assertTrue($top->every(fn ($o) => $o->worker_key->value === 'business_advisor'));
    }

    // ── Location ACL ─────────────────────────────────────────────────────

    private function selectedLocationStaff(array $locations): void
    {
        $staff = $this->createCustomer();
        $membership = $this->member($this->workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();

        foreach ($locations as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        }

        $this->authenticateAs($staff, ['business_advisor']);
    }

    public function test_a_selected_location_staff_member_sees_only_their_locations_and_counts_only_those(): void
    {
        $second = $this->secondLocation('Second site');
        $this->unansweredDeal(48, 70000, 'Main deal');
        $this->unansweredDeal(50, 20000, 'Other site deal', $second->id);
        $this->unansweredDeal(55, 30000, 'Other site deal two', $second->id);
        $this->evaluateGrowth();
        $this->selectedLocationStaff([$this->primaryLocation]);

        $page = $this->get($this->growth('opportunities.index'))->assertOk();

        $page->assertSee('1 new lead has had no reply for 24+ hours');
        $page->assertDontSee('2 new leads have had no reply');

        // the tab count is filtered first, aggregated second: it is what THIS actor can see
        preg_match('/data-role="tab-open-count">([0-9]+)</', $page->getContent(), $m);
        // Ad spend figures are Business-wide money data: a Location-restricted actor never sees the ads worker's findings.
        $open = fn () => Opportunity::where('business_id', $this->business->id)->where('freshness', 'current')->where('status', 'open')->where('worker_key', '!=', 'ads');
        $visible = $open()->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $this->primaryLocation->id))->count();

        $this->assertSame($visible, (int) ($m[1] ?? 0), 'the count is what THIS actor can see');
        $this->assertLessThan($open()->count(), $visible, 'and never the Business total');
    }

    public function test_an_inaccessible_locations_opportunity_is_a_404_by_direct_uid(): void
    {
        $second = $this->secondLocation('Second site');
        $this->unansweredDeal(50, 20000, 'Other site deal', $second->id);
        $this->evaluateGrowth();
        $hidden = Opportunity::where('location_id', $second->id)->firstOrFail();
        $this->selectedLocationStaff([$this->primaryLocation]);

        $this->get($this->growth('opportunities.show', [$hidden->uid]))->assertNotFound();
        $this->post($this->growth('opportunities.dismiss', [$hidden->uid]))->assertNotFound();
        $this->get($this->growth('opportunities.go', [$hidden->uid]))->assertNotFound();
    }

    public function test_a_restricted_staff_member_is_never_shown_the_business_wide_score(): void
    {
        $this->secondLocation('Second site');
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $this->selectedLocationStaff([$this->primaryLocation]);

        $this->get($this->home())
            ->assertOk()
            ->assertDontSee('data-role="growth-health-score"', false);
        $this->get($this->growth('score'))->assertOk()->assertSee('data-role="score-restricted"', false);
    }

    public function test_a_location_with_all_locations_granted_sees_everything_including_the_score(): void
    {
        $second = $this->secondLocation('Second site');
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $this->selectedLocationStaff([$this->primaryLocation, $second]);

        $this->get($this->home())->assertOk()->assertSee('data-role="growth-health-score"', false);
    }

    public function test_refresh_queues_an_evaluation_and_never_runs_a_provider(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->asOwner();

        $this->post($this->growth('refresh'))->assertRedirect();

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Growth\RunGrowthEvaluation::class, fn ($j) => $j->businessId === $this->business->id);
    }

    public function test_the_plan_without_the_ai_coo_feature_has_no_growth_center(): void
    {
        [$coreOwner, $coreBusiness, $coreWorkspace] = $this->tenant(WorkspacePlanTier::Core, 'Core Co', 'Core WS');
        DB::table('workspace_plan_features')->where('feature_key', 'ai_coo_basic')->delete();
        \Illuminate\Support\Facades\Cache::flush();
        $this->authenticateAs($coreOwner, ['business_advisor']);

        $this->get(route('customer.workspaces.businesses.growth.index', [$coreWorkspace->uid, $coreBusiness->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.growth.opportunities.index', [$coreWorkspace->uid, $coreBusiness->uid]))->assertNotFound();
    }
}
