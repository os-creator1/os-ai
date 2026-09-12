<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Dashboard\DashboardSnapshot;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Unified Business Home §2.6 (Slice H-4) — Visibility, Conversations and
 * Automations: the three operating-health sections that let an owner answer,
 * in one glance, "is my website live, is Google connected, are customers
 * writing, am I answering, is anyone waiting, and are my automations working?"
 *
 * Every figure is canonical. Visibility is two columns of the status row the
 * page already read. Conversations comes only from Slice 2B's read model, the
 * single door to the conversation table. Automations is B5's own
 * automationKpis() for the selected period, with no run semantics of this
 * slice's own.
 *
 * Incoming and Replied follow the H-3 period selector. Awaiting reply
 * deliberately does not: it is current state, and a figure about last month
 * could not answer "is anyone waiting for me now?".
 */
class BusinessHomeOperatingHealthTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // 11:00 in New York on 10 Sep 2026.
        $this->freezeClock();
    }

    // =================================================================
    // Visibility
    // =================================================================

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function websiteStates(): array
    {
        return [
            'published' => ['published', 'Published'],
            'draft' => ['draft', 'Draft'],
            'archived' => ['archived', 'Archived'],
            'never created' => [null, 'Not created'],
        ];
    }

    #[DataProvider('websiteStates')]
    public function test_the_website_tile_states_what_the_status_column_says(?string $status, string $expected): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Site Venue', 'Site Account');

        if ($status !== null) {
            $this->website($business, $status);
        }

        $this->authenticateAs($customer);

        $this->assertSame($expected, $this->visibility($customer->user)['website']['state']);
        $this->assertStringContainsString($expected, $this->bandText($this->home()->assertOk()->getContent(), 'visibility'));
    }

    /**
     * @return array<string, array{0: ?GoogleConnectionState, 1: string}>
     */
    public static function googleStates(): array
    {
        return [
            'active' => [GoogleConnectionState::Active, 'Connected'],
            'revoked' => [GoogleConnectionState::Revoked, 'Connection lost'],
            'disconnected' => [GoogleConnectionState::Disconnected, 'Not connected'],
            'still connecting' => [GoogleConnectionState::Pending, 'Not connected'],
            'never connected' => [null, 'Not connected'],
        ];
    }

    /**
     * The vocabulary is the Google page's own: a connection that was revoked
     * existed and broke ("Google access needs to be reconnected"), while one
     * switched off, never made, or still mid-connect is simply not connected.
     */
    #[DataProvider('googleStates')]
    public function test_the_google_tile_distinguishes_a_lost_connection_from_no_connection(?GoogleConnectionState $state, string $expected): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Google Venue', 'Google Account');

        if ($state !== null) {
            $this->googleConnection($business, $state);
        }

        $this->authenticateAs($customer);
        $tile = $this->visibility($customer->user)['google'];

        $this->assertSame($expected, $tile['state']);
        $this->assertSame($expected === 'Connection lost' ? 'warning' : null, $tile['severity']?->value);
        $this->assertStringContainsString($expected, $this->bandText($this->home()->assertOk()->getContent(), 'visibility'));
    }

    public function test_listings_needing_attention_are_counted_and_named_in_plain_words(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Listing Venue', 'Listing Account');
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->googleLocation($business, 'suspended');
        $this->googleLocation($business, 'duplicate');
        $this->googleLocation($business, 'verified');
        $this->authenticateAs($customer);

        $tile = $this->visibility($customer->user)['google'];

        $this->assertSame('Connected', $tile['state']);
        $this->assertSame('2 listings need attention', $tile['note']);
        $this->assertSame('warning', $tile['severity']?->value);

        $text = $this->bandText($this->home()->assertOk()->getContent(), 'visibility');
        $this->assertStringContainsString('2 listings need attention', $text);
        $this->assertStringContainsString('Warning', $text, 'Severity is a word, never colour alone.');
    }

    public function test_one_unhealthy_listing_is_said_in_the_singular(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Single Venue', 'Single Account');
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->googleLocation($business, 'unverified');
        $this->authenticateAs($customer);

        $this->assertSame('1 listing needs attention', $this->visibility($customer->user)['google']['note']);
    }

    public function test_another_businesss_website_and_google_never_reach_this_home(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Venue', 'Mine Account');
        $rival = $this->addBusiness($customer, $workspace, 'Rival Venue');
        $this->website($rival, 'published');
        $this->googleConnection($rival, GoogleConnectionState::Active);
        $this->googleLocation($rival, 'suspended');
        $this->authenticateAs($customer);
        $this->switchTo($workspace, $business)->assertRedirect(route('user.home'));

        $tiles = $this->visibility($customer->user);

        $this->assertSame('Not created', $tiles['website']['state'], "The rival's website is not this Business's.");
        $this->assertSame('Not connected', $tiles['google']['state']);
        $this->assertNull($tiles['google']['note'], "And neither are its listings.");
    }

    public function test_the_band_carries_no_traffic_ranking_or_seo_claim(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Venue', 'Quiet Account');
        $this->website($business, 'published');
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->authenticateAs($customer);

        $text = $this->bandText($this->home()->assertOk()->getContent(), 'visibility');

        $this->assertDoesNotMatchRegularExpression('/\b(visitors?|traffic|page views?|impressions?|SEO|ranking|score|healthy|conversion rate)\b/i', $text);
    }

    public function test_a_tier_without_either_surface_gets_no_visibility_band_and_no_locked_upsell(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Core, 'Core Venue', 'Core Account');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer);

        // Core is not entitled to Google, so that tile is absent; the website
        // tile is what keeps the band alive.
        $tiles = $this->visibility($customer->user);
        $this->assertArrayNotHasKey('google', $tiles);
        $this->assertArrayHasKey('website', $tiles);

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringNotContainsString('Google', $this->bandText($html, 'visibility'));
        $this->assertStringNotContainsString('Upgrade', $this->bandText($html, 'visibility'));
    }

    public function test_a_role_that_may_not_open_a_surface_is_not_told_about_it_either(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Restricted Venue', 'Restricted Account');
        $this->website($business, 'published');
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->authenticateAs($customer, array_values(array_diff($this->allCustomerPermissions(), ['website', 'view_google_business_profile'])));

        $snapshot = $this->dashboardFor($customer->user);

        $this->assertFalse($snapshot->has(DashboardSnapshot::BAND_VISIBILITY), 'No permission for either surface, no band.');
        $this->assertStringNotContainsString('data-band="visibility"', $this->home()->assertOk()->getContent());
    }

    public function test_visibility_costs_no_query_of_its_own(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Free Venue', 'Free Account');
        $this->website($business, 'published');
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->googleLocation($business, 'suspended');
        $this->authenticateAs($customer);

        $sql = $this->sqlDuring(fn () => $this->home()->assertOk());
        $statusReads = count(array_filter($sql, fn (string $s) => str_contains($s, 'website_status')));

        // H-5's Recent work reads `website_revisions` joined to `websites` for
        // its own factual item; what must stay true here is that Visibility
        // adds no read, taking both its facts off the one status row.
        $this->assertSame(1, $statusReads, 'The status row the page already reads is the only website STATUS read.');
        $this->assertSame(
            1,
            count(array_filter($sql, fn (string $s) => str_contains($s, 'business_google_locations'))),
            'Listings are one grouped sub-select, never one query per location.',
        );
    }

    public function test_the_listing_count_does_not_grow_a_query_when_listings_do(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Many Venue', 'Many Account');
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->googleLocation($business, 'suspended');
        $this->authenticateAs($customer);

        $locationReads = function () use ($customer): int {
            Cache::flush();

            return count(array_filter(
                $this->sqlDuring(fn () => $this->home()->assertOk()),
                fn (string $sql) => str_contains($sql, 'business_google_locations'),
            ));
        };

        $this->assertSame(1, $locationReads(), 'One listing: one grouped read.');

        foreach (['duplicate', 'unverified', 'disabled', 'ownership_conflict'] as $state) {
            $this->googleLocation($business, $state);
        }

        $this->assertSame('5 listings need attention', $this->visibility($customer->user)['google']['note']);
        $this->assertSame(1, $locationReads(), 'Five listings: still one grouped read, never one per location.');
    }

    // =================================================================
    // Conversations — the period figures
    // =================================================================

    public function test_incoming_counts_conversations_a_customer_wrote_in_during_the_period(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Incoming Venue', 'Incoming Account');
        $at = fn (string $date, string $time = '12:00:00') => $this->localInstant($date . ' ' . $time, $business);

        // Inside This month (1–10 Sep): three conversations the customer wrote in.
        $this->conversationWith($business, [['incoming', $at('2026-09-02')]]);
        $this->conversationWith($business, [['incoming', $at('2026-09-03')], ['incoming', $at('2026-09-03', '13:00:00')]]);
        $this->conversationWith($business, [['outgoing', $at('2026-09-04')], ['incoming', $at('2026-09-04', '13:00:00')]]);
        // Outbound only: the Business wrote, nobody wrote back.
        $this->conversationWith($business, [['outgoing', $at('2026-09-05')]]);
        // Before the window.
        $this->conversationWith($business, [['incoming', $at('2026-08-20')]]);

        $this->authenticateAs($customer);
        $figures = $this->conversations($customer->user);

        $this->assertSame('3', $figures['incoming']['figure'], 'Conversations, not messages: two incoming in one thread is one.');
    }

    public function test_replied_counts_conversations_the_business_answered_not_messages_it_sent(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Replied Venue', 'Replied Account');
        $at = fn (string $date, string $time = '12:00:00') => $this->localInstant($date . ' ' . $time, $business);

        // Answered once.
        $this->conversationWith($business, [['incoming', $at('2026-09-02')], ['outgoing', $at('2026-09-02', '12:30:00')]]);
        // Answered five times — still ONE answered conversation.
        $this->conversationWith($business, [
            ['incoming', $at('2026-09-03')],
            ['outgoing', $at('2026-09-03', '12:10:00')],
            ['outgoing', $at('2026-09-03', '12:20:00')],
            ['outgoing', $at('2026-09-03', '12:30:00')],
            ['outgoing', $at('2026-09-03', '12:40:00')],
            ['outgoing', $at('2026-09-03', '12:50:00')],
        ]);
        // Never answered.
        $this->conversationWith($business, [['incoming', $at('2026-09-04')]]);
        // The Business wrote BEFORE the customer did: that is not an answer.
        $this->conversationWith($business, [['outgoing', $at('2026-09-05')], ['incoming', $at('2026-09-05', '13:00:00')]]);

        $this->authenticateAs($customer);
        $figures = $this->conversations($customer->user);

        $this->assertSame('4', $figures['incoming']['figure']);
        $this->assertSame('2', $figures['replied']['figure'], 'Five outbound messages in one thread are one answered conversation.');
        $this->assertStringContainsString('A person or an automation both count', $figures['replied']['caption']);
    }

    public function test_the_period_figures_are_half_open_at_both_ends(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Bounds Venue', 'Bounds Account');
        $this->authenticateAs($customer);
        $range = $this->performanceRange($customer->user);
        $stamp = fn (CarbonImmutable $instant) => $instant->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');

        $this->conversationWith($business, [['incoming', $stamp($range->startUtc->subSecond())]]);   // before
        $this->conversationWith($business, [['incoming', $stamp($range->startUtc)]]);                // first instant: in
        $this->conversationWith($business, [['incoming', $stamp($range->endUtc->subSecond())]]);     // last instant: in
        $this->conversationWith($business, [['incoming', $stamp($range->endUtc)]]);                  // the next window

        $model = app(BusinessConversationReadModel::class);

        $this->assertSame(2, $model->incomingCount($business, $range->startUtc, $range->endUtc));
        $this->assertSame(1, $model->incomingCount($business, $range->endUtc, $range->endUtc->addMonth()));
        $this->assertSame(1, $model->incomingCount($business, $range->startUtc->subMonth(), $range->startUtc));
    }

    public function test_the_period_figures_follow_the_performance_selector(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Switch Venue', 'Switch Account');
        $at = fn (string $date) => $this->localInstant($date . ' 12:00:00', $business);

        $this->conversationWith($business, [['incoming', $at('2026-09-02')], ['outgoing', $at('2026-09-02')]]);
        $this->conversationWith($business, [['incoming', $at('2026-08-12')]]);
        $this->conversationWith($business, [['incoming', $at('2026-08-14')]]);
        $this->authenticateAs($customer);

        $thisMonth = $this->conversations($customer->user);
        $lastMonth = $this->conversations($customer->user, ['range' => AnalyticsDateRange::PRESET_LAST_MONTH]);

        $this->assertSame(['1', '1'], [$thisMonth['incoming']['figure'], $thisMonth['replied']['figure']]);
        $this->assertSame(['2', '0'], [$lastMonth['incoming']['figure'], $lastMonth['replied']['figure']]);
        $this->assertStringContainsString('Aug 1 – Aug 31', $this->bandText($this->get(route('user.home', ['range' => 'last_month']))->assertOk()->getContent(), 'conversations'));
    }

    public function test_another_businesss_conversations_are_never_counted(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Ours Venue', 'Ours Account');
        $rival = $this->addBusiness($customer, $workspace, 'Rival Venue');
        $at = $this->localInstant('2026-09-02 12:00:00', $business);

        $this->conversationWith($business, [['incoming', $at]]);
        $this->conversationWith($rival, [['incoming', $at]]);
        $this->conversationWith($rival, [['incoming', $at]]);

        $this->authenticateAs($customer);
        $this->switchTo($workspace, $business)->assertRedirect(route('user.home'));

        $this->assertSame('1', $this->conversations($customer->user)['incoming']['figure']);
    }

    // =================================================================
    // Conversations — awaiting reply, the current state
    // =================================================================

    public function test_a_customer_message_older_than_the_grace_period_is_waiting(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Waiting Venue', 'Waiting Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(10)]]);
        $this->authenticateAs($customer);

        $awaiting = $this->conversations($customer->user)['awaiting_reply'];

        $this->assertSame('1', $awaiting['figure']);
        $this->assertSame('warning', $awaiting['severity']?->value);
        $this->assertStringContainsString('Waiting right now', $awaiting['caption']);
    }

    public function test_a_message_inside_the_grace_period_is_not_called_late(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Fresh Venue', 'Fresh Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(2)]]);
        $this->authenticateAs($customer);

        $awaiting = $this->conversations($customer->user)['awaiting_reply'];

        $this->assertSame('0', $awaiting['figure'], 'Home never says "late" the instant a message arrives.');
        $this->assertNull($awaiting['severity'], 'And an ordinary zero is not styled as a problem.');
    }

    public function test_the_grace_boundary_is_exact(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Boundary Venue', 'Boundary Account');
        $model = app(BusinessConversationReadModel::class);
        $grace = (int) config('conversations.awaiting_reply_grace_minutes');

        $this->assertSame(5, $grace, 'The default the contract names.');

        // Exactly at the cutoff is NOT yet waiting; one second older is.
        $exact = $this->conversationWith($business, [['incoming', $this->minutesAgo($grace)]]);
        $this->assertSame(0, $model->awaitingReplyCount($business));

        DB::table('chat_box_messages')->where('box_id', $exact)->update(['created_at' => $this->secondsAgo($grace * 60 + 1)]);
        $this->assertSame(1, $model->awaitingReplyCount($business));
    }

    public function test_an_answer_clears_the_wait_and_a_newer_question_reopens_it(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Thread Venue', 'Thread Account');
        $model = app(BusinessConversationReadModel::class);

        $box = $this->conversationWith($business, [
            ['incoming', $this->minutesAgo(60)],
            ['outgoing', $this->minutesAgo(50)],
        ]);

        $this->assertSame(0, $model->awaitingReplyCount($business), 'Answered: nobody is waiting.');

        DB::table('chat_box_messages')->insert([
            'box_id' => $box,
            'message' => 'And one more thing',
            'sms_type' => 'sms',
            'send_by' => 'to',
            'direction' => 'incoming',
            'created_at' => $this->minutesAgo(20),
            'updated_at' => $this->minutesAgo(20),
        ]);

        $this->assertSame(1, $model->awaitingReplyCount($business), 'A newer question is waiting again.');
    }

    public function test_a_conversation_with_many_waiting_messages_is_still_one(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Many Messages Venue', 'Many Messages Account');

        $this->conversationWith($business, [
            ['incoming', $this->minutesAgo(40)],
            ['incoming', $this->minutesAgo(30)],
            ['incoming', $this->minutesAgo(20)],
        ]);

        $this->assertSame(1, app(BusinessConversationReadModel::class)->awaitingReplyCount($business));
    }

    public function test_a_conversation_untouched_for_more_than_a_month_is_not_reported_as_waiting_forever(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Stale Venue', 'Stale Account');
        $model = app(BusinessConversationReadModel::class);
        $days = (int) config('conversations.awaiting_reply_scan_days');

        $this->assertSame(30, $days, 'The horizon the contract names.');

        $inside = $this->conversationWith($business, [['incoming', $this->daysAgo($days - 1)]], $this->daysAgo($days - 1));
        $stale = $this->conversationWith($business, [['incoming', $this->daysAgo($days + 5)]], $this->daysAgo($days + 5));

        $this->assertSame(1, $model->awaitingReplyCount($business), 'Only the one still in the horizon.');
        $this->assertNotSame($inside, $stale);
    }

    public function test_awaiting_reply_excludes_another_business_and_a_message_of_no_recorded_direction(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Isolated Venue', 'Isolated Account');
        $rival = $this->addBusiness($customer, $workspace, 'Rival Venue');
        $model = app(BusinessConversationReadModel::class);

        $this->conversationWith($rival, [['incoming', $this->minutesAgo(60)]]);
        // A legacy row written before `direction` existed proves nothing about
        // who wrote it, so it is counted as neither side.
        $this->conversationWith($business, [[null, $this->minutesAgo(60)]]);

        $this->assertSame(0, $model->awaitingReplyCount($business));
        $this->assertSame(1, $model->awaitingReplyCount($rival));
    }

    public function test_awaiting_reply_ignores_the_selected_period_entirely(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Current Venue', 'Current Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->authenticateAs($customer);

        foreach ([[], ['range' => 'last_month'], ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-01-31']] as $period) {
            $this->assertSame(
                '1',
                $this->conversations($customer->user, $period)['awaiting_reply']['figure'],
                'Waiting now is waiting now, whatever period the tiles above show.',
            );
        }

        $text = $this->bandText($this->get(route('user.home', ['range' => 'last_month']))->assertOk()->getContent(), 'conversations');
        $this->assertStringContainsString('Awaiting reply is right now', $text);
    }

    public function test_the_conversations_band_costs_two_statements_and_stays_flat_as_conversations_grow(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Flat Venue', 'Flat Account');
        $this->authenticateAs($customer);

        $this->seedConversations($business, 5);
        $before = $this->conversationSql($customer->user);

        $this->seedConversations($business, 25);
        $after = $this->conversationSql($customer->user);

        $this->assertCount(count($before), $after, 'No per-conversation query: ' . implode(' | ', $after));
        $this->assertSame(4, count($after), 'Two Business performance periods, the incoming/replied pair, and awaiting reply.');
    }

    public function test_every_conversation_read_on_home_comes_from_the_slice_2b_seam(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Seam Venue', 'Seam Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->authenticateAs($customer);

        $origins = [];
        DB::listen(function ($query) use (&$origins): void {
            if (! str_contains($query->sql, 'chat_box')) {
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

        $this->assertNotEmpty($origins);
        $this->assertSame(['app/Library/Conversations/BusinessConversationReadModel.php'], array_values(array_unique($origins)));

        // The table, and the model that owns it — never the PERMISSION name,
        // which Home legitimately checks before showing the band at all.
        foreach (glob(app_path('Library/Dashboard/*.php')) ?: [] as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('chat_boxes', $source, "{$file} must reach conversations only through the read model.");
            $this->assertDoesNotMatchRegularExpression('/\bChatBox\b/', $source, "{$file} must not touch the conversation model.");
        }

        foreach (glob(resource_path('views/customer/dashboard/bands/*.blade.php')) ?: [] as $view) {
            $this->assertStringNotContainsString('chat_boxes', file_get_contents($view));
        }
    }

    // =================================================================
    // Automations
    // =================================================================

    public function test_completed_and_failed_are_b5s_figures_for_the_selected_period(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Runs Venue', 'Runs Account');
        $this->automationRuns($business, 4, '2026-09-02', 'succeeded');
        $this->automationRuns($business, 2, '2026-09-03', 'failed');
        // Deliberately not runs of this period, and not completions at all.
        $this->automationRuns($business, 7, '2026-08-15', 'succeeded');
        $this->automationRuns($business, 3, '2026-09-04', 'pending');
        $this->automationRuns($business, 5, '2026-09-05', 'skipped');
        $this->authenticateAs($customer);

        $items = $this->automations($customer->user);

        $this->assertSame('4', $items['completed']['figure'], 'Pending and skipped are not completions.');
        $this->assertSame('2', $items['failed']['figure']);
        $this->assertSame('warning', $items['failed']['severity']?->value);

        $lastMonth = $this->automations($customer->user, ['range' => AnalyticsDateRange::PRESET_LAST_MONTH]);
        $this->assertSame('7', $lastMonth['completed']['figure'], 'The band follows the same selector as Business performance.');
        $this->assertSame('0', $lastMonth['failed']['figure']);
    }

    public function test_a_period_with_nothing_in_it_shows_no_automations_band(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Idle Venue', 'Idle Account');
        $this->automationRuns($business, 3, '2026-08-15', 'succeeded');
        $this->authenticateAs($customer);

        Cache::flush();
        $this->assertTrue(
            $this->dashboardFor($customer->user, ['range' => AnalyticsDateRange::PRESET_LAST_MONTH])->has(DashboardSnapshot::BAND_AUTOMATIONS),
            'Last month has runs.',
        );
        Cache::flush();

        $snapshot = $this->dashboardFor($customer->user, ['range' => AnalyticsDateRange::PRESET_THIS_MONTH]);
        $this->assertFalse($snapshot->has(DashboardSnapshot::BAND_AUTOMATIONS), 'Nothing ran and nothing failed: no band, no clutter.');
    }

    public function test_another_businesss_runs_are_never_counted(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Own Runs Venue', 'Own Runs Account');
        $rival = $this->addBusiness($customer, $workspace, 'Rival Venue');
        $this->automationRuns($business, 2, '2026-09-02', 'succeeded');
        $this->automationRuns($rival, 9, '2026-09-02', 'succeeded');
        $this->authenticateAs($customer);
        $this->switchTo($workspace, $business)->assertRedirect(route('user.home'));

        $this->assertSame('2', $this->automations($customer->user)['completed']['figure']);
    }

    public function test_the_band_is_absent_when_the_automation_source_is_absent(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'No Source Venue', 'No Source Account');
        $this->automationRuns($business, 2, '2026-09-02', 'succeeded');
        $this->authenticateAs($customer);
        Cache::flush();

        $this->partialMock(\App\Library\Analytics\BusinessAnalyticsQueries::class, function ($mock) {
            $mock->shouldReceive('automationKpis')->andReturn(null);
        });

        $snapshot = $this->dashboardFor($customer->user);

        $this->assertFalse($snapshot->has(DashboardSnapshot::BAND_AUTOMATIONS), 'An absent source invents no zero.');
        $this->assertNotContains(DashboardSnapshot::BAND_AUTOMATIONS, $snapshot->failedBands);
    }

    public function test_the_automations_band_adds_no_query_of_its_own(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Budget Runs Venue', 'Budget Runs Account');
        $this->automationRuns($business, 3, '2026-09-02', 'succeeded');
        $this->authenticateAs($customer);

        $sql = $this->sqlDuring(fn () => $this->home()->assertOk());
        $kpiReads = count(array_filter($sql, fn (string $s) => str_contains($s, 'automation_executions') && str_contains($s, 'group by')));
        $recentWorkReads = count(array_filter($sql, fn (string $s) => str_contains($s, 'automation_executions') && str_contains($s, 'automations')));

        // The band itself adds nothing: both reads are the B5 comparison's two
        // periods. H-5's Recent work owns exactly one more, through the same
        // seam — it joins `automations` for the name, which is what tells the
        // two statements apart.
        $this->assertSame(2, $kpiReads, 'The two B5 comparison periods: ' . implode(' | ', $sql));
        $this->assertLessThanOrEqual(1, $recentWorkReads, 'Recent work reads the ledger once, through the seam that owns it.');
    }

    // =================================================================
    // The Home as a whole
    // =================================================================

    public function test_the_three_bands_render_in_contract_order_after_business_performance(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Order Venue', 'Order Account');
        $this->website($business, 'published');
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->conversationWith($business, [['incoming', $this->localInstant('2026-09-02 12:00:00', $business)]]);
        $this->automationRuns($business, 2, '2026-09-02', 'succeeded');
        $this->authenticateAs($customer);

        $order = $this->bandOrder($this->home()->assertOk()->getContent());

        $this->assertSame(
            ['headlines', 'visibility', 'conversations', 'automations', 'actions'],
            array_values(array_filter($order, fn (string $band) => in_array($band, ['headlines', 'visibility', 'conversations', 'automations', 'actions'], true))),
        );
    }

    public function test_h3_performance_and_h2_activity_are_untouched_by_the_new_bands(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Preserved Venue', 'Preserved Account');
        $this->authenticateAs($customer);
        $storage = $this->storageTimezone();
        $this->previousHomeVisit($business, (int) $customer->user_id, CarbonImmutable::now()->subHours(3)->setTimezone($storage)->format('Y-m-d H:i:s'));
        $this->contactsAt($business, 2, CarbonImmutable::now()->subHour()->setTimezone($storage)->format('Y-m-d H:i:s'));
        $this->conversationWith($business, [['incoming', $this->minutesAgo(45)]]);

        $snapshot = $this->dashboardFor($customer->user);
        $headlines = $snapshot->band(DashboardSnapshot::BAND_HEADLINES);
        $activity = $snapshot->band(DashboardSnapshot::BAND_ACTIVITY);

        $this->assertSame(
            ['new_contacts', 'new_conversations', 'messages_received'],
            array_map(fn ($headline) => $headline->key, $headlines['items']),
            'Business performance still carries exactly its three figures.',
        );
        $this->assertSame('Today so far', $activity['window']->label());
        $this->assertSame([['key' => 'new_contacts', 'text' => '2 new contacts'], ['key' => 'new_conversations', 'text' => '1 new conversation']], $activity['items']);

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-role="chart-new-contacts"', $html, 'The chart is still deferred, not loaded here.');
        $this->assertStringContainsString('data-role="chart-placeholder"', $html);
    }

    public function test_the_billing_strip_and_its_absence_are_unchanged(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Billing Venue', 'Billing Account');
        $this->wallet($business, ['available_balance_micro' => 25000000, 'auto_recharge_enabled' => true, 'auto_recharge_threshold_micro' => 1000000]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->authenticateAs($customer);

        $main = $this->mainText($this->home()->assertOk()->getContent());
        $this->assertDoesNotMatchRegularExpression('/\b(Spend and billing|Available balance|Add funds)\b/i', $main);

        $this->wallet($business, ['paid_activity_paused_at' => now()]);
        $snapshot = $this->dashboardFor($customer->user);
        $this->assertTrue($snapshot->has(DashboardSnapshot::BAND_BILLING_EXCEPTION), 'A real exception still speaks first.');
    }

    public function test_no_ai_call_and_no_outbound_http_happen_while_home_loads(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet AI Venue', 'Quiet AI Account');
        $this->website($business, 'published');
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->automationRuns($business, 1, '2026-09-02', 'failed');
        $this->authenticateAs($customer);

        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake();

        $this->home()->assertOk();

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_an_agency_opened_client_business_gets_the_same_three_bands_and_no_portfolio_data(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->website($client, 'published');
        $this->googleConnection($client, GoogleConnectionState::Active);
        $this->conversationWith($client, [['incoming', $this->minutesAgo(30)]]);
        $this->automationRuns($client, 2, '2026-09-02', 'succeeded');
        $this->authenticateAs($agency);
        $this->switchTo($workspace, $client)->assertRedirect(route('user.home'));

        $snapshot = $this->dashboardFor($agency->user);
        $html = $this->home()->assertOk()->getContent();
        $main = $this->mainText($html);

        $this->assertSame(DashboardSnapshot::KIND_BUSINESS, $snapshot->kind);
        foreach ([DashboardSnapshot::BAND_VISIBILITY, DashboardSnapshot::BAND_CONVERSATIONS, DashboardSnapshot::BAND_AUTOMATIONS] as $band) {
            $this->assertTrue($snapshot->has($band), "{$band} renders for a client Business too.");
        }

        foreach ([DashboardSnapshot::BAND_CROSS_CLIENT, DashboardSnapshot::BAND_PROSPECTING, DashboardSnapshot::BAND_CLIENTS] as $agencyBand) {
            $this->assertFalse($snapshot->has($agencyBand), "{$agencyBand} belongs to the Account Home.");
        }

        $this->assertDoesNotMatchRegularExpression('/\b(Positive replies|Outreach|Client performance|Client accounts|Prospects)\b/', $main);
        $this->assertStringNotContainsString('Bravo Bistro', $main, "No other client's name reaches this page.");
    }

    public function test_the_agency_account_home_gains_none_of_the_new_bands(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->website($client, 'published');
        $this->conversationWith($client, [['incoming', $this->minutesAgo(30)]]);
        $this->authenticateAs($agency);

        $snapshot = $this->dashboardFor($agency->user);

        $this->assertSame(DashboardSnapshot::KIND_AGENCY, $snapshot->kind);
        foreach ([DashboardSnapshot::BAND_VISIBILITY, DashboardSnapshot::BAND_CONVERSATIONS, DashboardSnapshot::BAND_AUTOMATIONS] as $band) {
            $this->assertFalse($snapshot->has($band), "{$band} is a Business Home band, not an Account one.");
        }
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, array<string, mixed>>
     */
    private function visibility(User $user, array $query = []): array
    {
        $band = $this->band($user, DashboardSnapshot::BAND_VISIBILITY, $query);
        $tiles = [];

        foreach ($band['items'] as $item) {
            $tiles[$item['key']] = $item;
        }

        return $tiles;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, array<string, mixed>>
     */
    private function conversations(User $user, array $query = []): array
    {
        $band = $this->band($user, DashboardSnapshot::BAND_CONVERSATIONS, $query);
        $items = [];

        foreach ($band['items'] as $item) {
            $items[$item['key']] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, array<string, mixed>>
     */
    private function automations(User $user, array $query = []): array
    {
        $band = $this->band($user, DashboardSnapshot::BAND_AUTOMATIONS, $query);
        $items = [];

        foreach ($band['items'] as $item) {
            $items[$item['key']] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function band(User $user, string $band, array $query = []): array
    {
        Cache::flush();
        $payload = $this->dashboardFor($user, $query)->band($band);
        $this->assertIsArray($payload, "The {$band} band is present.");

        return $payload;
    }

    private function performanceRange(User $user): AnalyticsDateRange
    {
        return $this->band($user, DashboardSnapshot::BAND_HEADLINES)['range'];
    }

    /** @return array<int, string> */
    private function conversationSql(User $user): array
    {
        Cache::flush();

        return array_values(array_filter(
            $this->sqlDuring(fn () => $this->dashboardFor($user)),
            fn (string $sql) => str_contains($sql, 'chat_box'),
        ));
    }

    private function seedConversations(Business $business, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->conversationWith($business, [
                ['incoming', $this->minutesAgo(120 + $i)],
                ['outgoing', $this->minutesAgo(90 + $i)],
            ]);
        }
    }

    private function storageTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /** A Business-local wall-clock instant, as the database stores it. */
    private function localInstant(string $local, Business $business): string
    {
        return CarbonImmutable::parse($local, (string) $business->timezone)
            ->setTimezone($this->storageTimezone())
            ->format('Y-m-d H:i:s');
    }

    private function minutesAgo(int $minutes): string
    {
        return CarbonImmutable::now()->subMinutes($minutes)->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
    }

    private function secondsAgo(int $seconds): string
    {
        return CarbonImmutable::now()->subSeconds($seconds)->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
    }

    private function daysAgo(int $days): string
    {
        return CarbonImmutable::now()->subDays($days)->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
    }

    /** @return array<int, string> */
    private function bandOrder(string $html): array
    {
        preg_match_all('/data-band="([a-z_]+)"/', $this->mainHtml($html), $matches);

        return $matches[1];
    }

    private function bandText(string $html, string $band): string
    {
        $main = $this->mainHtml($html);
        $start = strpos($main, 'data-band="' . $band . '"');

        if ($start === false) {
            return '';
        }

        $end = strpos($main, '</section>', $start);
        $region = substr($main, $start, $end === false ? null : $end - $start);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($region))) ?? '');
    }
}
