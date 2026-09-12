<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Dashboard\HeadlinePolarity;
use App\Enums\Dashboard\HeadlineTrend;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Dashboard\BusinessHomePresenter;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Dashboard\Headline;
use App\Library\Dashboard\HeadlineComparison;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 4 §4.1–§4.5, §9.1 — every headline equals the
 * B5 (or Slice 2B) figure it claims, carries an honest comparison with the
 * previous 30 days, and is read with its declared polarity: rates and
 * failures directional, volume only ever described.
 */
class DashboardHeadlinesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    /**
     * Home's canonical headline keys after H-1: outbound volume,
     * provider-accepted and failed-send figures left Home for Results.
     */
    private const KEYS = ['new_contacts', 'conversations_started', 'automation_runs'];

    /** The three H-1 removed, asserted absent wherever Home renders. */
    private const REMOVED_KEYS = ['messages_sent', 'provider_accepted', 'confirmed_failed'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    // =================================================================
    // §4.4 — the comparison value
    // =================================================================

    public function test_the_comparison_value_in_all_three_directions_and_the_two_zero_cases(): void
    {
        $up = new HeadlineComparison(15, 12);
        $this->assertSame([3, 25.0, HeadlineTrend::Up], [$up->absoluteDelta, $up->percentDelta, $up->trend]);
        $this->assertSame('Up 3 (25.0%) from 12 in the previous 30 days.', $up->sentence());

        $down = new HeadlineComparison(2, 3);
        $this->assertSame([-1, -33.3, HeadlineTrend::Down], [$down->absoluteDelta, $down->percentDelta, $down->trend]);
        $this->assertSame('Down 1 (33.3%) from 3 in the previous 30 days.', $down->sentence());

        $same = new HeadlineComparison(7, 7);
        $this->assertSame([0, 0.0, HeadlineTrend::Unchanged], [$same->absoluteDelta, $same->percentDelta, $same->trend]);

        // #48 — previous = 0: no percentage at all, plain words.
        $fromZero = new HeadlineComparison(4, 0);
        $this->assertSame([4, null, HeadlineTrend::Up], [$fromZero->absoluteDelta, $fromZero->percentDelta, $fromZero->trend]);
        $this->assertSame('Up from 0 in the previous 30 days.', $fromZero->sentence());

        // #49 — both = 0.
        $zeros = new HeadlineComparison(0, 0);
        $this->assertSame([0, null, HeadlineTrend::Unchanged], [$zeros->absoluteDelta, $zeros->percentDelta, $zeros->trend]);
        $this->assertSame('No change: 0 in both the last 30 days and the previous 30 days.', $zeros->sentence());

        // One decimal place, B5's own precedent.
        $this->assertSame(14.3, (new HeadlineComparison(8, 7))->percentDelta);
    }

    public function test_polarity_is_declared_per_metric_in_code(): void
    {
        $this->assertSame('positive', HeadlinePolarity::Directional->judgement(HeadlineTrend::Up));
        $this->assertSame('negative', HeadlinePolarity::Directional->judgement(HeadlineTrend::Down));
        $this->assertSame('neutral', HeadlinePolarity::Directional->judgement(HeadlineTrend::Unchanged));
        $this->assertSame('negative', HeadlinePolarity::Inverted->judgement(HeadlineTrend::Up));
        $this->assertSame('positive', HeadlinePolarity::Inverted->judgement(HeadlineTrend::Down));
        $this->assertSame('neutral', HeadlinePolarity::Inverted->judgement(HeadlineTrend::Unchanged));

        foreach (HeadlineTrend::cases() as $trend) {
            $this->assertNull(HeadlinePolarity::Descriptive->judgement($trend));
            $this->assertNull(HeadlinePolarity::DescriptiveGrowth->judgement($trend));
        }

        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Polarity Venue', 'Polarity Account');
        $this->authenticateAs($customer);
        $headlines = $this->headlines($customer->user);

        $this->assertSame(HeadlinePolarity::DescriptiveGrowth, $headlines['new_contacts']->polarity);
        $this->assertSame(HeadlinePolarity::Descriptive, $headlines['conversations_started']->polarity);
        $this->assertSame(HeadlinePolarity::Descriptive, $headlines['automation_runs']->polarity);
    }

    // =================================================================
    // #19, #41–#44 — equality with the sources
    // =================================================================

    public function test_every_headline_equals_the_b5_or_slice_2b_figure_for_both_periods(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Equal Venue', 'Equal Account');
        $this->sent($business, 5, '2026-09-01');
        $this->sent($business, 1, '2026-09-02', 'Undelivered');
        $this->sent($business, 3, '2026-07-20');
        $this->sent($business, 2, '2026-07-21', 'Expired');
        $this->contactsAdded($business, 4, '2026-08-30');
        $this->contactsAdded($business, 6, '2026-07-25');
        $this->conversationsStarted($business, 2, '2026-09-09');
        $this->conversationsStarted($business, 7, '2026-08-01');
        $this->automationRuns($business, 3, '2026-08-15');
        $this->automationRuns($business, 1, '2026-07-15', 'failed');
        $this->authenticateAs($customer);

        $headlines = $this->headlines($customer->user);
        ['current' => $current, 'previous' => $previous] = BusinessDashboardAnalyticsPresenter::ranges($business->timezone);
        $queries = app(BusinessAnalyticsQueries::class);
        $conversations = app(BusinessConversationReadModel::class);

        $expect = [
            'new_contacts' => fn (AnalyticsDateRange $r) => $queries->contactKpis($business, $r)['kpis']->newInRange,
            'conversations_started' => fn (AnalyticsDateRange $r) => $conversations->startedCount($business, $r->startUtc, $r->endUtc),
            'automation_runs' => fn (AnalyticsDateRange $r) => $queries->automationKpis($business, $r)->executionsInRange,
        ];

        foreach ($expect as $key => $source) {
            $this->assertSame($source($current), $headlines[$key]->comparison->current, "{$key}: current");
            $this->assertSame($source($previous), $headlines[$key]->comparison->previous, "{$key}: previous");
        }

        $this->assertSame(self::KEYS, array_keys($headlines), 'Home carries exactly the canonical figures, in KPI-priority order.');
        $this->assertSame([4, 6], [$headlines['new_contacts']->comparison->current, $headlines['new_contacts']->comparison->previous]);
        $this->assertSame([2, 7], [$headlines['conversations_started']->comparison->current, $headlines['conversations_started']->comparison->previous]);
        $this->assertSame([3, 1], [$headlines['automation_runs']->comparison->current, $headlines['automation_runs']->comparison->previous]);
    }

    public function test_conversations_are_counted_by_the_slice_2b_seam_with_the_ranges_bounds_unconverted(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Seam Venue', 'Seam Account');
        // New York local midnight that opens the current window is 04:00 UTC.
        // The rows sit asymmetrically around it, so bounds shifted by the
        // four-hour offset (a second conversion) would change the counts.
        $this->conversationAt($business, '2026-08-12 02:00:00'); // 22:00 on 11 Aug: previous window
        $this->conversationAt($business, '2026-08-12 03:59:59'); // 23:59:59 on 11 Aug: previous window
        $this->conversationAt($business, '2026-08-12 04:00:00'); // 00:00:00 on 12 Aug: current window
        $this->conversationAt($business, '2026-09-10 12:00:00'); // 08:00 on 10 Sep: current window
        $this->conversationAt($business, '2026-09-11 04:00:00'); // 11 Sep: after both windows
        $this->authenticateAs($customer);

        $headline = $this->headlines($customer->user)['conversations_started'];

        $this->assertSame(2, $headline->comparison->current);
        $this->assertSame(2, $headline->comparison->previous);
    }

    public function test_no_chat_boxes_query_originates_in_dashboard_code_or_views(): void
    {
        foreach ($this->dashboardSourceFiles() as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('chat_boxes', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/\bChatBox\b(?!Controller)/', $source, "{$file} must reach conversations only through BusinessConversationReadModel.");
        }

        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Origin Venue', 'Origin Account');
        $this->conversationsStarted($business, 1, '2026-09-01');
        $this->authenticateAs($customer);

        $origins = [];
        DB::listen(function ($query) use (&$origins): void {
            if (! str_contains($query->sql, 'chat_boxes')) {
                return;
            }

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $file = str_replace('\\', '/', $frame['file'] ?? '');
                if (str_contains($file, '/app/')) {
                    $origins[] = substr($file, strpos($file, '/app/') + 1);
                    break;
                }
            }
        });

        $this->home()->assertOk();

        $this->assertNotEmpty($origins, 'Precondition: the conversations headline was read.');
        $this->assertSame(['app/Library/Conversations/BusinessConversationReadModel.php'], array_values(array_unique($origins)));
    }

    // =================================================================
    // #38 — one fixed period, no picker
    // =================================================================

    public function test_the_page_uses_the_last_30_days_preset_and_offers_no_range_picker(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Fixed Venue', 'Fixed Account');
        $this->authenticateAs($customer);

        $html = $this->get(route('user.home', ['range' => 'last_7_days', 'start' => '2026-01-01', 'end' => '2026-01-31']))->assertOk()->getContent();
        $band = $this->between($html, 'data-band="headlines"', '</section>');

        $this->assertStringContainsString('Aug 12 – Sep 10, compared with the previous 30 days (Jul 13 – Aug 11).', html_entity_decode($band));
        $this->assertStringNotContainsString('<select', $band);
        $this->assertStringNotContainsString('<input', $band);
        $this->assertStringNotContainsString('range=', $band);
    }

    // =================================================================
    // #48, #49, #50 — rendered directions and zeros
    // =================================================================

    public function test_increase_decrease_and_unchanged_render_correct_deltas_and_trends_for_every_headline(): void
    {
        $cases = [
            'up' => [HeadlineTrend::Up, fn (Business $b) => $this->shape($b, 3, 1)],
            'down' => [HeadlineTrend::Down, fn (Business $b) => $this->shape($b, 1, 3)],
            'unchanged' => [HeadlineTrend::Unchanged, fn (Business $b) => $this->shape($b, 2, 2)],
        ];

        foreach ($cases as $name => [$trend, $populate]) {
            [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Trend ' . $name, 'Trend Account ' . $name);
            $populate($business);
            $this->authenticateAs($customer);

            $headlines = $this->headlines($customer->user);
            $html = $this->home()->assertOk()->getContent();

            foreach (self::KEYS as $key) {
                $headline = $headlines[$key];
                $this->assertSame($trend, $headline->comparison->trend, "{$name}: {$key} trend");
                $this->assertSame($headline->comparison->current - $headline->comparison->previous, $headline->comparison->absoluteDelta, "{$name}: {$key} delta");
                $this->assertMatchesRegularExpression('/data-headline="' . $key . '"[^>]*data-trend="' . $trend->value . '"/', $html, "{$name}: {$key} rendered trend");
            }
        }
    }

    public function test_a_zero_previous_period_renders_plainly_with_no_infinity_nan_or_fabricated_percentage(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Fresh Venue', 'Fresh Account');
        $this->sent($business, 4, '2026-09-01');
        $this->contactsAdded($business, 2, '2026-09-01');
        $this->authenticateAs($customer);

        $headlines = $this->headlines($customer->user);
        $this->assertNull($headlines['new_contacts']->comparison->percentDelta);
        $this->assertSame('Up from 0 in the previous 30 days.', $headlines['new_contacts']->comparisonSentence);

        // Both zero.
        $this->assertSame([0, 0, null, HeadlineTrend::Unchanged], [
            $headlines['conversations_started']->comparison->current,
            $headlines['conversations_started']->comparison->absoluteDelta,
            $headlines['conversations_started']->comparison->percentDelta,
            $headlines['conversations_started']->comparison->trend,
        ]);

        $main = $this->mainText($this->home()->assertOk()->getContent());

        $this->assertStringContainsString('Up from 0 in the previous 30 days.', $main);
        $this->assertStringContainsString('No change: 0 in both the last 30 days and the previous 30 days.', $main);
        $this->assertDoesNotMatchRegularExpression('/\b(INF|NAN)\b|∞|Infinity/i', $main);
        $this->assertStringNotContainsString('(100.0%) from 0', $main);
    }

    // =================================================================
    // #51, #52, #53 — honest interpretation
    // =================================================================

    /**
     * H-1 replaced Slice 4's directional metrics on Home: provider-accepted
     * and confirmed-failed were the only two, and both left for Results. What
     * must remain true is that Home now carries no judged metric at all —
     * nothing on this page tells a customer they are doing better or worse.
     */
    public function test_home_carries_no_directional_metric_and_no_judgement_badge(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Share Venue', 'Share Account');
        $this->sent($business, 1, '2026-07-20');
        $this->sent($business, 1, '2026-07-20', 'Undelivered');
        $this->sent($business, 3, '2026-09-01');
        $this->contactsAdded($business, 4, '2026-09-01');
        $this->authenticateAs($customer);

        $headlines = $this->headlines($customer->user);
        $html = $this->home()->assertOk()->getContent();

        foreach (self::REMOVED_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $headlines, "{$key} is no longer a Home figure.");
            $this->assertDoesNotMatchRegularExpression('/data-headline="' . $key . '"/', $html);
        }

        foreach ($headlines as $key => $headline) {
            $this->assertNull($headline->judgement, "{$key} carries no judgement.");
        }

        $this->assertStringNotContainsString('data-role="headline-judgement"', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(Better|Worse)\b/', $this->mainText($html));
    }


    public function test_volume_is_described_and_never_called_good_successful_or_healthy(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Volume Venue', 'Volume Account');
        $this->shape($business, 5, 1);
        $this->authenticateAs($customer);

        $headlines = $this->headlines($customer->user);
        $html = $this->home()->assertOk()->getContent();

        foreach (['conversations_started', 'automation_runs', 'new_contacts'] as $key) {
            $this->assertSame(HeadlineTrend::Up, $headlines[$key]->comparison->trend, "Precondition: {$key} increased.");
            $this->assertNull($headlines[$key]->judgement, "{$key} carries no judgement.");
            $this->assertDoesNotMatchRegularExpression('/\b(good|great|successful|healthy|better|improved)\b/i', $headlines[$key]->interpretation, $key);
            $this->assertDoesNotMatchRegularExpression('/data-headline="' . $key . '"[^>]*data-judgement=/', $html, "{$key} renders no judgement badge.");
        }

        $this->assertStringContainsString('Volume is activity, not a success measure.', $headlines['conversations_started']->interpretation);
        $this->assertStringContainsString('Runs are activity, not a success measure.', $headlines['automation_runs']->interpretation);
        $this->assertDoesNotMatchRegularExpression('/\b(revenue|lead quality|pipeline|leads?)\b/i', $headlines['new_contacts']->interpretation);
    }

    /**
     * The acceptance vocabulary was B5's, and it goes with the tile: Home no
     * longer speaks about providers, sending or delivery at all (T-HOME-4).
     * Results keeps the vocabulary and its own test of it.
     */
    public function test_home_carries_no_provider_or_sending_vocabulary(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Vocabulary Venue', 'Vocabulary Account');
        $this->sent($business, 2, '2026-09-01');
        $this->authenticateAs($customer);

        $main = $this->mainText($this->home()->assertOk()->getContent());

        $this->assertDoesNotMatchRegularExpression('/\bprovider accepted\b/i', $main);
        $this->assertDoesNotMatchRegularExpression('/\bmessages sent\b/i', $main);
        $this->assertDoesNotMatchRegularExpression('/\bconfirmed failed\b/i', $main);
        $this->assertDoesNotMatchRegularExpression('/\bdeliver(ed|y rate)\b/i', $main);
    }


    // -----------------------------------------------------------------

    /** Every headline figure with `current` in the current window and `previous` in the previous one. */
    private function shape(Business $business, int $current, int $previous): void
    {
        $this->sent($business, $current, '2026-09-01');
        $this->sent($business, $previous, '2026-07-20');
        $this->sent($business, $current, '2026-09-01', 'Undelivered');
        $this->sent($business, $previous, '2026-07-20', 'Undelivered');
        $this->contactsAdded($business, $current, '2026-09-01');
        $this->contactsAdded($business, $previous, '2026-07-20');
        $this->conversationsStarted($business, $current, '2026-09-01');
        $this->conversationsStarted($business, $previous, '2026-07-20');
        $this->automationRuns($business, $current, '2026-09-01');
        $this->automationRuns($business, $previous, '2026-07-20');
    }

    private function conversationAt(Business $business, string $storageTimestamp): void
    {
        DB::table('chat_boxes')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => '18005550100',
            'to' => '1606555' . random_int(1000, 9999),
            'notification' => 0,
            'created_at' => $storageTimestamp,
            'updated_at' => $storageTimestamp,
        ]);
    }

    /** @return array<string, Headline> */
    private function headlines(User $user): array
    {
        \Illuminate\Support\Facades\Cache::flush();
        $snapshot = $this->dashboardFor($user);
        $this->assertSame(DashboardSnapshot::KIND_BUSINESS, $snapshot->kind);

        $result = [];
        foreach ($snapshot->band(DashboardSnapshot::BAND_HEADLINES)['items'] as $headline) {
            $result[$headline->key] = $headline;
        }

        return $result;
    }

    /** @return array<int, string> */
    private function dashboardSourceFiles(): array
    {
        $files = glob(app_path('Library/Dashboard/*.php')) ?: [];
        $files[] = resource_path('views/customer/dashboard.blade.php');

        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/customer/dashboard'), \FilesystemIterator::SKIP_DOTS));
        foreach ($views as $view) {
            $files[] = $view->getPathname();
        }

        return $files;
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "{$from} must be present.");
        $end = strpos($html, $to, $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }
}
