<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Seo\SeoKeywordException;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\SeoRankDashboardReader;
use App\Library\Seo\Rank\SeoRankException;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Library\Seo\SeoConfig;
use App\Library\Seo\SeoKeywordManager;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankTarget;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * SEO V1 final (A7, A10) — rank tracking says what is TRUE.
 *
 *  a  no dead link to a page the Business is not entitled to;
 *  b  "the first check is on its way" only when it is;
 *  c  a missing domain is explained instead of "Waiting for first check" forever;
 *  d  search operators are refused where a keyword is saved or tracked;
 *  e  an old position is labelled "may be out of date";
 *  f  no "Tracked 0" cards for a Business that has no rank tracking;
 *  A10 an unknown plan tier never gets more than the strictest limits.
 *
 * Every test runs against the FAKE provider: no request leaves the process.
 */
class SeoRankUxTest extends TestCase
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

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    /** @return list<string> */
    private function texts(string $html, string $query): array
    {
        $out = [];

        foreach ($this->xpath($html)->query($query) as $node) {
            $out[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }

        return $out;
    }

    private function role(string $html, string $role): array
    {
        return $this->texts($html, "//*[@data-role='{$role}']");
    }

    private function cell(string $html, SeoKeyword $keyword, string $label): string
    {
        $found = $this->texts($html, "//tr[@data-uid='{$keyword->uid}']/td[@data-label='{$label}']");
        $this->assertNotEmpty($found, "No '{$label}' cell for {$keyword->phrase}");

        return $found[0];
    }

    private function index(Workspace $workspace, Business $business): string
    {
        return (string) $this->get($this->u('keywords.index', $workspace, $business))->assertOk()->getContent();
    }

    private function setPhone(Business $business, ?string $phone): void
    {
        DB::table('businesses')->where('id', $business->id)->update(['phone' => $phone]);
    }

    private function deEntitle(): void
    {
        DB::table('workspace_plan_features')->where('feature_key', 'seo_rank_tracking')->delete();
    }

    // ------------------------------------------------------------------
    // (a) no dead link
    // ------------------------------------------------------------------

    public function test_a_business_that_lost_rank_tracking_sees_stored_results_with_no_dead_link(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Core);
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        $this->obs($target, 'organic', 9);
        $detailUrl = $this->u('rank-targets.show', $workspace, $business, $target->uid);

        $this->authenticateAsSeoCustomer($owner);
        $this->assertStringContainsString($detailUrl, $this->index($workspace, $business), 'Control: an entitled row links to its detail page.');

        $this->deEntitle();
        $html = $this->index($workspace, $business);

        $this->assertStringNotContainsString('/rank-targets/', $html, 'The detail route 404s without the entitlement, so nothing may point at it.');
        $this->assertSame(0, $this->xpath($html)->query("//tr[@data-uid='{$keyword->uid}']/@data-href")->length, 'The row is not clickable.');
        $this->assertSame([], $this->texts($html, "//tr[@data-uid='{$keyword->uid}']//a[contains(@href,'rank-targets')]"));
        $this->assertNotEmpty($this->role($html, 'rank-value'), 'The stored position stays visible, read-only.');
        $this->assertNotEmpty($this->role($html, 'rank-not-included-notice'));
        $this->get($detailUrl)->assertNotFound();
    }

    // ------------------------------------------------------------------
    // (b) the first-check sentence is the real state
    // ------------------------------------------------------------------

    public function test_the_first_check_message_is_true_when_everything_is_in_place(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO])
            ->assertSessionHas('message', 'Rank tracking started. The first check is on its way.');

        $this->assertGreaterThan(0, SeoRankCheckRun::query()->count(), 'A check really was queued.');
    }

    public function test_with_the_provider_switched_off_no_check_is_promised(): void
    {
        config(['seo.rank_tracking.enabled' => false]);
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);

        $response = $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO]);
        $message = (string) session('message');

        $this->assertStringStartsWith('Rank tracking started. Rank checks are not available right now, so no check will run yet.', $message);
        $this->assertStringNotContainsString('on its way', $message);
        $this->assertSame(0, SeoRankCheckRun::query()->count(), 'No run exists, so none may be promised.');
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
        $this->assertSame(1, SeoRankTarget::query()->count(), 'Tracking is set up and keeps its slot; only the promise changed.');

        // Resuming says the same.
        $target = SeoRankTarget::query()->firstOrFail();
        $this->post($this->u('rank-targets.stop', $workspace, $business, $target->uid));
        $this->post($this->u('rank-targets.restart', $workspace, $business, $target->uid));
        $this->assertStringStartsWith('Rank tracking resumed. Rank checks are not available right now', (string) session('message'));
        $this->assertStringNotContainsString('on its way', (string) session('message'));
    }

    public function test_a_provider_with_no_credentials_is_just_as_unavailable(): void
    {
        FakeSeoRankProvider::$configured = false;
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $keyword = $this->keyword($owner, $business, 'wedding photo booth');
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.store', $workspace, $business), [
            'phrase' => 'neon sign hire', 'track_rank' => '1', 'search_location_code' => (string) self::CHICAGO,
        ]);

        $this->assertStringStartsWith('Keyword added and rank tracking started. Rank checks are not available right now', (string) session('message'));
        $this->assertStringNotContainsString('on its way', (string) session('message'));
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
        $this->assertNotNull($keyword->id);
    }

    public function test_with_no_domain_and_no_phone_nothing_could_be_matched_so_the_message_says_so(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth, null);
        $this->setPhone($business, null);
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO]);
        $message = (string) session('message');

        $this->assertStringStartsWith('Rank tracking started. No check will run yet. Connect your website domain or add a business phone number', $message);
        $this->assertStringNotContainsString('on its way', $message);
        $this->assertSame(0, SeoRankCheckRun::query()->count(), 'No identity means no spend.');
    }

    public function test_a_phone_but_no_domain_gets_a_local_check_and_is_told_organic_needs_the_domain(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth, null);
        $this->setPhone($business, '+13125550100');
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO]);

        $this->assertSame(
            'Rank tracking started. The first local check is on its way. Connect your website domain so we can find your site in results.',
            (string) session('message')
        );
        $this->assertSame(['local'], SeoRankCheckRun::query()->pluck('check_type')->map(fn ($t) => $t instanceof \BackedEnum ? $t->value : $t)->all());
    }

    // ------------------------------------------------------------------
    // (c) the reason, not "waiting" forever
    // ------------------------------------------------------------------

    public function test_without_a_domain_the_organic_cell_and_detail_page_explain_why(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth, null);
        $this->setPhone($business, '+13125550100');
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);
        $organic = $this->cell($html, $keyword, 'Organic');

        $this->assertSame(SeoRankDashboardReader::NO_DOMAIN_REASON, $organic);
        $this->assertStringNotContainsString('Waiting for first check', $organic);
        $this->assertNotSame(SeoRankDashboardReader::NO_DOMAIN_REASON, $this->cell($html, $keyword, 'Local'), 'A phone is enough for the local check.');

        $detail = (string) $this->get($this->u('rank-targets.show', $workspace, $business, $target->uid))->assertOk()->getContent();
        $this->assertStringContainsString('Connect your website domain so we can find your site in results.', $detail);
    }

    public function test_without_a_domain_or_a_phone_both_cells_explain_why(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth, null);
        $this->setPhone($business, null);
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertSame(SeoRankDashboardReader::NO_DOMAIN_REASON, $this->cell($html, $keyword, 'Organic'));
        $this->assertSame(SeoRankDashboardReader::NO_IDENTITY_REASON, $this->cell($html, $keyword, 'Local'));
        $this->assertNotEmpty($this->role($this->get($this->u('rank-targets.show', $workspace, $business, $target->uid))->getContent(), 'rank-needs-identity-notice'));
    }

    public function test_with_an_active_primary_domain_there_is_no_such_reason(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $keyword = $this->keyword($owner, $business);
        $this->track($owner, $business, $keyword);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertStringNotContainsString('Connect your website domain', $html);
    }

    // ------------------------------------------------------------------
    // (d) search operators
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function operatorPhrases(): array
    {
        return [
            'quotes' => ['"photo booth" rental'],
            'site operator' => ['site:example.com photo booth'],
            'exclusion' => ['photo booth -cheap'],
            'OR' => ['photo booth OR kiosk'],
            'AND' => ['photo booth AND kiosk'],
            'wildcard' => ['photo * rental'],
            'pipe' => ['photo|booth'],
        ];
    }

    #[DataProvider('operatorPhrases')]
    public function test_a_keyword_with_a_search_operator_is_refused_with_a_clear_message(string $phrase): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => $phrase])->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertStringStartsWith('Use plain words only.', (string) session('message'));
        $this->assertSame(0, SeoKeyword::query()->count());
    }

    public function test_editing_a_keyword_into_an_operator_is_refused_too_and_plain_hyphens_are_fine(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $keyword = $this->keyword($owner, $business, 'dj-services chicago');
        $this->assertNotNull($keyword->id, 'A hyphen inside a word is not an operator.');
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.update', $workspace, $business, $keyword->uid), ['phrase' => 'dj services -cheap'])->assertSessionHas('status', 'error');
        $this->assertSame('dj-services chicago', $keyword->fresh()->phrase);

        try {
            app(SeoKeywordManager::class)->create((int) $owner->user_id, $business, 'photo booth OR kiosk');
            $this->fail('An operator must be refused at the manager.');
        } catch (SeoKeywordException $e) {
            $this->assertSame(SeoKeywordException::SEARCH_OPERATOR, $e->reason);
        }
    }

    public function test_a_legacy_operator_keyword_is_refused_at_track_time_and_takes_no_slot(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        // Saved before operators were refused: written directly, as an old row would be.
        DB::table('seo_keywords')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'business_location_id' => null,
            'phrase' => '"photo booth" rental', 'phrase_normalized' => '"photo booth" rental',
            'lifecycle_state' => 'active', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacy = SeoKeyword::query()->firstOrFail();
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.rank.track', $workspace, $business, $legacy->uid), ['search_location_code' => self::CHICAGO])
            ->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertStringContainsString('so its rank cannot be checked', (string) session('message'));
        $this->assertSame(0, SeoRankTarget::query()->count(), 'No slot is taken for a check the provider would reject.');
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);

        try {
            app(SeoRankTargetManager::class)->track((int) $owner->user_id, $business, $legacy->uid, self::CHICAGO);
            $this->fail('The manager must refuse it.');
        } catch (SeoRankException $e) {
            $this->assertSame(SeoRankException::SEARCH_OPERATOR, $e->reason);
        }
    }

    // ------------------------------------------------------------------
    // (e) staleness
    // ------------------------------------------------------------------

    private function stale(SeoRankTarget $target, int $days): void
    {
        DB::table('seo_rank_targets')->where('id', $target->id)->update(['last_checked_at' => now()->subDays($days)]);
    }

    public function test_an_old_position_is_labelled_may_be_out_of_date_beyond_the_freshness_window(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $fresh = $this->track($owner, $business, $this->keyword($owner, $business, 'fresh phrase'));
        $old = $this->track($owner, $business, $this->keyword($owner, $business, 'old phrase'));
        $this->obs($fresh, 'organic', 4, now()->subDays(2));
        $this->obs($old, 'organic', 4, now()->subDays(10));
        $this->stale($fresh, 2);
        $this->stale($old, 10);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);
        $rows = SeoKeyword::query()->orderBy('id')->get();

        $this->assertSame([], $this->texts($html, "//tr[@data-uid='{$rows[0]->uid}']//*[@data-role='rank-stale']"), 'Two days is inside the default 7-day window.');
        $this->assertSame(['10 days ago — may be out of date'], $this->texts($html, "//tr[@data-uid='{$rows[1]->uid}']//*[@data-role='rank-stale']"));
        $this->assertSame('#4', $this->cell($html, $rows[1], 'Organic'), 'The old position stays visible; it is only labelled.');

        $detail = (string) $this->get($this->u('rank-targets.show', $workspace, $business, $old->uid))->assertOk()->getContent();
        $this->assertSame(['10 days ago — may be out of date'], $this->role($detail, 'rank-stale'));
    }

    public function test_the_freshness_window_is_configured_in_seo_config_and_fails_closed(): void
    {
        $config = app(SeoConfig::class);

        $this->assertSame(7, $config->rankStaleAfterDays(), 'The documented default.');

        foreach ([0, 1, 91, -3, 'soon', null, 2.5, true] as $bad) {
            config(['seo.rank_tracking.stale_after_days' => $bad]);
            $this->assertSame(7, $config->rankStaleAfterDays(), 'A malformed or out-of-range value falls back to the default: ' . var_export($bad, true));
        }

        config(['seo.rank_tracking.stale_after_days' => '30']);
        $this->assertSame(30, $config->rankStaleAfterDays());

        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth);
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->obs($target, 'organic', 6, now()->subDays(4));
        $this->stale($target, 4);
        $this->authenticateAsSeoCustomer($owner);

        $this->assertStringNotContainsString('may be out of date', $this->index($workspace, $business), 'Four days is fresh in a 30-day window.');

        config(['seo.rank_tracking.stale_after_days' => 3]);
        $this->assertStringContainsString('4 days ago — may be out of date', $this->index($workspace, $business));
    }

    public function test_the_stale_helper_is_exact_at_the_boundary_and_never_invents_a_date(): void
    {
        $now = CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC');

        $this->assertNull(SeoRankDashboardReader::staleDays(null, 7, $now), 'No check yet: no date, no staleness.');
        $this->assertNull(SeoRankDashboardReader::staleDays($now->subDays(7), 7, $now), 'Exactly at the window is still fresh.');
        $this->assertSame(8, SeoRankDashboardReader::staleDays($now->subDays(8), 7, $now));
        $this->assertSame(30, SeoRankDashboardReader::staleDays($now->subDays(30), 7, $now));
    }

    // ------------------------------------------------------------------
    // (f) no zero cards for a Business with no rank tracking
    // ------------------------------------------------------------------

    public function test_a_business_with_no_rank_tracking_shows_no_zero_cards_and_one_explanation(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Core);
        $keyword = $this->keyword($owner, $business);
        $this->deEntitle();
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertSame([], $this->texts($html, "//*[@data-section='rank-summary']"));
        $this->assertSame([], $this->role($html, 'summary-tracked'));
        $this->assertNotEmpty($this->role($html, 'rank-not-included'));
        $this->assertNotEmpty($this->cell($html, $keyword, 'Website'), 'Coverage still shows.');
    }

    public function test_an_entitled_business_keeps_its_summary_cards_even_with_nothing_tracked(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Core);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertSame(['0 / 5'], $this->role($html, 'summary-tracked'));
        $this->assertSame([], $this->role($html, 'rank-not-included'));
    }

    // ------------------------------------------------------------------
    // A10 — the tier mapping fails closed
    // ------------------------------------------------------------------

    public function test_the_tier_mapping_is_one_function_and_unknown_tiers_get_the_strictest_limits(): void
    {
        $config = app(SeoConfig::class);

        $this->assertSame('trial', $config->rankTierFor(WorkspacePlanTier::Growth, true), 'A trial is always the strictest tier.');
        $this->assertSame('trial', $config->rankTierFor(WorkspacePlanTier::Core, true));
        $this->assertSame('core', $config->rankTierFor(WorkspacePlanTier::Core, false));
        $this->assertSame('growth', $config->rankTierFor(WorkspacePlanTier::Growth, false));
        $this->assertSame('growth', $config->rankTierFor(WorkspacePlanTier::Agency, false), 'An Agency Workspace uses the Growth limits.');
        $this->assertSame('trial', $config->rankTierFor(null, false), 'No tier, or one nobody has priced: fail closed.');

        $this->assertSame($config->rankTier('trial'), $config->rankTier('some-future-plan'), 'An unknown key in the limits table is the trial limits too.');
    }

    public function test_plan_names_live_only_in_the_one_mapping_not_in_controllers_or_views(): void
    {
        $root = dirname(__DIR__, 4);
        $files = [
            'app/Http/Controllers/Customer/Business/SeoController.php',
            'app/Http/Controllers/Customer/Business/SeoKeywordsController.php',
            'app/Http/Controllers/Customer/Business/SeoRankTargetsController.php',
            'app/Http/Controllers/Customer/Business/SeoAuditController.php',
            'resources/views/customer/business/seo/keywords.blade.php',
            'resources/views/customer/business/seo/rank-target.blade.php',
            'resources/views/customer/business/seo/audit.blade.php',
            'app/Library/Seo/Rank/SeoRankEntitlement.php',
        ];

        foreach ($files as $relative) {
            $source = (string) file_get_contents($root . '/' . $relative);

            $this->assertDoesNotMatchRegularExpression('/[\'"](core|growth|agency|trial)[\'"]/', $source, "{$relative} must not name a plan; SeoConfig::rankTierFor is the one mapping.");
        }
    }

    public function test_each_tier_gets_its_own_limits_through_the_real_entitlement(): void
    {
        $entitlement = app(\App\Library\Seo\Rank\SeoRankEntitlement::class);

        foreach ([[WorkspacePlanTier::Core, 'core', 5], [WorkspacePlanTier::Growth, 'growth', 20], [WorkspacePlanTier::Agency, 'growth', 20]] as [$tier, $name, $slots]) {
            [, $business] = $this->rankTenant($tier, Str::lower(Str::random(10)) . '.example.test');
            $plan = $entitlement->planFor($business->fresh());

            $this->assertNotNull($plan, $name);
            $this->assertSame($name, $plan->tier);
            $this->assertSame($slots, $plan->trackedTargets);
        }
    }
}
