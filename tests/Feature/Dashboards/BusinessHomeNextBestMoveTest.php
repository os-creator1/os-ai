<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Dashboard\AttentionType;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Dashboard\BusinessHomePresenter;
use App\Library\Dashboard\DashboardSnapshot;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Unified Business Home §2.4 / §6.4 / §6.5 / §7.2 (C-2) — Your next best move.
 *
 * ONE move, chosen in a fixed order from the attention items this actor can
 * act on and the head of the Opportunity work queue; "Why this?" in this
 * application's own words; one action, linked only through DashboardLinkGate.
 * It replaced both of Slice 4's lists — the attention list and the list of
 * five recommendations — and the facts behind them stay visible in the
 * Visibility, Conversations and Automations bands. No AI anywhere.
 */
class BusinessHomeNextBestMoveTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    // =================================================================
    // Each move, from real data
    // =================================================================

    public function test_a_waiting_customer_is_the_move_and_its_count_comes_from_the_2b_seam(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Waiting Venue', 'Waiting Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(20)]]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(1)]]); // inside the grace period
        $this->authenticateAs($customer);

        $move = $this->move($customer->user);

        $this->assertSame('conversations_awaiting_reply', $move['key']);
        $this->assertSame('2 customers are waiting for a reply.', $move['headline']);
        $this->assertSame(app(BusinessConversationReadModel::class)->awaitingReplyCount($business), 2);
        $this->assertSame('Reply', $move['actionLabel']);
        $this->assertSame(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]), $move['actionUrl']);
        $this->assertSame('warning', $move['severity']?->value);
        $this->assertContains(
            '2 conversations have waited more than 5 minutes for this business to reply, and the customer wrote last in each.',
            $move['why'],
        );
    }

    public function test_one_waiting_customer_is_said_in_the_singular(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'One Venue', 'One Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->authenticateAs($customer);

        $this->assertSame('1 customer is waiting for a reply.', $this->move($customer->user)['headline']);
    }

    public function test_the_order_holds_through_real_data_not_only_in_the_selector(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Order Venue', 'Order Account');
        $this->website($business, 'draft');
        $this->recommendation($business, ['type' => 'missing_phone']);
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->googleLocation($business, 'suspended');
        $this->automationRuns($business, 2, '2026-09-02', 'failed');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->authenticateAs($customer);

        $expected = ['conversations_awaiting_reply', 'google_connection_lost', 'automation_failing', 'google_location_unhealthy', 'opportunity', 'website_unpublished'];

        foreach ($expected as $index => $key) {
            $this->assertSame($key, $this->move($customer->user)['key'] ?? null, "Step {$index}: {$key} leads.");
            $this->resolve($business, $key);
        }

        $this->assertNull($this->band($customer->user)['move'], 'Everything handled: all caught up.');
    }

    public function test_the_opportunity_head_follows_rfc_002_work_queue_ordering_exactly(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Queue Venue', 'Queue Account');

        // Not in the queue at all, however high they score.
        $this->recommendation($business, ['title' => 'Stale', 'type' => 'missing_email', 'freshness' => 'stale', 'priority_score' => 99]);
        $this->recommendation($business, ['title' => 'Snoozed', 'type' => 'missing_email', 'status' => 'snoozed', 'priority_score' => 99]);
        $this->recommendation($business, ['title' => 'Dismissed', 'type' => 'missing_email', 'status' => 'dismissed', 'priority_score' => 99]);
        // In the queue: score, then impact, then urgency, then oldest, then id.
        $this->recommendation($business, ['type' => 'missing_email', 'priority_score' => 80, 'impact' => 5]);
        $winner = $this->recommendation($business, ['type' => 'missing_phone', 'priority_score' => 80, 'impact' => 5, 'urgency' => 5, 'first_detected_at' => '2026-01-01 00:00:00']);
        $this->recommendation($business, ['type' => 'missing_website_url', 'priority_score' => 80, 'impact' => 5, 'urgency' => 5, 'first_detected_at' => '2026-06-01 00:00:00']);
        $this->recommendation($business, ['type' => 'missing_description', 'priority_score' => 60, 'impact' => 5, 'urgency' => 5]);
        $this->authenticateAs($customer);

        $move = $this->move($customer->user);

        $this->assertSame('opportunity', $move['key']);
        $this->assertSame('Add your business phone number', $move['headline'], "The registry's title of the queue head.");
        $this->assertSame(route('customer.opportunities.show', $winner), $move['actionUrl']);

        $head = app(\App\Repositories\Contracts\OpportunityRepository::class)->topForCustomer($business->fresh(), 1)->first();
        $this->assertSame($winner, (int) $head->id, 'The same head RFC-002 ordering returns, never re-scored.');
    }

    public function test_why_this_for_a_recommendation_is_its_registry_evidence_and_effort_in_words_never_the_score(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Why Venue', 'Why Account');
        $this->recommendation($business, [
            'type' => 'missing_phone',
            'impact' => 5,
            'effort' => 1,
            'priority_score' => 87,
            'evidence' => json_encode([
                ['source_type' => 'business_profile', 'fact_key' => 'phone_blank', 'observed_value' => null, 'summary' => 'The business phone number is not set.'],
                ['summary' => ''],
                'not an item',
            ]),
        ]);
        $this->authenticateAs($customer);

        $move = $this->move($customer->user);

        $this->assertSame(['The business phone number is not set.', 'High impact · Quick to do'], $move['why']);

        $band = $this->bandHtml($this->home()->assertOk()->getContent(), 'next_best_move');
        $this->assertStringContainsString('Why this?', $band);
        $this->assertStringNotContainsString('87', $band, 'The priority score is never shown.');
        $this->assertStringNotContainsString('phone_blank', $band, 'Nor a fact key.');
        $this->assertStringNotContainsString('business_profile', $band);
    }

    public function test_a_lower_effort_or_impact_recommendation_is_described_only_by_what_is_true(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Plain Venue', 'Plain Account');
        $this->recommendation($business, ['type' => 'missing_phone', 'impact' => 2, 'effort' => 4, 'evidence' => json_encode([['summary' => 'The business phone number is not set.']])]);
        $this->authenticateAs($customer);

        $this->assertSame(['The business phone number is not set.'], $this->move($customer->user)['why'], 'Neither "High impact" nor "Quick to do" is claimed.');
    }

    public function test_the_website_is_recommended_only_when_no_recommendation_exists(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Site Venue', 'Site Account');
        $this->website($business, 'draft');
        $this->authenticateAs($customer);

        $move = $this->move($customer->user);
        $this->assertSame('website_unpublished', $move['key']);
        $this->assertSame(['The website has not been published yet.', 'It is saved as a draft and has not been published.'], $move['why']);

        $this->recommendation($business, ['type' => 'missing_phone']);
        $this->assertSame('opportunity', $this->move($customer->user)['key'], 'Setup waits behind a real recommendation.');
    }

    public function test_nothing_to_do_says_you_are_all_caught_up(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Calm Venue', 'Calm Account');
        $this->authenticateAs($customer);

        $band = $this->band($customer->user);
        $html = $this->bandHtml($this->home()->assertOk()->getContent(), 'next_best_move');

        $this->assertNull($band['move']);
        $this->assertNull($band['recommendations']);
        $this->assertStringContainsString("You're all caught up.", html_entity_decode($html));
        $this->assertStringContainsString('data-band="next_best_move"', $this->home()->getContent(), 'The band is always shown.');
    }

    public function test_billing_is_never_the_move_even_when_it_is_the_only_problem(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Billing Venue', 'Billing Account');
        $this->wallet($business, ['paid_activity_paused_at' => now(), 'billing_status' => 'suspended', 'debt_balance_micro' => 500000]);
        $this->authenticateAs($customer);

        $snapshot = $this->dashboardFor($customer->user);

        $this->assertTrue($snapshot->has(DashboardSnapshot::BAND_BILLING_EXCEPTION), 'Billing keeps its own strip.');
        $this->assertNull($snapshot->band(DashboardSnapshot::BAND_NEXT_BEST_MOVE)['move'], 'And never becomes the move.');
    }

    // =================================================================
    // One move, one action, no second list
    // =================================================================

    public function test_the_page_shows_exactly_one_move_and_neither_old_list(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'One Move Venue', 'One Move Account');
        $this->website($business, 'draft');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        foreach (range(1, 6) as $i) {
            $this->recommendation($business, ['type' => 'missing_phone', 'priority_score' => 50 + $i]);
        }
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $main = $this->mainHtml($html);

        $this->assertSame(1, substr_count($main, 'data-role="next-best-move"'), 'Exactly one move.');
        $this->assertSame(1, substr_count($this->bandHtml($html, 'next_best_move'), 'data-role="next-best-move-action"'), 'Exactly one action.');
        $this->assertStringNotContainsString('data-band="attention"', $main, "Slice 4's attention list is gone.");
        $this->assertStringNotContainsString('data-band="recommendations"', $main, 'And its list of five.');
        $this->assertStringNotContainsString('Recommended next steps', $main);
        $this->assertStringNotContainsString('Needs attention', $this->mainText($html));
    }

    public function test_see_all_recommendations_counts_the_queue_and_caps_the_read(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Count Venue', 'Count Account');
        foreach (range(1, 3) as $i) {
            $this->recommendation($business, ['type' => 'missing_phone']);
        }
        $this->recommendation($business, ['type' => 'missing_phone', 'status' => 'snoozed']);
        $this->authenticateAs($customer);

        $band = $this->band($customer->user);
        $this->assertSame('3', $band['recommendations']['label'], 'Snoozed is not in the queue.');
        $this->assertSame(route('customer.opportunities.index'), $band['recommendations']['url']);
        $this->assertStringContainsString('See all recommendations (3)', $this->bandText($this->home()->assertOk()->getContent(), 'next_best_move'));

        foreach (range(1, BusinessHomePresenter::RECOMMENDATION_QUEUE_CAP) as $i) {
            $this->recommendation($business, ['type' => 'missing_email']);
        }

        $this->assertSame(BusinessHomePresenter::RECOMMENDATION_QUEUE_CAP . '+', $this->band($customer->user)['recommendations']['label'], 'A bounded read, and it says so.');
    }

    // =================================================================
    // Links through the gate
    // =================================================================

    public function test_a_move_whose_fix_this_actor_cannot_reach_is_not_in_the_pool(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Gated Venue', 'Gated Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->website($business, 'draft');
        $this->authenticateAs($customer, array_values(array_diff($this->allCustomerPermissions(), ['chat_box'])));

        $move = $this->move($customer->user);

        $this->assertSame('website_unpublished', $move['key'], 'Without the inbox, "Reply" is not a move this actor can make.');
        $this->assertStringNotContainsString(
            route('customer.workspaces.businesses.conversations.index', [$this->workspaceOf($business)->uid, $business->uid]),
            $this->bandHtml($this->home()->assertOk()->getContent(), 'next_best_move'),
        );
    }

    public function test_a_recommendation_for_a_business_the_advisor_would_not_open_is_shown_without_a_link(): void
    {
        [$customer, $primary, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Primary Venue', 'Primary Account');
        $secondAccount = $this->createIndependentWorkspaceBusiness(businessName: 'Second Venue', workspaceName: 'Second Account');
        $secondary = $secondAccount['business'];
        $this->assignTier($secondAccount['workspace'], WorkspacePlanTier::Growth);
        $this->member($secondAccount['workspace'], $customer->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $opportunity = $this->recommendation($secondary, ['type' => 'missing_phone']);
        $this->authenticateAs($customer);
        $this->switchTo($secondAccount['workspace'], $secondary)->assertRedirect(route('user.home'));

        $band = $this->band($customer->user);
        $html = $this->bandHtml($this->home()->assertOk()->getContent(), 'next_best_move');

        $this->assertSame('opportunity', $band['move']['key'], 'Real work is never replaced by "all caught up".');
        $this->assertNull($band['move']['actionUrl'], 'The Advisor opens the PRIMARY Business, so no link is offered here.');
        $this->assertNull($band['recommendations']['url']);
        $this->assertStringNotContainsString(route('customer.opportunities.show', $opportunity), $html, 'An unauthorized destination is never linked.');
        $this->assertStringNotContainsString('data-role="next-best-move-action"', $html);
        $this->assertStringContainsString('Add your business phone number', $html);
    }

    // =================================================================
    // Honesty under failure, no AI, tenancy, cost
    // =================================================================

    /**
     * A source the move depends on failing must never read as "nothing to do".
     * The work queue is the source that can be made to fail here (the status
     * reader and the conversation read model are final); the presenter treats
     * an unreadable status row or waiting count the same way.
     */
    public function test_an_unreadable_source_degrades_the_band_rather_than_claiming_all_caught_up(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Broken Venue', 'Broken Account');
        $this->authenticateAs($customer);

        $this->mock(\App\Repositories\Contracts\OpportunityRepository::class, function ($mock): void {
            $mock->shouldReceive('topForCustomer')->andThrow(new RuntimeException('advisor store down'));
        });

        $snapshot = $this->dashboardFor($customer->user);
        $html = $this->home()->assertOk()->getContent();

        $this->assertContains(DashboardSnapshot::BAND_NEXT_BEST_MOVE, $snapshot->failedBands);
        $this->assertStringNotContainsString("You're all caught up.", html_entity_decode($html));
        $this->assertStringContainsString('data-band="next_best_move" data-band-state="failed"', $html);
    }

    public function test_choosing_and_explaining_the_move_makes_no_ai_call_and_no_http_request(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'No AI Venue', 'No AI Account');
        $this->recommendation($business, ['type' => 'missing_phone', 'evidence' => json_encode([['summary' => 'The business phone number is not set.']])]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        $this->authenticateAs($customer);

        Http::preventStrayRequests();
        Http::fake();

        $this->home()->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('ai_usage_ledger')->count(), 'Nothing reached the AI ledger.');

        foreach (['app/Library/Coo/NextBestMoveSelector.php', 'app/Library/Coo/WhyThis.php', 'app/Library/Coo/NextBestMove.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\bAi(Gateway|CompletionClient|Request)\b|openai/i', file_get_contents(base_path($file)), "{$file} has no AI dependency.");
        }
    }

    public function test_an_agency_opened_client_gets_its_own_move_and_nothing_of_a_sibling(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $sibling = $this->createAgencyManagedClient($workspace, 'Bravo Bistro', 'Bravo Bistro Account')['clientBusiness'];
        $this->conversationWith($sibling, [['incoming', $this->minutesAgo(30)]]);
        $this->googleConnection($sibling, GoogleConnectionState::Revoked);
        $this->website($client, 'draft');
        $this->authenticateAs($agency);
        $this->switchTo($workspace, $client)->assertRedirect(route('user.home'));

        $move = $this->move($agency->user);

        $this->assertSame('website_unpublished', $move['key'], "The sibling's waiting customer and lost connection are not this client's.");
        $this->assertStringNotContainsString('Bravo Bistro', $this->mainText($this->home()->assertOk()->getContent()));
    }

    public function test_the_move_costs_no_extra_conversation_read_and_one_bounded_queue_read(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Cost Venue', 'Cost Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);
        foreach (range(1, 5) as $i) {
            $this->recommendation($business, ['type' => 'missing_phone']);
        }
        $this->authenticateAs($customer);

        Cache::flush();
        $sql = $this->sqlDuring(fn () => $this->dashboardFor($customer->user));

        $awaitingReads = array_filter($sql, fn (string $s) => str_contains($s, 'chat_box_messages') && str_contains($s, 'last_message_id'));
        $opportunityReads = array_filter($sql, fn (string $s) => str_contains($s, 'from `opportunities`'));

        $this->assertCount(1, $awaitingReads, 'Awaiting reply is read ONCE, shared by the Conversations band and the move.');
        $this->assertCount(1, $opportunityReads, 'One bounded read of the work queue: ' . implode(' | ', $opportunityReads));
        $this->assertStringContainsString('limit ' . BusinessHomePresenter::RECOMMENDATION_QUEUE_CAP, strtolower((string) array_values($opportunityReads)[0]));
    }

    // -----------------------------------------------------------------

    /** Makes the named move stop being true, so the next one can lead. */
    private function resolve(Business $business, string $key): void
    {
        match ($key) {
            'conversations_awaiting_reply' => DB::table('chat_box_messages')->insert([
                'box_id' => DB::table('chat_boxes')->where('business_id', $business->id)->value('id'),
                'message' => 'On it',
                'sms_type' => 'sms',
                'send_by' => 'from',
                'direction' => 'outgoing',
                'created_at' => $this->minutesAgo(5),
                'updated_at' => $this->minutesAgo(5),
            ]),
            'google_connection_lost' => $this->googleConnection($business, GoogleConnectionState::Active),
            'automation_failing' => DB::table('automation_executions')->where('business_id', $business->id)->update(['status' => 'succeeded']),
            'google_location_unhealthy' => DB::table('business_google_locations')->where('business_id', $business->id)->update(['verification_state' => 'verified']),
            'opportunity' => DB::table('opportunities')->where('business_id', $business->id)->update(['status' => 'completed']),
            'website_unpublished' => $this->website($business, 'published'),
        };
    }

    /** @return array<string, mixed> */
    private function band(User $user): array
    {
        Cache::flush();
        $band = $this->dashboardFor($user)->band(DashboardSnapshot::BAND_NEXT_BEST_MOVE);
        $this->assertIsArray($band, 'The next best move band is always present.');

        return $band;
    }

    /** @return array<string, mixed> */
    private function move(User $user): array
    {
        $move = $this->band($user)['move'];
        $this->assertIsArray($move, 'A move is expected.');

        return $move;
    }

    private function workspaceOf(Business $business): \App\Models\Workspace
    {
        return \App\Models\Workspace::query()->findOrFail($business->workspace_id);
    }

    private function minutesAgo(int $minutes): string
    {
        return CarbonImmutable::now()->subMinutes($minutes)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    private function bandHtml(string $html, string $band): string
    {
        $main = $this->mainHtml($html);
        $start = strpos($main, 'data-band="' . $band . '"');

        if ($start === false) {
            return '';
        }

        $end = strpos($main, '</section>', $start);

        return substr($main, $start, $end === false ? null : $end - $start);
    }

    private function bandText(string $html, string $band): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->bandHtml($html, $band)))) ?? '');
    }
}
