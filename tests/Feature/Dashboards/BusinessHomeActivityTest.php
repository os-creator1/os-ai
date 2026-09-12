<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Dashboard\HomeActivityWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Unified Business Home §2.3 (Slice H-2) — "Business activity": what actually
 * changed since this customer last used this Business.
 *
 * The honesty rules this file exists to hold:
 *  - every figure comes from the seam that owns it, for the exact window;
 *  - no leads, bookings, revenue, visitors, rankings or invented rates;
 *  - a first visit shows nothing rather than a fabricated delta;
 *  - one Business never counts another's rows, and one user's window never
 *    moves because a colleague visited;
 *  - the window adapts so an hourly visitor sees a useful day rather than
 *    near-zero noise, and a long absence is a bounded catch-up, not an
 *    unbounded historical delta.
 */
class BusinessHomeActivityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    // =================================================================
    // The visit marker
    // =================================================================

    public function test_the_first_visit_shows_no_activity_band_and_records_the_visit(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'First Venue', 'First Account');
        $this->contactsAt($business, 3, $this->ago('2 hours'));
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertNotContains('activity', $this->bandOrder($html), 'Nothing to compare against yet.');
        $this->assertStringNotContainsString('Business activity', $this->mainText($html));

        $row = DB::table('business_home_visits')->where('user_id', $customer->user_id)->where('business_id', $business->id)->first();
        $this->assertNotNull($row, 'The visit itself is recorded, so the next one has a window.');
        $this->assertNull($row->window_start_at);
        $this->assertSame($this->now(), (string) $row->current_visit_last_seen_at);
    }

    public function test_a_refresh_inside_the_same_visit_keeps_the_window_and_writes_at_most_once_a_minute(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Refresh Venue', 'Refresh Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('3 days'));

        $first = $this->activityFor($customer->user);
        $this->assertSame(HomeActivityWindow::MODE_SINCE_LAST_VISIT, $first['window']->mode);
        $windowStart = (string) DB::table('business_home_visits')->value('window_start_at');

        // A refresh 10 seconds later: same visit, same window, no write.
        $this->freezeClock(CarbonImmutable::now('UTC')->addSeconds(10)->toDateTimeString());
        $before = (string) DB::table('business_home_visits')->value('current_visit_last_seen_at');
        $second = $this->activityFor($customer->user);

        $this->assertSame($windowStart, (string) DB::table('business_home_visits')->value('window_start_at'), 'The window does not move inside a visit.');
        $this->assertSame($before, (string) DB::table('business_home_visits')->value('current_visit_last_seen_at'), 'No stamp write within 60 seconds.');
        $this->assertSame($first['window']->start->toDateTimeString(), $second['window']->start->toDateTimeString());

        // Two minutes later, still the same visit: the stamp moves, the window does not.
        $this->freezeClock(CarbonImmutable::now('UTC')->addMinutes(2)->toDateTimeString());
        $this->activityFor($customer->user);

        $this->assertSame($windowStart, (string) DB::table('business_home_visits')->value('window_start_at'));
        $this->assertNotSame($before, (string) DB::table('business_home_visits')->value('current_visit_last_seen_at'));
    }

    public function test_a_visit_after_the_gap_moves_the_window_to_the_end_of_the_previous_visit(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Gap Venue', 'Gap Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('2 days'));

        $this->activityFor($customer->user);
        $endOfThatVisit = (string) DB::table('business_home_visits')->value('current_visit_last_seen_at');

        // An hour later — past the 30-minute gap — is a new visit.
        $this->freezeClock(CarbonImmutable::now('UTC')->addHour()->toDateTimeString());
        $this->activityFor($customer->user);

        $this->assertSame($endOfThatVisit, (string) DB::table('business_home_visits')->value('window_start_at'));
    }

    public function test_one_users_visit_never_moves_another_users_window_and_one_user_shares_it_across_devices(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Shared Venue', 'Shared Account');
        $colleague = $this->createCustomer();
        $this->member($workspace, $colleague->user, \App\Enums\Workspace\WorkspaceMembershipRole::Admin);

        $this->previousHomeVisit($business, (int) $owner->user_id, $this->ago('2 days'));
        $this->previousHomeVisit($business, (int) $colleague->user_id, $this->ago('5 hours'));

        $this->authenticateAs($colleague);
        $this->home()->assertOk();

        $this->assertSame(
            $this->ago('2 days'),
            (string) DB::table('business_home_visits')->where('user_id', $owner->user_id)->value('current_visit_last_seen_at'),
            'A colleague browsing the same Business leaves the owner\'s marker alone.'
        );

        // The same user from a second device continues the one stored marker.
        $this->authenticateAs($owner);
        $this->home()->assertOk();
        $this->assertSame(1, DB::table('business_home_visits')->where('user_id', $owner->user_id)->count(), 'One marker per user and Business, wherever they sign in.');
    }

    public function test_two_tabs_opening_home_in_the_same_moment_agree_on_one_window(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Tabs Venue', 'Tabs Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('4 days'));
        $this->contactsAt($business, 2, $this->ago('2 days'));

        $first = $this->activityFor($customer->user);
        $second = $this->activityFor($customer->user);

        $this->assertSame($first['window']->start->toDateTimeString(), $second['window']->start->toDateTimeString(), 'Both tabs compute the same window.');
        $this->assertSame($first['items'], $second['items'], 'And therefore the same figures.');
        $this->assertSame(1, DB::table('business_home_visits')->count());
    }

    public function test_a_failed_request_does_not_advance_the_visit_baseline(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Failure Venue', 'Failure Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('3 days'));
        $before = DB::table('business_home_visits')->first();

        // A request that never reaches Home leaves the marker exactly as it was.
        $this->get('/this-route-does-not-exist')->assertNotFound();

        $after = DB::table('business_home_visits')->first();
        $this->assertEquals($before, $after, 'Only a rendered Business Home advances the visit.');
    }

    public function test_viewing_a_client_or_impersonating_never_writes_a_marker(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $client)->assertRedirect(route('user.home'));

        $this->home()->assertOk();

        $this->assertSame(0, DB::table('business_home_visits')->count());
    }

    // =================================================================
    // The adaptive window
    // =================================================================

    public function test_a_visit_earlier_today_summarises_the_whole_business_day(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Today Venue', 'Today Account');
        $this->authenticateAs($customer);

        // Last here two hours ago; the day began before that.
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('2 hours'));
        $this->contactsAt($business, 2, $this->localTimeToday($business, '07:30'));
        $this->contactsAt($business, 1, $this->ago('30 minutes'));

        $activity = $this->activityFor($customer->user);

        $this->assertSame(HomeActivityWindow::MODE_TODAY, $activity['window']->mode);
        $this->assertSame('Today so far', $activity['window']->label());
        $this->assertSame('3 new contacts', $activity['items'][0]['text'], 'An hourly visit still summarises the day, not the last two hours.');

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('Business activity', $this->mainText($html));
        $this->assertStringContainsString('Today so far', $this->mainText($html));
    }

    public function test_a_visit_days_ago_counts_from_that_visit(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Days Venue', 'Days Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('3 days'));

        $this->contactsAt($business, 4, $this->ago('4 days'));   // before the window
        $this->contactsAt($business, 2, $this->ago('2 days'));   // inside it

        $activity = $this->activityFor($customer->user);

        $this->assertSame(HomeActivityWindow::MODE_SINCE_LAST_VISIT, $activity['window']->mode);
        $this->assertStringStartsWith('Since your last visit', $activity['window']->label());
        $this->assertSame('2 new contacts', $activity['items'][0]['text']);
    }

    public function test_a_long_absence_becomes_a_bounded_catch_up_window(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Away Venue', 'Away Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('40 days'));

        $this->contactsAt($business, 5, $this->ago('20 days')); // older than the catch-up window
        $this->contactsAt($business, 3, $this->ago('2 days'));

        $activity = $this->activityFor($customer->user);

        $this->assertSame(HomeActivityWindow::MODE_CATCH_UP, $activity['window']->mode);
        $this->assertSame('Last 7 days', $activity['window']->label());
        $this->assertSame('3 new contacts', $activity['items'][0]['text'], 'A bounded window, never an unbounded historical delta.');
    }

    public function test_today_is_the_business_own_calendar_day_not_the_servers(): void
    {
        // Mid-afternoon in Tokyo, so "today" there is well under way.
        $this->freezeClock('2026-09-10 06:00:00');
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Zone Venue', 'Zone Account');
        DB::table('businesses')->where('id', $business->id)->update(['timezone' => 'Asia/Tokyo']);
        $business = $business->fresh();
        $this->authenticateAs($customer);

        $tokyoMidnight = CarbonImmutable::now()->setTimezone('Asia/Tokyo')->startOfDay();
        $storage = (string) config('app.timezone', 'UTC');

        // Last here shortly after midnight in Tokyo: the same Tokyo day.
        $this->previousHomeVisit($business, (int) $customer->user_id, $tokyoMidnight->addMinutes(20)->setTimezone($storage)->format('Y-m-d H:i:s'));
        $this->contactsAt($business, 1, $tokyoMidnight->addHours(2)->setTimezone($storage)->format('Y-m-d H:i:s'));
        $this->contactsAt($business, 1, $tokyoMidnight->subHours(2)->setTimezone($storage)->format('Y-m-d H:i:s'));

        $activity = $this->activityFor($customer->user);

        $this->assertSame(HomeActivityWindow::MODE_TODAY, $activity['window']->mode);
        $this->assertSame(
            $tokyoMidnight->setTimezone($storage)->toDateTimeString(),
            $activity['window']->start->toDateTimeString(),
            'The window opens at local midnight in the Business timezone, not the server\'s.'
        );
        $this->assertSame('1 new contact', $activity['items'][0]['text'], 'Yesterday in Tokyo is not today.');
    }

    // =================================================================
    // The figures
    // =================================================================

    public function test_each_figure_equals_its_owning_seam_for_the_same_window(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Figures Venue', 'Figures Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('2 days'));

        $this->contactsAt($business, 3, $this->ago('1 day'));
        $this->conversationsAt($business, 2, $this->ago('1 day'));
        $this->receivedAt($business, 6, $this->ago('1 day'));
        $this->automationRunsAt($business, 4, $this->ago('1 day'));
        $this->automationRunsAt($business, 1, $this->ago('1 day'), 'failed');

        // Outside the window: none of these may be counted.
        $this->contactsAt($business, 9, $this->ago('5 days'));
        $this->conversationsAt($business, 9, $this->ago('5 days'));
        $this->receivedAt($business, 9, $this->ago('5 days'));
        $this->automationRunsAt($business, 9, $this->ago('5 days'));

        $activity = $this->activityFor($customer->user);

        $this->assertSame(
            ['3 new contacts', '2 new conversations', '6 messages received', '4 automations completed', '1 automation failed'],
            array_column($activity['items'], 'text'),
            'KPI priority order: contacts, conversations, replies received, then work done.'
        );

        $window = $activity['window'];
        $counts = app(\App\Library\Analytics\BusinessAnalyticsQueries::class)->countsBetween($business, $window->start, $window->end);
        $this->assertSame(3, $counts['newContacts']);
        $this->assertSame(6, $counts['messagesReceived']);
        $this->assertSame(2, app(\App\Library\Conversations\BusinessConversationReadModel::class)->startedCount($business, $window->start, $window->end));
    }

    public function test_nothing_new_is_said_once_quietly_and_zero_values_never_render(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Venue', 'Quiet Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('2 days'));

        $activity = $this->activityFor($customer->user);
        $this->assertSame([], $activity['items']);

        $main = $this->mainText($this->home()->assertOk()->getContent());
        $this->assertStringContainsString('No new activity since your last visit', $main);
        $this->assertDoesNotMatchRegularExpression('/\b0 (new contacts|new conversations|messages received)\b/', $main, 'A zero is never rendered as an item.');
    }

    public function test_one_business_never_counts_another_businesss_activity(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Mine Venue', 'Northwind Agency');
        $other = $this->addBusiness($customer, $workspace, 'Other Venue');
        $this->authenticateAs($customer);
        $this->switchTo($workspace, $business)->assertRedirect(route('user.home'));
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('2 days'));

        $this->contactsAt($business, 1, $this->ago('1 day'));
        $this->contactsAt($other, 8, $this->ago('1 day'));
        $this->conversationsAt($other, 8, $this->ago('1 day'));
        $this->receivedAt($other, 8, $this->ago('1 day'));

        $activity = $this->activityFor($customer->user);

        $this->assertSame(['1 new contact'], array_column($activity['items'], 'text'));
    }

    public function test_the_band_speaks_only_about_what_this_application_can_prove(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Honest Venue', 'Honest Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('2 days'));
        $this->contactsAt($business, 2, $this->ago('1 day'));
        $this->receivedAt($business, 3, $this->ago('1 day'));

        $main = $this->mainText($this->home()->assertOk()->getContent());

        $this->assertDoesNotMatchRegularExpression(
            '/\b(leads?|bookings?|appointments?|revenue|sales|visitors?|page views?|impressions?|rankings?|seo|conversion(s| rate)?|roi)\b/i',
            $main,
            'None of these has a canonical source in this application.'
        );
        $this->assertDoesNotMatchRegularExpression('/\d+(\.\d+)?%/', $main, 'The activity band states counts, never invented percentages.');
    }

    // =================================================================
    // Cost
    // =================================================================

    public function test_the_activity_band_stays_within_its_query_budget(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Cost Venue', 'Cost Account');
        $this->authenticateAs($customer);
        $this->previousHomeVisit($business, (int) $customer->user_id, $this->ago('2 days'));
        $this->contactsAt($business, 2, $this->ago('1 day'));
        $this->conversationsAt($business, 2, $this->ago('1 day'));
        $this->receivedAt($business, 2, $this->ago('1 day'));
        $this->automationRunsAt($business, 2, $this->ago('1 day'));

        $sql = $this->sqlDuring(fn () => $this->home()->assertOk());

        $marker = $this->matching($sql, '/business_home_visits/');
        $this->assertLessThanOrEqual(2, count($marker), 'One marker read and at most one write: ' . implode(' | ', $marker));
        $this->assertSame(1, count($this->matching($sql, '/select.+new_contacts.+messages_received/is')), 'Contacts and received messages are ONE statement.');
        $this->assertLessThanOrEqual(1, count($this->matching($sql, '/from `automation_executions` where `business_id` = \? and `created_at` >= \? and `created_at` < \?$/i')));
        // Two Business performance periods, the activity window, and H-4's
        // two: the incoming/replied pair and the current awaiting-reply
        // state. The contract's own conversation ceiling is 5 (§16).
        $this->assertLessThanOrEqual(5, count($this->matching($sql, '/`chat_box/')), 'Conversation statements: ' . implode(' | ', $this->matching($sql, '/`chat_box/')));

        // The whole Business Home, activity band included, stays inside the
        // contract's ceiling for this state.
        $product = $this->matching($sql, '/\b(reports|contacts|contact_groups|automation_executions|campaigns|chat_boxes|chat_box_messages|business_home_visits|businesses|business_usage_wallets|websites|business_google_connections|opportunities)\b/');
        $this->assertLessThanOrEqual(25, count($product), 'Business Home product-data ceiling: ' . count($product));
    }

    // -----------------------------------------------------------------

    /**
     * @return array{window: HomeActivityWindow, items: array<int, array{key: string, text: string}>}
     */
    private function activityFor(\App\Models\User $user): array
    {
        $band = $this->dashboardFor($user)->band(DashboardSnapshot::BAND_ACTIVITY);
        $this->assertIsArray($band, 'The activity band is present.');

        return $band;
    }

    /** @param array<int, string> $sql */
    private function matching(array $sql, string $pattern): array
    {
        return array_values(array_filter($sql, fn (string $statement) => preg_match($pattern, $statement) === 1));
    }

    private function now(): string
    {
        return CarbonImmutable::now()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    /** A storage-timezone stamp, N units before the frozen now. */
    private function ago(string $interval): string
    {
        return CarbonImmutable::now()->sub($interval)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    /** A storage-timezone stamp for a Business-local wall-clock time today. */
    private function localTimeToday(\App\Models\Business $business, string $time): string
    {
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));

        return CarbonImmutable::now()->setTimezone($timezone)->setTimeFromTimeString($time)
            ->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array<int, string> */
    private function bandOrder(string $html): array
    {
        preg_match_all('/data-band="([a-z_]+)"/', $this->mainHtml($html), $matches);

        return $matches[1];
    }
}
