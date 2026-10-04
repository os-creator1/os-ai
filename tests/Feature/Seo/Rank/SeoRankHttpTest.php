<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoRankRunState;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankProviderException;
use App\Library\Seo\Rank\SeoRankCheckExecutor;
use App\Library\Seo\Rank\SeoSearchConsoleReader;
use App\Library\Seo\SeoKeywordManager;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankLocation;
use App\Models\SeoRankObservation;
use App\Models\SeoRankProviderLedger;
use App\Models\SeoRankTarget;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\TestCase;

/**
 * SEO Keyword Rank Tracking V1 over HTTP: the real controllers and the real
 * entitlement against the FAKE provider (no network). Covers the dashboard
 * states, the add/track/stop/restart/check flows, the search-location lookup,
 * the detail page, the Search Console seam, ACL/tenancy and output safety, plus
 * the admin provider-cost page.
 *
 * The queue is sync in tests: dispatching ScheduleSeoRankChecks/SubmitSeoRankCheck
 * runs inline against the fake provider and leaves the runs `submitted` (the poll
 * is a separate step, driven here by completeRuns()).
 */
class SeoRankHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;

    private const GSC_POSITION = '9.6';

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function u(string $name, Workspace $workspace, Business $business, ?string $uid = null): string
    {
        $parameters = [$workspace->uid, $business->uid];

        if ($uid !== null) {
            $parameters[] = $uid;
        }

        return route('customer.workspaces.businesses.seo.' . $name, $parameters);
    }

    /** @return array{0: \App\Models\Customer, 1: Business, 2: Workspace} */
    private function coreTenant(): array
    {
        return $this->rankTenant(WorkspacePlanTier::Core);
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    /** @return list<string> whitespace-normalised text of every node matching the XPath */
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

    private function first(string $html, string $role): ?string
    {
        return $this->role($html, $role)[0] ?? null;
    }

    private function cell(string $html, SeoKeyword $keyword, string $label): string
    {
        $found = $this->texts($html, "//tr[@data-uid='{$keyword->uid}']/td[@data-label='{$label}']");
        $this->assertNotEmpty($found, "No '{$label}' cell for {$keyword->phrase}");

        return $found[0];
    }

    private function rowState(string $html, SeoKeyword $keyword): ?string
    {
        $nodes = $this->xpath($html)->query("//tr[@data-uid='{$keyword->uid}']/@data-rank-state");

        return $nodes->length ? $nodes->item(0)->nodeValue : null;
    }

    private function index(Workspace $workspace, Business $business): string
    {
        return (string) $this->get($this->u('keywords.index', $workspace, $business))->assertOk()->getContent();
    }

    private function detail(Workspace $workspace, Business $business, SeoRankTarget $target): string
    {
        return (string) $this->get($this->u('rank-targets.show', $workspace, $business, $target->uid))->assertOk()->getContent();
    }

    /** A completed run + its observation, directly (no pipeline). */
    private function observe(SeoRankTarget $target, string $type, string $status, ?int $position, ?CarbonInterface $at = null): SeoRankObservation
    {
        $at ??= now();
        $depth = $type === 'organic' ? 100 : 10;

        $run = new SeoRankCheckRun();
        $run->forceFill([
            'business_id' => $target->business_id,
            'seo_rank_target_id' => $target->id,
            'check_type' => $type,
            'trigger' => 'scheduled',
            'idempotency_key' => 'obs:' . Str::uuid(),
            'state' => SeoRankRunState::Completed->value,
            'provider' => 'dataforseo',
            'depth' => $depth,
            'completed_at' => $at,
        ])->save();

        $observation = new SeoRankObservation();
        $observation->forceFill([
            'business_id' => $target->business_id,
            'seo_rank_target_id' => $target->id,
            'seo_rank_check_run_id' => $run->id,
            'check_type' => $type,
            'status' => $status,
            'position' => $position,
            'result_path' => $position !== null ? '/rentals' : null,
            'depth_checked' => $depth,
            'provider' => 'dataforseo',
            'search_location_code' => $target->search_location_code,
            'device' => 'mobile',
            'checked_at' => $at,
        ])->save();

        return $observation;
    }

    /** A closed run carrying real spend in the platform ledger. */
    private function spend(SeoRankTarget $target, int $micros, string $status = 'committed', ?int $actual = null): SeoRankProviderLedger
    {
        $run = new SeoRankCheckRun();
        $run->forceFill([
            'business_id' => $target->business_id,
            'seo_rank_target_id' => $target->id,
            'check_type' => 'organic',
            'trigger' => 'scheduled',
            'idempotency_key' => 'spend:' . Str::uuid(),
            'state' => SeoRankRunState::Completed->value,
            'provider' => 'dataforseo',
            'depth' => 100,
            'reserved_micros' => $micros,
        ])->save();

        $ledger = new SeoRankProviderLedger();
        $ledger->forceFill([
            'business_id' => $target->business_id,
            'workspace_id' => null,
            'provider' => 'dataforseo',
            'operation' => 'organic_serp',
            'seo_rank_check_run_id' => $run->id,
            'usage_month' => now('UTC')->format('Y-m'),
            'usage_day' => now('UTC')->toDateString(),
            'reserved_micros' => $micros,
            'actual_micros' => $actual,
            'status' => $status,
        ])->save();

        return $ledger;
    }

    /** Polls every submitted run to completion (the fake provider answers at once). */
    private function completeRuns(): void
    {
        $executor = app(SeoRankCheckExecutor::class);

        foreach (SeoRankCheckRun::query()->orderBy('id')->get() as $run) {
            SeoRankCheckRun::query()->whereKey($run->id)->update(['next_attempt_at' => now()->subMinute()]);
            $executor->poll($run->id);
        }
    }

    private function counts(): array
    {
        return [
            SeoRankCheckRun::query()->count(),
            SeoRankProviderLedger::query()->count(),
            FakeSeoRankProvider::$submitCalls,
        ];
    }

    private function actingAsAdmin(): void
    {
        $admin = User::create([
            'first_name' => 'Rank', 'last_name' => 'Admin',
            'email' => 'rankadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);
    }

    // ------------------------------------------------------------------
    // dashboard
    // ------------------------------------------------------------------

    public function test_the_empty_dashboard_shows_header_dashes_and_slots_never_zero(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertStringContainsString('Search keywords', $html);
        $this->assertStringContainsString('see how your visibility changes over time', (string) $this->first($html, 'page-subtitle'));
        $this->assertSame('—', $this->first($html, 'summary-average'));
        $this->assertSame('—', $this->first($html, 'summary-top10'));
        $this->assertSame('—', $this->first($html, 'summary-improved'));
        $this->assertSame('—', $this->first($html, 'summary-local-top3'));
        $this->assertSame('0 / 5', $this->first($html, 'summary-tracked'));
        $this->assertSame('You have no keywords yet.', $this->first($html, 'no-keywords'));
        $this->assertStringNotContainsString('This does not show search rankings', $html);
    }

    public function test_a_keyword_with_no_rank_tracking_is_an_seo_target_and_keeps_website_coverage(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business, 'photo booth rental');
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $headers = $this->texts($html, "//table[@data-role='rank-table']/thead//th");
        $this->assertSame(['KEYWORD', 'SEARCH LOCATION', 'ORGANIC', 'LOCAL', 'CHANGE', 'WEBSITE', 'LAST CHECKED', 'ACTIONS'], array_map('mb_strtoupper', $headers));

        $this->assertSame('untracked', $this->rowState($html, $keyword));
        $this->assertStringContainsString('SEO target', $this->cell($html, $keyword, 'Keyword'));
        $this->assertSame('—', $this->cell($html, $keyword, 'Search location'));
        $this->assertSame('—', $this->cell($html, $keyword, 'Organic'));
        $this->assertSame('—', $this->cell($html, $keyword, 'Local'));
        $this->assertSame('—', $this->cell($html, $keyword, 'Change'));
        $this->assertNotSame('—', $this->cell($html, $keyword, 'Website'), 'Website coverage is still shown for an untracked keyword.');
        $this->assertNotEmpty($this->texts($html, "//tr[@data-uid='{$keyword->uid}']//*[@data-role='keyword-coverage']"));
        $this->assertSame('—', $this->cell($html, $keyword, 'Last checked'));
        $this->assertNotEmpty($this->texts($html, "//tr[@data-uid='{$keyword->uid}']//*[@data-role='rank-start']"));
    }

    public function test_every_table_cell_carries_a_data_label_for_the_mobile_card_layout(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $cells = $this->xpath($html)->query("//table[@data-role='rank-table']/tbody/tr/td");
        $this->assertSame(8, $cells->length);

        $labels = [];
        foreach ($cells as $cell) {
            $labels[] = $cell->attributes->getNamedItem('data-label')?->nodeValue;
        }

        $this->assertSame(['Keyword', 'Search location', 'Organic', 'Local', 'Change', 'Website', 'Last checked', 'Actions'], $labels);
        $this->assertStringContainsString('attr(data-label)', $html);
        $this->assertStringContainsString('max-width: 767.98px', $html);
    }

    public function test_a_tracked_keyword_with_no_run_yet_says_waiting_and_with_the_switch_off_budget_paused(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $this->track($owner, $business, $keyword);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertSame('waiting', $this->rowState($html, $keyword));
        $this->assertSame('Waiting for first check', $this->cell($html, $keyword, 'Organic'));
        $this->assertSame('Waiting for first check', $this->cell($html, $keyword, 'Local'));
        $this->assertSame('1 / 5', $this->first($html, 'summary-tracked'));
        $this->assertStringStartsWith('Chicago, Illinois, United States', $this->cell($html, $keyword, 'Search location'));
        $this->assertStringContainsString('Google · mobile', $this->cell($html, $keyword, 'Search location'));
        $this->assertSame([], $this->role($html, 'rank-paused-notice'));

        // Master switch off: nothing can be checked, so the page says UNAVAILABLE — it is
        // neither "waiting" nor a spend pause ("usage period resets" would be untrue).
        config(['seo.rank_tracking.enabled' => false]);
        $html = $this->index($workspace, $business);

        $this->assertSame('unavailable', $this->rowState($html, $keyword));
        $this->assertSame('Checks unavailable', $this->cell($html, $keyword, 'Organic'));
        $this->assertStringContainsString('Rank checks are not available right now', (string) $this->first($html, 'rank-unavailable-notice'));
        $this->assertSame([], $this->role($html, 'rank-paused-notice'));
    }

    public function test_the_switch_on_but_credentials_missing_is_also_unavailable_and_makes_no_provider_call(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        \App\Library\Seo\Rank\Provider\FakeSeoRankProvider::$configured = false;
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertSame('unavailable', $this->rowState($html, $keyword));
        $this->assertNotSame([], $this->role($html, 'rank-unavailable-notice'));

        // Check now is refused with the unavailable copy and creates no run, ledger row or call.
        $this->post($this->u('rank-targets.check', $workspace, $business, $target->uid))
            ->assertRedirect()
            ->assertSessionHas('message', 'Rank checks are not available right now. Your existing results stay visible.');
        $this->assertSame(0, SeoRankCheckRun::query()->count());
        $this->assertSame(0, SeoRankProviderLedger::query()->count());
        $this->assertSame(0, \App\Library\Seo\Rank\Provider\FakeSeoRankProvider::$submitCalls);
    }

    public function test_a_pending_first_check_shows_checking(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);
        FakeSeoRankProvider::$completeImmediately = false;

        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO])->assertRedirect();

        $html = $this->index($workspace, $business);

        $this->assertSame('checking', $this->rowState($html, $keyword));
        $this->assertSame('Checking…', $this->cell($html, $keyword, 'Organic'));
        $this->assertSame('Checking…', $this->cell($html, $keyword, 'Local'));
        $this->assertSame(SeoRankRunState::Submitted, SeoRankCheckRun::query()->where('check_type', 'organic')->firstOrFail()->state);
    }

    public function test_a_completed_check_shows_organic_and_local_ranks_and_the_summary(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        $this->observe($target, 'organic', 'found', 7);
        $this->observe($target, 'local', 'found', 3);
        $target->forceFill(['last_checked_at' => now()])->save();
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertSame('active', $this->rowState($html, $keyword));
        $this->assertSame('#7', $this->cell($html, $keyword, 'Organic'));
        $this->assertSame('Local #3', $this->cell($html, $keyword, 'Local'));
        $this->assertSame('Today', $this->cell($html, $keyword, 'Last checked'));
        $this->assertSame('#7', $this->first($html, 'summary-average'));
        $this->assertSame('1', $this->first($html, 'summary-top10'));
        $this->assertSame('1', $this->first($html, 'summary-local-top3'));
        $this->assertSame('0', $this->first($html, 'summary-improved'));
    }

    public function test_improvements_and_drops_are_shown_as_arrows_with_the_amount(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $up = $this->keyword($owner, $business, 'photo booth rental');
        $down = $this->keyword($owner, $business, 'wedding photo booth');
        $upTarget = $this->track($owner, $business, $up);
        $downTarget = $this->track($owner, $business, $down);
        $this->observe($upTarget, 'organic', 'found', 12, now()->subDays(2));
        $this->observe($upTarget, 'organic', 'found', 7, now());
        $this->observe($downTarget, 'organic', 'found', 3, now()->subDays(2));
        $this->observe($downTarget, 'organic', 'found', 7, now());
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertSame('↑ 5', $this->cell($html, $up, 'Change'));
        $this->assertSame('↓ 4', $this->cell($html, $down, 'Change'));
        $this->assertSame('1', $this->first($html, 'summary-improved'));
        $this->assertSame('2', $this->first($html, 'summary-top10'));
    }

    public function test_absence_is_a_word_never_a_number(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        $this->observe($target, 'organic', 'not_found', null);
        $this->observe($target, 'local', 'not_matched', null);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertSame('Not in top 100', $this->cell($html, $keyword, 'Organic'));
        $this->assertSame('Not matched', $this->cell($html, $keyword, 'Local'));
        $this->assertSame('—', $this->first($html, 'summary-average'));
        $table = implode(' ', $this->texts($html, "//table[@data-role='rank-table']"));
        $this->assertDoesNotMatchRegularExpression('/#0(?!\d)|#101/', $table);
        $this->assertDoesNotMatchRegularExpression('/#0(?!\d)|#101/', implode(' ', $this->texts($html, "//*[@data-section='rank-summary']")));
    }

    public function test_a_stopped_keyword_is_paused_and_frees_its_slot(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('rank-targets.stop', $workspace, $business, $target->uid))
            ->assertRedirect($this->u('keywords.index', $workspace, $business))
            ->assertSessionHas('message', 'Rank tracking stopped. Your history is kept and the slot is free.');

        $html = $this->index($workspace, $business);

        $this->assertSame('paused', $this->rowState($html, $keyword));
        $this->assertSame('Paused', $this->cell($html, $keyword, 'Organic'));
        $this->assertStringContainsString('Rank tracking stopped', $this->cell($html, $keyword, 'Keyword'));
        $this->assertSame('0 / 5', $this->first($html, 'summary-tracked'));
        $this->assertNotEmpty($this->texts($html, "//tr[@data-uid='{$keyword->uid}']//*[@data-role='rank-restart']"));
    }

    public function test_an_exhausted_business_cap_shows_budget_paused_and_the_exact_notice(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $paused = $this->keyword($owner, $business, 'photo booth rental');
        $withResult = $this->keyword($owner, $business, 'wedding photo booth');
        $pausedTarget = $this->track($owner, $business, $paused);
        $resultTarget = $this->track($owner, $business, $withResult);
        $this->observe($resultTarget, 'organic', 'found', 4);
        $this->spend($pausedTarget, 1_500_000);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertSame('Rank checks paused until your usage period resets. Your latest results stay visible.', $this->first($html, 'rank-paused-notice'));
        $this->assertSame('budget_paused', $this->rowState($html, $paused));
        $this->assertSame('Budget paused', $this->cell($html, $paused, 'Organic'));
        $this->assertSame('#4', $this->cell($html, $withResult, 'Organic'), 'Existing results stay visible while paused.');
    }

    // ------------------------------------------------------------------
    // add keyword / track / stop / restart
    // ------------------------------------------------------------------

    public function test_adding_a_keyword_with_rank_tracking_starts_tracking_and_queues_the_first_check(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $this->authenticateAsSeoCustomer($owner);
        FakeSeoRankProvider::$completeImmediately = false;

        $this->post($this->u('keywords.store', $workspace, $business), [
            'phrase' => 'photo booth rental',
            'track_rank' => '1',
            'search_location_code' => (string) self::CHICAGO,
        ])->assertRedirect($this->u('keywords.index', $workspace, $business))
            ->assertSessionHas('message', 'Keyword added and rank tracking started. The first check is on its way.');

        $keyword = SeoKeyword::query()->where('phrase', 'photo booth rental')->firstOrFail();
        $target = SeoRankTarget::query()->where('seo_keyword_id', $keyword->id)->firstOrFail();

        $this->assertTrue($target->isTracking());
        $this->assertSame(self::CHICAGO, (int) $target->search_location_code);
        $this->assertSame('mobile', $target->device);
        $this->assertSame(2, SeoRankCheckRun::query()->where('seo_rank_target_id', $target->id)->count());
        $this->assertSame(2, SeoRankProviderLedger::query()->count());
        $this->assertSame(2, FakeSeoRankProvider::$submitCalls);
        $this->assertSame('checking', $this->rowState($this->index($workspace, $business), $keyword));
    }

    public function test_adding_a_keyword_without_a_checkbox_is_just_an_seo_keyword(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => 'photo booth rental', 'track_rank' => '0', 'search_location_code' => (string) self::CHICAGO])
            ->assertSessionHas('message', 'Keyword added.');

        $this->assertSame(1, SeoKeyword::query()->count());
        $this->assertSame(0, SeoRankTarget::query()->count());
        $this->assertSame([0, 0, 0], $this->counts());
    }

    public function test_with_every_slot_used_a_new_keyword_is_saved_untracked_and_the_owner_is_told_why(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();

        for ($i = 1; $i <= 5; $i++) {
            $this->track($owner, $business, $this->keyword($owner, $business, "slot keyword {$i}"));
        }

        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);
        $this->assertSame('5 / 5', $this->first($html, 'summary-tracked'));
        $this->assertStringContainsString('5 of 5 rank-tracked keywords are in use', (string) $this->first($html, 'slots-full-notice'));

        $response = $this->post($this->u('keywords.store', $workspace, $business), [
            'phrase' => 'sixth keyword',
            'track_rank' => '1',
            'search_location_code' => (string) self::CHICAGO,
        ])->assertRedirect();

        $message = (string) session('message');
        $this->assertStringContainsString('Keyword saved', $message);
        $this->assertStringContainsString('Rank tracking off', $message);
        $this->assertStringContainsString('5 of 5 rank-tracked keywords are in use', $message);

        $sixth = SeoKeyword::query()->where('phrase', 'sixth keyword')->firstOrFail();
        $this->assertSame(0, SeoRankTarget::query()->where('seo_keyword_id', $sixth->id)->count());
        $this->assertSame(5, SeoRankTarget::query()->count());
        $this->assertSame([0, 0, 0], $this->counts());
    }

    public function test_tracking_an_existing_keyword_stopping_and_restarting_keeps_history_and_the_same_target(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO])
            ->assertRedirect($this->u('keywords.index', $workspace, $business))
            ->assertSessionHas('message', 'Rank tracking started. The first check is on its way.');

        $target = SeoRankTarget::query()->firstOrFail();
        $this->assertTrue($target->isTracking());
        $this->assertSame(2, SeoRankCheckRun::query()->count());

        $this->observe($target, 'organic', 'found', 9);

        $this->post($this->u('rank-targets.stop', $workspace, $business, $target->uid))->assertRedirect();
        $this->assertFalse($target->fresh()->isTracking());
        $this->assertSame(1, SeoRankObservation::query()->count(), 'History is kept.');
        $this->assertSame('0 / 5', $this->first($this->index($workspace, $business), 'summary-tracked'), 'The slot is freed.');

        $this->post($this->u('rank-targets.restart', $workspace, $business, $target->uid))
            ->assertRedirect()
            ->assertSessionHas('message', 'Rank tracking resumed.');

        $this->assertTrue($target->fresh()->isTracking());
        $this->assertSame(1, SeoRankTarget::query()->count(), 'Restart resumes the same target.');
        $this->assertSame($target->uid, SeoRankTarget::query()->firstOrFail()->uid);
        $this->assertSame(1, SeoRankObservation::query()->count());
        $this->assertSame('1 / 5', $this->first($this->index($workspace, $business), 'summary-tracked'));
    }

    // ------------------------------------------------------------------
    // Check now
    // ------------------------------------------------------------------

    /** The two same-day manual copies; see the report: the idempotency key is consulted before pending/recent. */
    private const SAME_DAY_COPIES = [
        'Checking… a check is already in progress.',
        'A check for this period is already scheduled.',
    ];

    public function test_check_now_is_allowed_first_and_a_same_day_repeat_creates_no_new_spend(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->authenticateAsSeoCustomer($owner);
        $check = $this->u('rank-targets.check', $workspace, $business, $target->uid);
        $show = $this->u('rank-targets.show', $workspace, $business, $target->uid);

        $this->post($check)->assertRedirect($show)
            ->assertSessionHas('status', 'success')
            ->assertSessionHas('message', 'Checking… results will appear here when the check completes.');
        $afterFirst = $this->counts();
        $this->assertSame(2, $afterFirst[0]);
        $this->assertSame(2, $afterFirst[1]);
        $this->assertSame(['manual'], SeoRankCheckRun::query()->get()->pluck('trigger')->map->value->unique()->values()->all());

        // Still in flight.
        $this->post($check)->assertRedirect($show)->assertSessionHas('status', 'error');
        $this->assertContains((string) session('message'), self::SAME_DAY_COPIES);
        $this->assertSame($afterFirst, $this->counts());

        // Completed: a same-day repeat still creates nothing.
        $this->completeRuns();
        $this->assertSame(2, SeoRankObservation::query()->count());
        $this->post($check)->assertRedirect($show)->assertSessionHas('status', 'error');
        $this->assertSame($afterFirst, $this->counts());

        // The detail page offers the button again but a second click is harmless.
        $this->assertNotEmpty($this->role($this->detail($workspace, $business, $target), 'check-now'));
    }

    public function test_a_check_in_flight_from_the_schedule_shows_the_in_progress_copy(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);
        FakeSeoRankProvider::$completeImmediately = false;
        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO])->assertRedirect();
        $target = SeoRankTarget::query()->firstOrFail();
        $before = $this->counts();

        $this->post($this->u('rank-targets.check', $workspace, $business, $target->uid))
            ->assertRedirect($this->u('rank-targets.show', $workspace, $business, $target->uid))
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', 'Checking… a check is already in progress.');

        $this->assertSame($before, $this->counts());
        $this->assertSame(2, SeoRankCheckRun::query()->where('trigger', 'scheduled')->count());
    }

    public function test_a_recent_result_shows_the_recent_copy_and_creates_nothing(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->observe($target, 'organic', 'found', 7, now()->subHours(3));
        $this->authenticateAsSeoCustomer($owner);
        $before = $this->counts();

        $this->post($this->u('rank-targets.check', $workspace, $business, $target->uid))
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', 'This keyword was checked in the last 24 hours, so the latest result is shown. You can refresh it again later.');

        $this->assertSame($before, $this->counts());

        // Older than the cooldown: allowed again.
        SeoRankObservation::query()->update(['checked_at' => now()->subHours(30)]);
        $this->post($this->u('rank-targets.check', $workspace, $business, $target->uid))->assertSessionHas('status', 'success');
        $this->assertSame(2, SeoRankCheckRun::query()->where('trigger', 'manual')->count());
    }

    public function test_a_recent_manual_run_from_another_day_triggers_the_cooldown_copy_without_a_result(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->authenticateAsSeoCustomer($owner);
        $check = $this->u('rank-targets.check', $workspace, $business, $target->uid);

        $this->post($check)->assertSessionHas('status', 'success');
        // The runs closed without a result (cost not released) and belong to the previous UTC day's key.
        SeoRankCheckRun::query()->update(['state' => SeoRankRunState::FailedTerminal->value, 'failed_at' => now(), 'error_code' => 'provider_task_failed']);
        foreach (SeoRankCheckRun::query()->get() as $run) {
            SeoRankCheckRun::query()->whereKey($run->id)->update(['idempotency_key' => 'prev-' . $run->idempotency_key]);
        }
        $before = $this->counts();

        $this->post($check)->assertSessionHas('status', 'error');
        $this->assertStringContainsString('checked in the last 24 hours', (string) session('message'));
        $this->assertSame($before, $this->counts());
    }

    public function test_check_now_with_the_cap_reached_says_paused_and_creates_nothing(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->spend($target, 1_500_000);
        $this->authenticateAsSeoCustomer($owner);
        $before = $this->counts();

        $this->post($this->u('rank-targets.check', $workspace, $business, $target->uid))
            ->assertRedirect($this->u('rank-targets.show', $workspace, $business, $target->uid))
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', 'Rank checks paused until your usage period resets.');

        $this->assertSame($before, $this->counts());
    }

    public function test_check_now_on_a_stopped_target_asks_to_start_tracking_first(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->authenticateAsSeoCustomer($owner);
        $this->post($this->u('rank-targets.stop', $workspace, $business, $target->uid));

        $this->post($this->u('rank-targets.check', $workspace, $business, $target->uid))
            ->assertSessionHas('message', 'Start tracking this keyword before checking it.');

        $this->assertSame([0, 0, 0], $this->counts());
    }

    // ------------------------------------------------------------------
    // location search + forged location
    // ------------------------------------------------------------------

    public function test_the_location_search_returns_cached_us_cities_only_and_makes_no_provider_call(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $this->seedLocation(5001, 'Chicago Heights,Illinois,United States');
        $this->seedLocation(5002, 'Illinois,United States', 'State');
        $abroad = $this->seedLocation(5003, 'Chicoutimi,Quebec,Canada');
        $abroad->forceFill(['country_iso' => 'CA'])->save();
        $this->authenticateAsSeoCustomer($owner);
        Http::fake();

        $response = $this->getJson($this->u('keywords.rank-locations', $workspace, $business) . '?q=Chi')->assertOk();

        $this->assertSame([
            ['code' => 5001, 'label' => 'Chicago Heights, Illinois, United States'],
            ['code' => self::CHICAGO, 'label' => 'Chicago, Illinois, United States'],
        ], $response->json('locations'));

        $this->getJson($this->u('keywords.rank-locations', $workspace, $business) . '?q=C')->assertOk()->assertExactJson(['locations' => []]);
        $this->getJson($this->u('keywords.rank-locations', $workspace, $business))->assertOk()->assertExactJson(['locations' => []]);

        Http::assertNothingSent();
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
        $this->assertSame(0, FakeSeoRankProvider::$fetchCalls);
    }

    public function test_the_location_search_needs_view_seo(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $this->authenticateAsSeoCustomer($owner, ['website']);

        $this->getJson($this->u('keywords.rank-locations', $workspace, $business) . '?q=Chicago')->assertUnauthorized();
    }

    public function test_tracking_with_an_unknown_or_forged_location_code_is_refused_without_any_spend(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $this->seedLocation(5002, 'Illinois,United States', 'State');
        $this->authenticateAsSeoCustomer($owner);

        foreach ([999999, 5002] as $code) {
            $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => $code])
                ->assertRedirect($this->u('keywords.index', $workspace, $business))
                ->assertSessionHas('status', 'error')
                ->assertSessionHas('message', 'Choose a search location from the list.');
        }

        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => 'Chicago'])->assertSessionHasErrors('search_location_code');
        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), [])->assertSessionHasErrors('search_location_code');

        $this->assertSame(0, SeoRankTarget::query()->count());
        $this->assertSame([0, 0, 0], $this->counts());

        // From the add form: the keyword is saved, tracking stays off.
        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => 'new phrase', 'track_rank' => '1', 'search_location_code' => '999999']);
        $this->assertStringContainsString('Rank tracking off', (string) session('message'));
        $this->assertStringContainsString('Choose a search location from the list.', (string) session('message'));
        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => 'other phrase', 'track_rank' => '1']);
        $this->assertStringContainsString('Rank tracking off', (string) session('message'));
        $this->assertStringContainsString('Choose a search location from the list', (string) session('message'));

        $this->assertSame(3, SeoKeyword::query()->count());
        $this->assertSame(0, SeoRankTarget::query()->count());
        $this->assertSame([0, 0, 0], $this->counts());
    }

    // ------------------------------------------------------------------
    // detail page
    // ------------------------------------------------------------------

    private function detailFixture(): array
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business, 'photo booth rental');
        $target = $this->track($owner, $business, $keyword);

        $this->observe($target, 'organic', 'found', 4, now()->subDays(10));
        $this->observe($target, 'organic', 'not_found', null, now()->subDays(6));
        $this->observe($target, 'organic', 'found', 12, now()->subDays(3));
        $this->observe($target, 'organic', 'found', 7, now());
        $this->observe($target, 'local', 'found', 5, now()->subDays(3));
        $this->observe($target, 'local', 'found', 3, now());
        $target->forceFill(['last_checked_at' => now()])->save();

        return [$owner, $business, $workspace, $keyword, $target->fresh()];
    }

    public function test_the_detail_page_shows_current_previous_best_dates_location_device_and_the_chart(): void
    {
        [$owner, $business, $workspace, $keyword, $target] = $this->detailFixture();
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->detail($workspace, $business, $target);

        $this->assertSame('photo booth rental', $this->first($html, 'detail-keyword'));
        $this->assertSame('Chicago, Illinois, United States · Google · mobile', $this->first($html, 'detail-location'));
        $this->assertSame('#7', $this->first($html, 'current-organic'));
        $this->assertSame('Local #3', $this->first($html, 'current-local'));
        $this->assertSame('#12', $this->first($html, 'previous-organic'));
        $this->assertSame('#4', $this->first($html, 'best-organic'));
        $this->assertSame('Local #3', $this->first($html, 'best-local'));
        $this->assertSame($target->tracked_since->format('M j, Y'), $this->first($html, 'first-tracked'));
        $this->assertSame($target->last_checked_at->format('M j, Y g:i A') . ' UTC', $this->first($html, 'last-checked'));
        $this->assertSame('↑ 5', $this->first($html, 'rank-change'));
        $this->assertCount(6, $this->role($html, 'recent-check'));

        // Two charts; found checks are points, the not-found check is a hollow gap marker.
        $this->assertCount(2, $this->role($html, 'rank-chart'));
        $this->assertCount(5, $this->role($html, 'chart-point'), '3 organic + 2 local found checks.');
        $this->assertCount(1, $this->role($html, 'chart-gap'));
        $positions = array_map(fn ($n) => $n->nodeValue, iterator_to_array($this->xpath($html)->query("//*[@data-role='chart-point']/@data-position")));
        $this->assertSame(['4', '12', '7', '5', '3'], $positions);
        $this->assertStringContainsString('not found', (string) $this->first($html, 'chart-gap'));
    }

    public function test_a_detail_page_with_no_history_says_so_and_shows_dashes(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->detail($workspace, $business, $target);

        $this->assertSame('—', $this->first($html, 'current-organic'));
        $this->assertSame('—', $this->first($html, 'last-checked'));
        $this->assertSame('No completed checks yet.', $this->role($html, 'chart-empty')[0]);
        $this->assertSame([], $this->role($html, 'rank-chart'));
    }

    // ------------------------------------------------------------------
    // Search Console seam
    // ------------------------------------------------------------------

    public function test_without_a_search_console_reader_there_is_no_search_console_block(): void
    {
        [$owner, $business, $workspace, , $target] = $this->detailFixture();
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->detail($workspace, $business, $target);

        $this->assertStringNotContainsString('Google Search Console', $html);
        $this->assertStringNotContainsString('data-section="search-console"', $html);
    }

    public function test_search_console_metrics_render_as_a_separate_source_and_never_touch_rank(): void
    {
        [$owner, $business, $workspace, , $target] = $this->detailFixture();
        $this->app->bind(SeoSearchConsoleReader::class, fn () => new class implements SeoSearchConsoleReader {
            public function forKeyword(Business $business, SeoKeyword $keyword): ?array
            {
                return ['clicks' => 120, 'impressions' => 3400, 'average_position' => 9.6, 'as_of' => '2026-10-01'];
            }
        });
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->detail($workspace, $business, $target);

        $this->assertStringContainsString('Google Search Console', $html);
        $this->assertSame('120', $this->first($html, 'gsc-clicks'));
        $this->assertSame('3,400', $this->first($html, 'gsc-impressions'));
        $this->assertSame(self::GSC_POSITION, $this->first($html, 'gsc-position'));
        $this->assertStringContainsString('A separate source from the rank tracker', $this->texts($html, "//*[@data-section='search-console']")[0]);

        // The rank facts are unchanged.
        $this->assertSame('#7', $this->first($html, 'current-organic'));
        $this->assertSame('#12', $this->first($html, 'previous-organic'));
        $this->assertSame('#4', $this->first($html, 'best-organic'));
        $this->assertSame('Local #3', $this->first($html, 'current-local'));

        // The GSC average appears nowhere outside its own labelled section.
        $visible = "//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::*[@data-section='search-console'])]";
        $this->assertStringNotContainsString(self::GSC_POSITION, implode(' ', $this->texts($html, $visible)));

        // And the dashboard organic average is the provider average only.
        $index = $this->index($workspace, $business);
        $this->assertSame('#7', $this->first($index, 'summary-average'));
        $this->assertStringNotContainsString(self::GSC_POSITION, implode(' ', $this->texts($index, "//text()[not(ancestor::script) and not(ancestor::style)]")));
    }

    // ------------------------------------------------------------------
    // ACL / tenancy
    // ------------------------------------------------------------------

    public function test_another_business_target_and_forged_uids_are_a_404_everywhere(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        [$otherOwner, $otherBusiness] = $this->rankTenant(WorkspacePlanTier::Core, 'otherco.com');
        $foreign = $this->track($otherOwner, $otherBusiness, $this->keyword($otherOwner, $otherBusiness, 'foreign phrase'));
        $mine = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->authenticateAsSeoCustomer($owner);
        $before = $this->counts();

        foreach ([$foreign->uid, (string) Str::uuid(), 'not-a-uid'] as $uid) {
            $this->get($this->u('rank-targets.show', $workspace, $business, $uid))->assertNotFound();
            $this->post($this->u('rank-targets.stop', $workspace, $business, $uid))->assertNotFound();
            $this->post($this->u('rank-targets.restart', $workspace, $business, $uid))->assertNotFound();
            $this->post($this->u('rank-targets.check', $workspace, $business, $uid))->assertNotFound();
        }

        $this->post($this->u('keywords.rank.track', $workspace, $business, (string) Str::uuid()), ['search_location_code' => self::CHICAGO])->assertNotFound();

        // The foreign target is untouched and mine is still tracking.
        $this->assertTrue($foreign->fresh()->isTracking());
        $this->assertTrue($mine->fresh()->isTracking());
        $this->assertSame($before, $this->counts());

        // Another Business's workspace/business uid pair is not reachable either.
        $this->get(route('customer.workspaces.businesses.seo.rank-targets.show', [$workspace->uid, $otherBusiness->uid, $foreign->uid]))->assertNotFound();
    }

    public function test_a_target_whose_keyword_is_in_a_location_the_member_cannot_access_is_a_404_and_not_listed(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $granted = $this->createLocation($business);
        $hidden = $this->extraLocation($business, 'Hidden Location');
        $visibleKeyword = app(SeoKeywordManager::class)->create((int) $owner->user_id, $business, 'visible phrase two', $granted);
        $hiddenKeyword = app(SeoKeywordManager::class)->create((int) $owner->user_id, $business, 'hidden phrase', $hidden);
        $visibleTarget = $this->track($owner, $business, $visibleKeyword);
        $hiddenTarget = $this->track($owner, $business, $hiddenKeyword);
        $this->observe($hiddenTarget, 'organic', 'found', 2);

        $member = $this->selectedScopeMember($workspace, [$granted]);
        $this->authenticateAsSeoCustomer($member);
        $before = $this->counts();

        $this->get($this->u('rank-targets.show', $workspace, $business, $visibleTarget->uid))->assertOk();
        $this->get($this->u('rank-targets.show', $workspace, $business, $hiddenTarget->uid))->assertNotFound();
        $this->post($this->u('rank-targets.stop', $workspace, $business, $hiddenTarget->uid))->assertNotFound();
        $this->post($this->u('rank-targets.restart', $workspace, $business, $hiddenTarget->uid))->assertNotFound();
        $this->post($this->u('rank-targets.check', $workspace, $business, $hiddenTarget->uid))->assertNotFound();
        $this->post($this->u('keywords.rank.track', $workspace, $business, $hiddenKeyword->uid), ['search_location_code' => self::NAPERVILLE])->assertNotFound();

        $html = $this->index($workspace, $business);
        $this->assertStringContainsString('visible phrase two', $html);
        $this->assertStringNotContainsString('hidden phrase', $html);
        $this->assertStringNotContainsString($hiddenTarget->uid, $html);
        $this->assertSame([], $this->texts($html, "//tr[@data-uid='{$hiddenKeyword->uid}']"));
        $this->assertSame('—', $this->first($html, 'summary-average'), 'A hidden target must not feed the visible average.');

        $this->assertTrue($hiddenTarget->fresh()->isTracking());
        $this->assertSame($before, $this->counts());
    }

    public function test_a_business_without_the_rank_entitlement_404s_rank_routes_but_the_keyword_page_still_works(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        DB::table('workspace_plan_features')->where('feature_key', 'seo_rank_tracking')->delete();
        $this->authenticateAsSeoCustomer($owner);
        $unknown = (string) Str::uuid();

        $this->get($this->u('rank-targets.show', $workspace, $business, $unknown))->assertNotFound();
        $this->post($this->u('rank-targets.stop', $workspace, $business, $unknown))->assertNotFound();
        $this->post($this->u('rank-targets.restart', $workspace, $business, $unknown))->assertNotFound();
        $this->post($this->u('rank-targets.check', $workspace, $business, $unknown))->assertNotFound();
        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO])->assertNotFound();
        $this->getJson($this->u('keywords.rank-locations', $workspace, $business) . '?q=Chicago')->assertNotFound();
        $this->assertSame(0, SeoRankTarget::query()->count());

        $html = $this->index($workspace, $business);

        $this->assertStringContainsString($keyword->phrase, $html);
        $this->assertSame('—', $this->cell($html, $keyword, 'Organic'));
        $this->assertSame('—', $this->cell($html, $keyword, 'Local'));
        $this->assertSame('—', $this->cell($html, $keyword, 'Change'));
        $this->assertSame([], $this->role($html, 'track-rank-toggle'));
        $this->assertSame([], $this->role($html, 'rank-start'));
        $this->assertSame([], $this->role($html, 'rank-stop'));
        $this->assertSame([], $this->role($html, 'rank-location-field'));
        $this->assertNotEmpty($this->role($html, 'keyword-add-form'), 'Adding an SEO keyword still works.');

        // A forged track_rank on the add form still only saves the SEO keyword.
        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => 'plain keyword', 'track_rank' => '1', 'search_location_code' => (string) self::CHICAGO])->assertRedirect();
        $this->assertSame(1, SeoKeyword::query()->where('phrase', 'plain keyword')->count());
        $this->assertSame(0, SeoRankTarget::query()->count());
        $this->assertSame([0, 0, 0], $this->counts());
    }

    public function test_a_de_entitled_business_with_an_existing_target_gets_no_controls_and_no_new_checks(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $target = $this->track($owner, $business, $keyword);
        DB::table('workspace_plan_features')->where('feature_key', 'seo_rank_tracking')->delete();
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->u('rank-targets.check', $workspace, $business, $target->uid))->assertNotFound();
        $this->get($this->u('rank-targets.show', $workspace, $business, $target->uid))->assertNotFound();

        $html = $this->index($workspace, $business);
        foreach (['track-rank-toggle', 'rank-start', 'rank-stop', 'rank-restart', 'rank-location-field'] as $role) {
            $this->assertSame([], $this->role($html, $role), "{$role} must not render without the entitlement.");
        }
        $this->assertSame([], $this->role($html, 'rank-paused-notice'));
        $this->assertSame([0, 0, 0], $this->counts());
    }

    public function test_view_seo_without_manage_seo_sees_no_controls_and_every_write_is_refused(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $tracked = $this->track($owner, $business, $this->keyword($owner, $business, 'tracked phrase'));
        $loose = $this->keyword($owner, $business, 'loose phrase');
        $this->observe($tracked, 'organic', 'found', 7);
        $this->authenticateAsSeoCustomer($owner, ['view_seo']);
        $before = $this->counts();

        $html = $this->index($workspace, $business);
        $this->assertStringContainsString('tracked phrase', $html);
        $this->assertSame('#7', $this->cell($html, $tracked->keyword, 'Organic'));
        foreach (['keyword-add-form', 'rank-start', 'rank-stop', 'rank-restart', 'rank-location-field', 'track-rank-toggle', 'keyword-archive'] as $role) {
            $this->assertSame([], $this->role($html, $role), "{$role} must not render without manage_seo.");
        }

        $detail = $this->detail($workspace, $business, $tracked);
        foreach (['check-now', 'rank-stop', 'rank-restart'] as $role) {
            $this->assertSame([], $this->role($detail, $role), "{$role} must not render without manage_seo.");
        }

        $this->post($this->u('rank-targets.stop', $workspace, $business, $tracked->uid))->assertUnauthorized();
        $this->post($this->u('rank-targets.restart', $workspace, $business, $tracked->uid))->assertUnauthorized();
        $this->post($this->u('rank-targets.check', $workspace, $business, $tracked->uid))->assertUnauthorized();
        $this->post($this->u('keywords.rank.track', $workspace, $business, $loose->uid), ['search_location_code' => self::CHICAGO])->assertUnauthorized();
        $this->post($this->u('keywords.store', $workspace, $business), ['phrase' => 'x', 'track_rank' => '1', 'search_location_code' => self::CHICAGO])->assertUnauthorized();

        $this->assertTrue($tracked->fresh()->isTracking());
        $this->assertSame(1, SeoRankTarget::query()->count());
        $this->assertSame($before, $this->counts());
    }

    public function test_without_view_seo_the_rank_pages_are_refused(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->authenticateAsSeoCustomer($owner, ['manage_seo', 'website']);

        $this->get($this->u('rank-targets.show', $workspace, $business, $target->uid))->assertUnauthorized();
    }

    public function test_a_stranger_gets_404_on_the_rank_routes(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->authenticateAsSeoCustomer($this->createCustomer());

        $this->get($this->u('rank-targets.show', $workspace, $business, $target->uid))->assertNotFound();
        $this->post($this->u('rank-targets.stop', $workspace, $business, $target->uid))->assertNotFound();
        $this->assertTrue($target->fresh()->isTracking());
    }

    // ------------------------------------------------------------------
    // output safety
    // ------------------------------------------------------------------

    public function test_a_provider_failure_shows_temporarily_unavailable_and_never_leaks_provider_detail(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);
        FakeSeoRankProvider::$submitFailure = SeoRankProviderException::rejected(SeoRankProviderException::CODE_RATE_LIMIT);
        config(['seo.rank_tracking.dataforseo.login' => 'secret-login@example.test', 'seo.rank_tracking.dataforseo.password' => 'hunter2-secret']);

        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO])->assertRedirect();

        $executor = app(SeoRankCheckExecutor::class);
        for ($i = 0; $i < 3; $i++) {
            foreach (SeoRankCheckRun::query()->get() as $run) {
                SeoRankCheckRun::query()->whereKey($run->id)->update(['next_attempt_at' => now()->subMinute()]);
                $executor->submit($run->id);
            }
        }

        $this->assertSame(0, SeoRankCheckRun::query()->where('state', '!=', SeoRankRunState::FailedTerminal->value)->count());
        $this->assertSame('provider_rate_limit', SeoRankCheckRun::query()->firstOrFail()->error_code);

        $target = SeoRankTarget::query()->firstOrFail();
        $index = $this->index($workspace, $business);
        $detail = $this->detail($workspace, $business, $target);

        $this->assertSame('Temporarily unavailable', $this->first($index, 'rank-unavailable'));
        foreach ([$index, $detail] as $html) {
            foreach (['provider_rate_limit', 'rate_limit', 'Rank provider call failed', 'dataforseo', 'DataForSEO', 'hunter2-secret', 'secret-login', 'failed_terminal'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $html, "Page leaked '{$forbidden}'.");
            }
        }
    }

    public function test_pages_never_contain_provider_task_ids_or_internal_run_ids(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);
        FakeSeoRankProvider::$completeImmediately = false;

        $this->post($this->u('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO])->assertRedirect();
        $this->completeRuns();
        FakeSeoRankProvider::willReturn('organic', 'photo booth rental', [$this->item(7, 'photoboothco.com')]);

        $taskIds = SeoRankCheckRun::query()->whereNotNull('provider_task_id')->pluck('provider_task_id')->all();
        $runUids = SeoRankCheckRun::query()->pluck('uid')->all();
        $this->assertNotEmpty($taskIds);

        $target = SeoRankTarget::query()->firstOrFail();
        foreach ([$this->index($workspace, $business), $this->detail($workspace, $business, $target)] as $html) {
            foreach (array_merge($taskIds, $runUids, ['-fake-', 'provider_task_id', 'idempotency']) as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html);
            }
        }
    }

    public function test_the_keywords_page_escapes_a_hostile_phrase(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();
        $this->keyword($owner, $business, '<script>alert(1)</script>');
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->index($workspace, $business);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ------------------------------------------------------------------
    // admin cost page
    // ------------------------------------------------------------------

    public function test_the_admin_cost_page_shows_the_month_total_from_ledger_rows(): void
    {
        [$owner, $business] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->spend($target, 1_200_000);
        $this->spend($target, 300_000, 'committed', 250_000);
        $this->spend($target, 999_000, 'released');
        $old = $this->spend($target, 7_000_000);
        $old->forceFill(['usage_month' => '2020-01', 'usage_day' => '2020-01-15'])->save();

        $this->actingAsAdmin();

        $html = (string) $this->get(route('admin.seo-rank-cost.index'))->assertOk()->getContent();

        $this->assertSame('$1.4500', $this->first($html, 'month-total'), 'actual overrides the reservation; released and other months count nothing.');
        $this->assertSame('$1.4500', $this->first($html, 'today-total'));
        $this->assertSame('Enabled', $this->first($html, 'provider-switch'));

        config(['seo.rank_tracking.enabled' => false]);
        $off = (string) $this->get(route('admin.seo-rank-cost.index'))->assertOk()->getContent();
        $this->assertSame('Disabled', $this->first($off, 'provider-switch'));
        $this->assertStringContainsString('No paid checks will run', $off);

        config(['seo.rank_tracking.enabled' => true]);
        \App\Library\Seo\Rank\Provider\FakeSeoRankProvider::$configured = false;
        $unconfigured = (string) $this->get(route('admin.seo-rank-cost.index'))->assertOk()->getContent();
        $this->assertStringContainsString('credentials not configured', $unconfigured);
        \App\Library\Seo\Rank\Provider\FakeSeoRankProvider::$configured = true;
        $this->assertStringContainsString('2 provider tasks', $html);
        $this->assertStringContainsString('organic_serp', $html);

        $past = (string) $this->get(route('admin.seo-rank-cost.index', ['month' => '2020-01']))->assertOk()->getContent();
        $this->assertSame('$7.0000', $this->first($past, 'month-total'));

        $junk = (string) $this->get(route('admin.seo-rank-cost.index', ['month' => 'not-a-month']))->assertOk()->getContent();
        $this->assertSame('$1.4500', $this->first($junk, 'month-total'), 'A malformed month falls back to the current month.');

        foreach (['dataforseo', 'provider_task_id'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $html);
        }
    }

    public function test_the_admin_cost_page_is_not_reachable_by_a_customer_or_a_guest(): void
    {
        [$owner, $business] = $this->coreTenant();
        $target = $this->track($owner, $business, $this->keyword($owner, $business));
        $this->spend($target, 1_200_000);

        $this->get(route('admin.seo-rank-cost.index'))->assertUnauthorized();

        $this->authenticateAsSeoCustomer($owner);
        $this->get(route('admin.seo-rank-cost.index'))->assertUnauthorized();

        $this->withSession(['permissions' => collect(['access backend', 'access_backend'])]);
        $this->actingAs($owner->user);
        $response = $this->get(route('admin.seo-rank-cost.index'));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringNotContainsString('$1.2000', (string) $response->getContent());
    }
}
