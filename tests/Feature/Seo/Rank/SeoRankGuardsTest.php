<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankRunState;
use App\Enums\Seo\SeoRankTrigger;
use App\Jobs\Seo\ScheduleSeoRankChecks;
use App\Library\Seo\Rank\Provider\DataForSeoRankProvider;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\SeoRankBudgetDecision;
use App\Library\Seo\Rank\SeoRankCheckExecutor;
use App\Library\Seo\Rank\SeoRankCheckPlanner;
use App\Library\Seo\Rank\SeoRankDashboardReader;
use App\Library\Seo\Rank\SeoRankTrackingBudget;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Seo\SeoPhraseNormalizer;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankProviderLedger;
use App\Models\SeoRankTarget;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * SEO V1 final, review round (R1-R6) — guards that keep paid rank tracking honest:
 *
 *  R1 re-wording a rank-tracked keyword is refused (a position belongs to its exact words);
 *  R2 a switched-off Business buys no check, at schedule, reserve and submit time;
 *  R3 archive stops tracking, reactivate never silently resumes paid checks;
 *  R4 search operators are caught in any letter case;
 *  R5 keywords of an archived Location are not scheduled, not counted, and can be stopped;
 *  R6 the summary cards count only fresh results and say so.
 *
 * Fake provider only: nothing leaves the process.
 */
class SeoRankGuardsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function u(string $name, Workspace $workspace, Business $business, ?string $uid = null): string
    {
        return route('customer.workspaces.businesses.seo.' . $name, array_filter([$workspace->uid, $business->uid, $uid]));
    }

    private function tick(): void
    {
        (new ScheduleSeoRankChecks())->handle(app(SeoRankCheckPlanner::class), app(SeoRankTrackingBudget::class));
    }

    private function html(Workspace $workspace, Business $business): string
    {
        return (string) $this->get($this->u('keywords.index', $workspace, $business))->assertOk()->getContent();
    }

    private function role(string $html, string $role): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $out = [];

        foreach ((new \DOMXPath($doc))->query("//*[@data-role='{$role}']") as $node) {
            $out[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // R1
    // ------------------------------------------------------------------

    public function test_a_rank_tracked_keyword_cannot_be_reworded_so_no_old_position_shows_under_new_words(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $keyword = $this->keyword($owner, $business, 'photo booth rental');
        $target = $this->track($owner, $business, $keyword);
        $this->obs($target, 'organic', 4);
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.update', $workspace, $business, $keyword->uid), ['phrase' => 'wedding photo booth'])
            ->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertStringContainsString('cannot be re-worded', (string) session('message'));
        $this->assertSame('photo booth rental', $keyword->fresh()->phrase);
        $this->assertTrue($target->fresh()->isTracking());
        $this->assertSame(1, SeoRankObservation::query()->where('seo_rank_target_id', $target->id)->count());

        // Letter case alone keeps the same normalized words, so it is allowed.
        $this->post($this->u('keywords.update', $workspace, $business, $keyword->uid), ['phrase' => 'Photo Booth Rental'])->assertSessionHas('status', 'success');
        $this->assertSame('Photo Booth Rental', $keyword->fresh()->phrase);

        // A stopped target still carries history of the old words: still refused.
        $this->post($this->u('rank-targets.stop', $workspace, $business, $target->uid));
        $this->post($this->u('keywords.update', $workspace, $business, $keyword->uid), ['phrase' => 'party booth'])->assertSessionHas('status', 'error');
        $this->assertSame('Photo Booth Rental', $keyword->fresh()->phrase);
    }

    public function test_an_untracked_keyword_can_still_be_reworded(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $keyword = $this->keyword($owner, $business, 'photo booth rental');
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.update', $workspace, $business, $keyword->uid), ['phrase' => 'wedding photo booth'])->assertSessionHas('status', 'success');

        $this->assertSame('wedding photo booth', $keyword->fresh()->phrase);
    }

    // ------------------------------------------------------------------
    // R2
    // ------------------------------------------------------------------

    /** @return array<string, array{0: callable(Business): void}> */
    public static function switchedOff(): array
    {
        return [
            'inactive business' => [fn (Business $b) => DB::table('businesses')->where('id', $b->id)->update(['status' => 'inactive'])],
            'draft business' => [fn (Business $b) => DB::table('businesses')->where('id', $b->id)->update(['status' => 'draft'])],
            'inactive workspace' => [fn (Business $b) => DB::table('workspaces')->where('id', $b->workspace_id)->update(['is_active' => false])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('switchedOff')]
    public function test_a_switched_off_business_buys_no_check_from_the_scheduler(callable $switchOff): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Growth);
        $this->track($owner, $business, $this->keyword($owner, $business));
        $switchOff($business);

        $this->tick();

        $this->assertSame(0, SeoRankCheckRun::query()->count());
        $this->assertSame(0, SeoRankProviderLedger::query()->count(), 'No reservation, so no spend.');
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    public function test_an_active_business_is_still_scheduled_as_the_control(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Growth);
        $this->track($owner, $business, $this->keyword($owner, $business));

        $this->tick();

        $this->assertGreaterThan(0, SeoRankCheckRun::query()->count());
    }

    public function test_the_budget_authority_itself_refuses_a_switched_off_business(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Growth);
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        DB::table('businesses')->where('id', $business->id)->update(['status' => 'inactive']);

        $decision = app(SeoRankTrackingBudget::class)->reserveRun($target->fresh(), SeoRankCheckType::Organic, SeoRankTrigger::Manual, 'k1', (int) $owner->user_id);

        $this->assertFalse($decision->allowed);
        $this->assertSame(SeoRankBudgetDecision::TARGET_INACTIVE, $decision->reason);
        $this->assertSame(0, SeoRankProviderLedger::query()->count());
    }

    public function test_a_job_queued_before_the_business_was_switched_off_refuses_without_calling_the_provider(): void
    {
        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Growth);
        $target = $this->track($owner, $business, $this->keyword($owner, $business));

        // Reserved while the Business was fine (the planner makes the run; nothing is submitted yet).
        $decisions = app(SeoRankCheckPlanner::class)->planScheduled($target->fresh(), CarbonImmutable::now('UTC'));
        $run = collect($decisions)->first(fn ($d) => $d->allowed)->run;
        $this->assertSame(SeoRankRunState::Scheduled, $run->fresh()->state);

        DB::table('businesses')->where('id', $business->id)->update(['status' => 'inactive']);

        app(SeoRankCheckExecutor::class)->submit($run->id);

        $this->assertSame(0, FakeSeoRankProvider::$submitCalls, 'The provider was never asked.');
        $this->assertSame(SeoRankRunState::FailedTerminal, $run->fresh()->state);
        $this->assertSame('released', app(SeoRankCheckExecutor::class)->ledgerStatus($run->fresh()), 'The reservation is released: no spend.');
    }

    // ------------------------------------------------------------------
    // R3
    // ------------------------------------------------------------------

    public function test_archiving_a_tracked_keyword_stops_its_tracking_and_frees_the_slot(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Core);
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.archive', $workspace, $business, $keyword->uid))->assertRedirect();
        $this->assertStringContainsString('Rank tracking for it was stopped', (string) session('message'));
        $this->assertFalse($target->fresh()->isTracking());

        $this->post($this->u('keywords.reactivate', $workspace, $business, $keyword->uid))->assertRedirect();
        $this->assertFalse($target->fresh()->isTracking(), 'Reactivating never silently resumes paid tracking.');
        $this->assertSame(['0 / 5'], $this->role($this->html($workspace, $business), 'summary-tracked'));
    }

    public function test_reactivating_a_legacy_archived_keyword_whose_target_still_tracks_cannot_overfill_the_slots(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Core);
        $manager = app(SeoKeywordManager::class);


        // Archived before archiving stopped tracking: the target is left in "tracking".
        $legacy = $this->keyword($owner, $business, 'legacy keyword');
        $legacyTarget = $this->track($owner, $business, $legacy, self::NAPERVILLE);
        DB::table('seo_keywords')->where('id', $legacy->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);
        $this->authenticateAsSeoCustomer($owner);
        $this->assertNotNull($manager);

        for ($i = 1; $i <= 5; $i++) {
            $this->track($owner, $business, $this->keyword($owner, $business, "keyword {$i}"));
        }

        $this->post($this->u('keywords.reactivate', $workspace, $business, $legacy->uid))->assertRedirect();

        $this->assertFalse($legacyTarget->fresh()->isTracking());
        $this->assertSame(['5 / 5'], $this->role($this->html($workspace, $business), 'summary-tracked'), 'Never "Tracked 6 / 5".');
    }

    // ------------------------------------------------------------------
    // R4
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function mixedCaseOperators(): array
    {
        return [
            'Site' => ['Site:example.com booths'],
            'INTITLE' => ['INTITLE:wedding booth'],
            'Inurl' => ['Inurl:booth rental'],
            'inurl' => ['inurl:booth rental'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mixedCaseOperators')]
    public function test_operators_are_caught_in_any_letter_case_by_the_manager_and_the_provider_guard(string $phrase): void
    {
        $this->assertTrue(SeoPhraseNormalizer::hasSearchOperator($phrase));
        $this->assertTrue(DataForSeoRankProvider::hasSearchOperator($phrase));

        [$owner, $business] = $this->rankTenant(WorkspacePlanTier::Growth);

        try {
            app(SeoKeywordManager::class)->create((int) $owner->user_id, $business, $phrase);
            $this->fail('Refused at create.');
        } catch (\App\Exceptions\Seo\SeoKeywordException $e) {
            $this->assertSame(\App\Exceptions\Seo\SeoKeywordException::SEARCH_OPERATOR, $e->reason);
        }
    }

    public function test_ordinary_lower_case_words_are_not_operators(): void
    {
        foreach (['rock and roll dj', 'photo booth or kiosk hire', 'dj-services chicago', "kid's party photo booth"] as $phrase) {
            $this->assertFalse(SeoPhraseNormalizer::hasSearchOperator($phrase), $phrase);
        }

        $this->assertTrue(SeoPhraseNormalizer::hasSearchOperator('photo booth OR kiosk'), 'Capitalised booleans stay operators.');
    }

    // ------------------------------------------------------------------
    // R5
    // ------------------------------------------------------------------

    public function test_a_keyword_on_an_archived_location_is_not_scheduled_not_counted_and_can_be_stopped(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $location = $this->extraLocation($business, 'Closing Site');
        $keyword = app(SeoKeywordManager::class)->create((int) $owner->user_id, $business, 'closing site booth', $location);
        $target = $this->track($owner, $business, $keyword);
        $this->authenticateAsSeoCustomer($owner);

        $this->assertSame(1, app(SeoRankTrackingBudget::class)->trackedTargetsQuery($business)->count(), 'Control: counted while the Location is open.');

        DB::table('business_locations')->where('id', $location->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);

        $this->assertSame(0, app(SeoRankTrackingBudget::class)->trackedTargetsQuery($business)->count(), 'Its slot is free.');

        $this->tick();
        $this->assertSame(0, SeoRankCheckRun::query()->count(), 'No paid check is bought for it.');

        $decision = app(SeoRankTrackingBudget::class)->reserveRun($target->fresh(), SeoRankCheckType::Organic, SeoRankTrigger::Manual, 'k-archived', (int) $owner->user_id);
        $this->assertFalse($decision->allowed);

        // The owner can still see it and stop it from the list.
        $this->assertSame(['Stop tracking'], $this->role($this->html($workspace, $business), 'rank-stop'));
        $this->post($this->u('rank-targets.stop', $workspace, $business, $target->uid))->assertRedirect();
        $this->assertFalse($target->fresh()->isTracking());
    }

    // ------------------------------------------------------------------
    // R6
    // ------------------------------------------------------------------

    public function test_the_summary_counts_only_fresh_results_and_says_how_many(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $fresh = $this->track($owner, $business, $this->keyword($owner, $business, 'fresh phrase'));
        $old = $this->track($owner, $business, $this->keyword($owner, $business, 'old phrase'));
        $this->obs($fresh, 'organic', 4, now()->subDay());
        $this->obs($old, 'organic', 20, now()->subDays(20));
        DB::table('seo_rank_targets')->where('id', $fresh->id)->update(['last_checked_at' => now()->subDay()]);
        DB::table('seo_rank_targets')->where('id', $old->id)->update(['last_checked_at' => now()->subDays(20)]);
        $this->authenticateAsSeoCustomer($owner);

        $dashboard = app(SeoRankDashboardReader::class)->build(
            $business,
            SeoKeyword::query()->where('business_id', $business->id)->get(),
            app(\App\Library\Seo\Rank\SeoRankEntitlement::class)->planFor($business),
        );

        $this->assertSame(4.0, $dashboard['summary']['average_organic'], 'The 20-day-old #20 is not averaged in as if current.');
        $this->assertSame(1, $dashboard['summary']['top10']);
        $this->assertSame(['fresh' => 1, 'checked' => 2], $dashboard['summary']['basis']);

        $html = $this->html($workspace, $business);
        $this->assertSame(['#4'], $this->role($html, 'summary-average'));
        $this->assertSame(['Based on 1 of 2 keywords checked recently. Older results are shown on their rows but are not counted in these figures.'], $this->role($html, 'summary-basis'));

        // Everything fresh: no caveat.
        DB::table('seo_rank_targets')->where('id', $old->id)->update(['last_checked_at' => now()->subDay()]);
        $this->assertSame([], $this->role($this->html($workspace, $business), 'summary-basis'));
    }

    public function test_when_every_result_is_stale_the_cards_show_dashes_not_old_numbers(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $old = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->obs($old, 'organic', 3, now()->subDays(30));
        DB::table('seo_rank_targets')->where('id', $old->id)->update(['last_checked_at' => now()->subDays(30)]);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->html($workspace, $business);

        $this->assertSame(['—'], $this->role($html, 'summary-average'));
        $this->assertSame(['—'], $this->role($html, 'summary-top10'));
        $this->assertSame(['Based on 0 of 1 keyword checked recently. Older results are shown on their rows but are not counted in these figures.'], $this->role($html, 'summary-basis'));
    }
}
