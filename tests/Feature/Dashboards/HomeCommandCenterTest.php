<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Dashboard\AttentionType;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Dashboard\Home\HomeCommandCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Home V2 — the Business command center, end to end through the production
 * presenter and view: one primary move, de-duplicated attention, honest
 * all-clear, the four states, and the drawer/Ask boundaries.
 */
class HomeCommandCenterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function home(): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('user.home'));
    }

    private function minutesAgo(int $minutes): string
    {
        return \Carbon\CarbonImmutable::now()->subMinutes($minutes)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    private function command(\App\Models\User $user): array
    {
        return $this->dashboardFor($user)->band(DashboardSnapshot::BAND_COMMAND);
    }

    // ── States ───────────────────────────────────────────────────────────

    public function test_a_brand_new_business_sees_a_setup_journey_built_from_real_readiness(): void
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Fresh Venue', 'Fresh Account');
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);

        $this->assertSame(HomeCommandCenter::STATE_SETUP, $home['state']);
        $this->assertSame('setup', $home['status']['key']);
        $this->assertTrue($home['setup']['essentialIncomplete']);
        $steps = array_column($home['setup']['steps'], 'done', 'key');
        $this->assertFalse($steps['location'], 'No Location row exists, so the first step is not done.');
        $this->assertFalse($steps['booking_type']);

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-home-state="setup"', $html);
        $this->assertStringContainsString('data-role="setup-steps"', $html);
        $this->assertStringNotContainsString("You're all caught up", html_entity_decode($html), 'A business still being set up is not "all caught up".');
    }

    public function test_setup_progress_is_counted_from_facts_and_leaves_home_when_complete(): void
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Finish Venue', 'Finish Account');
        $this->authenticateAs($customer);
        $this->assertSame(HomeCommandCenter::STATE_SETUP, $this->command($customer->user)['state']);

        $this->completeCalendarSetup($business);

        $home = $this->command($customer->user);
        $this->assertNotSame(HomeCommandCenter::STATE_SETUP, $home['state']);
        $this->assertNull($home['setup']);
        $this->assertStringNotContainsString('data-role="setup-steps"', $this->home()->assertOk()->getContent());
    }

    public function test_a_healthy_quiet_business_is_normal_with_a_truthful_all_clear_and_caught_up_state(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Calm Venue', 'Calm Account');
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);

        $this->assertSame(HomeCommandCenter::STATE_NORMAL, $home['state']);
        $this->assertNull($home['primary']);
        $this->assertTrue($home['caughtUp']);
        $this->assertTrue($home['attention']['allClear']);
        $this->assertFalse($home['attention']['unknown']);
        $this->assertStringContainsString('Welcome to Calm Venue', $home['briefing']['text']);
        $this->assertSame('clear', $home['status']['key']);
        $this->assertStringContainsString('data-status="clear"', $this->home()->assertOk()->getContent());
    }

    public function test_a_broken_provider_makes_the_business_broken_and_is_never_called_all_clear(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Broken Venue', 'Broken Account');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);

        $this->assertSame(HomeCommandCenter::STATE_BROKEN, $home['state']);
        $this->assertFalse($home['attention']['allClear']);
        $this->assertSame('google_connection_lost', $home['primary']['key']);
        $this->assertSame('attention', $home['status']['key'], 'A broken provider is never "All caught up".');
        $this->assertSame(1, substr_count($this->home()->assertOk()->getContent(), 'data-role="next-best-move"'));
    }

    // ── One primary move; no duplicate or contradictory voices ───────────

    public function test_there_is_exactly_one_primary_move_and_the_others_are_secondary(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Many Venue', 'Many Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->website($business, 'draft');
        foreach (range(1, 3) as $i) {
            $this->recommendation($business, ['type' => 'missing_phone', 'priority_score' => 50 + $i]);
        }
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);
        $html = $this->home()->assertOk()->getContent();

        $this->assertSame('conversations_awaiting_reply', $home['primary']['key'], 'The selector\'s fixed order decides, not the page.');
        $this->assertSame(1, substr_count($html, 'data-role="next-best-move"'));
        $this->assertSame(1, substr_count($html, 'data-role="next-best-move-action"'));
    }

    public function test_the_primary_problem_is_not_repeated_in_needs_your_attention(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Dup Venue', 'Dup Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->website($business, 'draft');
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);
        $attentionKeys = array_column(array_merge($home['attention']['visible'], $home['attention']['overflow']), 'key');

        $this->assertSame('conversations_awaiting_reply', $home['primary']['key']);
        $this->assertNotContains(AttentionType::ConversationsAwaitingReply->value, $attentionKeys);
        $this->assertContains(AttentionType::WebsiteUnpublished->value, $attentionKeys, 'A different problem still appears.');
        $this->assertTrue($home['attention']['hasPrimaryException']);
    }

    public function test_a_waiting_customer_opens_the_follow_up_drawer_not_just_a_link(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Drawer Venue', 'Drawer Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);

        $this->assertSame('follow-ups', $home['primary']['action']['drawer']);
        $this->assertSame(
            route('customer.workspaces.businesses.home.drawer', [$workspace->uid, $business->uid, 'follow-ups']),
            $home['primary']['action']['drawerUrl'],
        );
        $this->assertSame(
            route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]),
            $home['primary']['action']['url'],
            'The same button is a real link to the module without JavaScript.',
        );
    }

    public function test_the_waiting_move_carries_its_own_real_figure_and_never_an_invented_one(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Metric Venue', 'Metric Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(45)]]);
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);

        $this->assertSame(2, $home['primary']['metric']['value'], 'The figure is the awaiting-reply count the Conversations band read.');
        $this->assertSame('waiting for you', $home['primary']['metric']['caption']);
        $this->assertSame(['value', 'caption'], array_keys($home['primary']['metric']), 'Only the current waiting count; no derived denominator.');
        $this->assertStringContainsString('data-role="next-best-move-metric"', $this->home()->assertOk()->getContent());
        $this->assertSame('attention', $home['status']['key']);
        $this->assertSame('1 thing needs you', $home['status']['pill']);
    }

    public function test_a_billing_exception_is_never_all_caught_up(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Billing Venue', 'Billing Account');
        $this->wallet($business, ['available_balance_micro' => 3000000, 'billing_status' => 'suspended']);
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);

        $this->assertSame(HomeCommandCenter::STATE_BROKEN, $home['state']);
        $this->assertSame('attention', $home['status']['key'], 'Billing needs the customer, so the status cannot say all caught up.');
        $this->assertGreaterThanOrEqual(1, $home['status']['count']);
        $this->assertFalse($home['caughtUp']);

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-band="billing_exception"', $html);
        $this->assertStringNotContainsString('data-role="home-status" data-status="clear"', $html);
        $this->assertStringNotContainsString('data-band="quiet_steps"', $html, 'No quiet-time suggestions over a billing problem.');
    }

    public function test_an_automations_read_failure_stays_visible_in_the_quiet_layout(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Fail Venue', 'Quiet Fail Account');
        $this->authenticateAs($customer);

        // Every analytics read fails (an unexpected call on a strict mock throws), so the B5 comparison — and with it Automations — is unreadable.
        $this->app->instance(\App\Library\Analytics\BusinessAnalyticsQueries::class, \Mockery::mock(\App\Library\Analytics\BusinessAnalyticsQueries::class));

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-band="quiet_steps"', $html, 'The layout under test is the quiet one.');
        $this->assertStringContainsString('data-role="automations-unavailable"', $html, 'An unreadable Automations says so even when there is no column for it.');
    }

    public function test_the_waiting_figure_is_never_compared_with_a_different_population(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Population Venue', 'Population Account');
        // One customer has waited since BEFORE the selected period (this month): still waiting NOW.
        $this->conversationWith($business, [['incoming', $this->minutesAgo(60 * 24 * 15)]]);
        // Three conversations started today and ALREADY answered: they inflate the period's incoming count but are not waiting.
        foreach ([40, 50, 60] as $ago) {
            $this->conversationWith($business, [['incoming', $this->minutesAgo($ago)], ['outgoing', $this->minutesAgo($ago - 5)]]);
        }
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);

        $this->assertSame(1, $home['primary']['metric']['value'], 'The actual current waiting count, whatever the period.');
        $this->assertArrayNotHasKey('of', $home['primary']['metric'], 'No "of 3 conversations in this period": that would compare two populations.');

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-role="next-best-move-metric"', $html);
        $this->assertSame(1, preg_match('#<aside[^>]*next-best-move-metric.*?</aside>#s', $html, $aside));
        $this->assertDoesNotMatchRegularExpression('/\bof\s+\d+\b/i', strip_tags($aside[0]), 'The card shows the waiting count alone.');
    }

    public function test_a_quiet_business_offers_only_steps_whose_pages_exist_for_the_actor(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Steps Venue', 'Quiet Steps Account');
        $this->authenticateAs($customer);

        $home = $this->command($customer->user);

        $this->assertSame('clear', $home['status']['key']);
        $this->assertNull($home['primary']);

        foreach ($home['quietSteps'] as $step) {
            $this->assertContains($step['key'], ['booking-type', 'form', 'contact']);
            $this->assertNotSame('', $step['url'], 'A step is a real page, never a dead row.');
        }

        $html = $this->home()->assertOk()->getContent();
        $this->assertSame($home['quietSteps'] !== [], str_contains($html, 'data-band="quiet_steps"'));
        $this->assertStringNotContainsString('data-band="next_best_move"', $html, 'No empty urgent-action container.');
    }
    // ── Drawers ──────────────────────────────────────────────────────────

    public function test_the_follow_up_drawer_lists_the_real_waiting_conversation_with_the_inbox_reply_endpoint(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Reply Venue', 'Reply Account');
        $boxId = $this->conversationWith($business, [['incoming', $this->minutesAgo(40)]]);
        $uid = DB::table('chat_boxes')->where('id', $boxId)->value('uid');
        $this->authenticateAs($customer);

        $html = $this->get(route('customer.workspaces.businesses.home.drawer', [$workspace->uid, $business->uid, 'follow-ups']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-role="follow-up-item"', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.conversations.reply', [$workspace->uid, $business->uid, $uid]), $html, 'The write is the inbox\'s own endpoint.');
        $this->assertStringContainsString('name="_token"', $html, 'CSRF travels with the form.');
        $this->assertStringContainsString('name="idempotency_token"', $html);
    }

    public function test_the_drawer_never_lists_another_businesss_conversations(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Venue', 'Mine Account');
        [, $other] = $this->tenant(WorkspacePlanTier::Growth, 'Other Venue', 'Other Account');
        $this->conversationWith($other, [['incoming', $this->minutesAgo(40)]]);
        $this->authenticateAs($customer);

        $html = $this->get(route('customer.workspaces.businesses.home.drawer', [$workspace->uid, $business->uid, 'follow-ups']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="follow-up-item"', $html);
        $this->assertStringContainsString('Nobody is waiting for a reply right now.', $html);
    }

    public function test_the_drawer_route_refuses_an_unknown_drawer_and_a_foreign_business(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Gate Venue', 'Gate Account');
        [, $other, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Stranger Venue', 'Stranger Account');
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.home.drawer', [$workspace->uid, $business->uid, 'nonsense']))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.home.drawer', [$otherWorkspace->uid, $other->uid, 'follow-ups']))->assertNotFound();
    }

    public function test_the_booking_type_drawer_posts_to_the_modules_own_store_route_and_creates_it_as_json(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Type Venue', 'Type Account');
        $this->authenticateAs($customer);
        $location = DB::table('business_locations')->where('business_id', $business->id)->first();

        $html = $this->get(route('customer.workspaces.businesses.home.drawer', [$workspace->uid, $business->uid, 'booking-type']))
            ->assertOk()->getContent();
        $store = route('customer.workspaces.businesses.calendar.booking-types.store', [$workspace->uid, $business->uid, $location->uid]);
        $this->assertStringContainsString($store, $html);

        $this->postJson($store, ['name' => 'Free consult', 'duration_minutes' => 45])
            ->assertCreated()
            ->assertJson(['status' => 'success']);

        $this->assertTrue(DB::table('booking_types')->where('name', 'Free consult')->where('business_location_id', $location->id)->exists());

        $this->postJson($store, ['name' => '', 'duration_minutes' => 0])->assertStatus(422);
    }

    public function test_a_location_restricted_staff_member_is_never_counted_or_shown_another_locations_customers(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Two Site Venue', 'Two Site Account');
        $first = \App\Models\BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $second = \App\Models\BusinessLocation::create([
            'business_id' => $business->id, 'name' => 'Second site', 'service_mode' => 'storefront', 'country_code' => 'US',
        ]);

        $mine = $this->conversationWith($business, [['incoming', $this->minutesAgo(40)]]);
        $theirs = $this->conversationWith($business, [['incoming', $this->minutesAgo(50)]]);
        DB::table('chat_boxes')->where('id', $mine)->update(['location_id' => $first->id]);
        DB::table('chat_boxes')->where('id', $theirs)->update(['location_id' => $second->id]);
        $theirUid = (string) DB::table('chat_boxes')->where('id', $theirs)->value('uid');

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, \App\Enums\Workspace\WorkspaceMembershipRole::Staff, \App\Enums\Workspace\WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => \App\Enums\Workspace\LocationAccessScope::Selected])->save();
        app(\App\Repositories\Contracts\WorkspaceMembershipLocationRepository::class)->assign($membership, $first);

        $model = app(\App\Library\Conversations\BusinessConversationReadModel::class);
        $this->assertSame(2, $model->awaitingReplyCount($business), 'Business-wide, as before, when no actor is named.');
        $this->assertSame(1, $model->awaitingReplyCount($business, null, (int) $staff->user_id), 'The restricted actor is counted only their Location.');
        $this->assertSame(2, $model->awaitingReplyCount($business, null, (int) $owner->user_id));

        $rows = $model->awaitingReplyConversations($business, (int) $staff->user_id);
        $this->assertCount(1, $rows);
        $this->assertNotSame($theirUid, $rows[0]['uid']);

        $this->authenticateAs($staff->fresh(), ['chat_box', 'access_backend']);
        $html = $this->get(route('customer.workspaces.businesses.home.drawer', [$workspace->uid, $business->uid, 'follow-ups']))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-role="follow-up-item"'));
        $this->assertStringNotContainsString($theirUid, $html);
    }

    public function test_a_booking_refusal_reaches_the_drawer_as_json_with_the_engines_own_words(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Refuse Venue', 'Refuse Account');
        $this->authenticateAs($customer);
        $setup = $this->completeCalendarSetupLookup($business);
        $location = \App\Models\BusinessLocation::findOrFail($setup['location']);
        $typeUid = (string) DB::table('booking_types')->where('id', $setup['bookingType'])->value('uid');
        $group = $this->contactGroup($business);
        $contactUid = (string) Str::uuid();
        DB::table('contacts')->insert([
            'uid' => $contactUid, 'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'location_id' => $location->id, 'group_id' => $group->id, 'phone' => '15557770000', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Monday 03:00 is outside the fixture's 09:00–17:00 Monday hours for the one staff member.
        $r = $this->postJson(
            route('customer.workspaces.businesses.calendar.appointments.store', [$workspace->uid, $business->uid, $location->uid]),
            ['booking_type_uid' => $typeUid, 'contact_uid' => $contactUid, 'staff' => (string) $business->customer_id, 'date' => '2026-09-14', 'time' => '03:00'],
        );

        $r->assertStatus(422)->assertJson(['status' => 'error']);
        $this->assertNotSame('', (string) $r->json('message'));
        $this->assertSame(0, DB::table('appointments')->count(), 'A refused booking writes nothing.');
    }

    // ── Ask MotionGrove ──────────────────────────────────────────────────

    public function test_ask_resolves_supported_requests_and_refuses_unsupported_ones_without_executing_anything(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Ask Venue', 'Ask Account');
        $this->authenticateAs($customer);
        $ask = route('customer.workspaces.businesses.home.ask', [$workspace->uid, $business->uid]);
        $before = DB::table('booking_types')->count();

        $drawer = $this->postJson($ask, ['q' => 'Create a booking type'])->assertOk()->json();
        $this->assertSame('drawer', $drawer['status']);
        $this->assertStringContainsString('/home/drawers/booking-type', $drawer['drawerUrl']);

        $unknown = $this->postJson($ask, ['q' => 'send 500 texts to everyone'])->assertOk()->json();
        $this->assertSame('unsupported', $unknown['status']);
        $this->assertNull($unknown['drawerUrl']);

        $this->assertSame($before, DB::table('booking_types')->count(), 'Resolving a request changes nothing.');
        $this->postJson($ask, ['q' => ''])->assertStatus(422);
    }

    public function test_ask_for_a_feature_the_plan_lacks_says_so_instead_of_pretending(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Core Venue', 'Core Account');
        $this->authenticateAs($customer);

        $r = $this->postJson(route('customer.workspaces.businesses.home.ask', [$workspace->uid, $business->uid]), ['q' => 'Improve my ads'])
            ->assertOk()->json();

        $this->assertContains($r['status'], ['unsupported', 'link', 'drawer']);

        if ($r['status'] === 'unsupported') {
            $this->assertStringContainsString('not available', $r['title']);
        }
    }

    public function test_ask_requires_authentication(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Anon Venue', 'Anon Account');

        $this->postJson(route('customer.workspaces.businesses.home.ask', [$workspace->uid, $business->uid]), ['q' => 'x'])->assertUnauthorized();
    }

    // ── Performance & honesty ────────────────────────────────────────────

    public function test_appointments_booked_is_its_own_figure_and_never_paid_revenue(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Booked Venue', 'Booked Account');
        $setup = $this->completeCalendarSetupLookup($business);
        $this->authenticateAs($customer);

        $contact = DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'location_id' => $setup['location'], 'group_id' => $this->contactGroup($business)->id,
            'phone' => '15551230000', 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([['scheduled', 1], ['cancelled', 1]] as [$status, $n]) {
            DB::table('appointments')->insert([
                'uid' => (string) Str::uuid(), 'business_location_id' => $setup['location'], 'booking_type_id' => $setup['bookingType'],
                'staff_user_id' => $business->customer_id, 'contact_id' => $contact, 'status' => $status,
                'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addMinutes(30), 'reschedule_count' => 0,
                'created_at' => now()->subHour(), 'updated_at' => now(),
            ]);
        }

        $headlines = $this->dashboardFor($customer->user)->band(DashboardSnapshot::BAND_HEADLINES)['items'];
        $booked = collect($headlines)->firstWhere('key', 'appointments_booked');

        $this->assertNotNull($booked);
        $this->assertSame('1', $booked->figure, 'The cancelled booking is not counted.');
        $this->assertStringNotContainsStringIgnoringCase('revenue', $booked->interpretation . $booked->figureCaption);
        $this->assertStringContainsString('Booked is not attended or paid', $booked->interpretation);
    }

    /** @return array{location: int, bookingType: int} the Location/booking type completeCalendarSetup() already made */
    private function completeCalendarSetupLookup($business): array
    {
        $location = (int) DB::table('business_locations')->where('business_id', $business->id)->value('id');

        return ['location' => $location, 'bookingType' => (int) DB::table('booking_types')->where('business_location_id', $location)->value('id')];
    }
}
