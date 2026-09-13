<?php

namespace Tests\Feature\Ai;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiBusinessActivityGate;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiModelRouter;
use App\Library\Ai\AiRequest;
use App\Library\Ai\AiUsageLedgerManager;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Library\Ai\Enums\AiUsageEntryStatus;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Models\AiUsageLedgerEntry;
use App\Models\AiUsagePeriod;
use App\Models\Business;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The AI-1 correction round.
 *
 *  1 COO entitlement gate, before reservation and provider call
 *  2 dormant Business gate for scheduled product COO work
 *  3 a billable provider failure commits what it really used
 *  4 routes.*.max_input_tokens is actually enforced
 *  7 one canonical lock order for settlement and expiry
 *  9 the estimate accounts for chat framing, not just content bytes
 * 10 routing uses the period's snapshotted cap, not a changed config cap
 */
class AiGatewayCorrectionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private FakeAiCompletionClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.active' => true]);
        config(['ai.enforce_budgets_for_existing_categories' => true]);

        $this->fakeClient = new FakeAiCompletionClient();
        $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fakeClient);
    }

    // =================================================================
    // 1 — the COO entitlement gate
    // =================================================================

    /**
     * `ai_coo_basic` is Planned until AI-3 ships it, so the canonical
     * entitlement system denies it today. The point of the gate is that the
     * denial happens before anything is spent: no reservation, no ledger
     * row, and above all no provider call.
     */
    public function test_the_coo_categories_are_refused_without_the_canonical_entitlement_and_spend_nothing(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        foreach ([AiUsageCategory::CooDiagnosis, AiUsageCategory::CooInteractive, AiUsageCategory::ConversationCompaction] as $category) {
            $result = app(AiGateway::class)->complete($this->request($workspace, $business, $category));

            $this->assertFalse($result->ok, $category->value . ' needs ai_coo_basic.');
            $this->assertSame(AiRefusalReason::EntitlementMissing, $result->refusalReason);
        }

        $this->assertSame(0, $this->fakeClient->callCount(), 'An unentitled category never reaches the provider.');
        $this->assertSame(0, AiUsageLedgerEntry::count(), 'And never reserves any budget.');
        $this->assertSame(0, AiUsagePeriod::count(), 'A period is not even opened.');
    }

    /**
     * The three categories that pre-date the gateway keep working. Putting
     * them behind a feature they never required would take away product
     * customers already have.
     */
    public function test_the_pre_existing_categories_are_not_placed_behind_the_coo_entitlement(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        foreach ([AiUsageCategory::WebsiteGeneration, AiUsageCategory::CampaignMessageDraft, AiUsageCategory::AgencyProspectReply] as $category) {
            $result = app(AiGateway::class)->complete($this->request($workspace, $business, $category));

            $this->assertTrue($result->ok, $category->value . ' must not suddenly require ai_coo_basic.');
        }

        $this->assertSame(3, $this->fakeClient->callCount());
    }

    /** A COO call with no Business has nothing the feature could entitle. */
    public function test_a_workspace_level_coo_call_is_refused_because_the_feature_is_business_scoped(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency);

        $result = app(AiGateway::class)->complete($this->request($workspace, null, AiUsageCategory::CooDiagnosis));

        $this->assertSame(AiRefusalReason::EntitlementMissing, $result->refusalReason);
        $this->assertSame(0, $this->fakeClient->callCount());
    }

    // =================================================================
    // 2 — the dormant Business gate
    // =================================================================

    /**
     * The gate is the contract's list and nothing else, and ANY one signal
     * inside the window makes a Business active. Each is proven on its own,
     * because a gate that only noticed one of them would silently refuse
     * work for Businesses that are plainly in use.
     *
     * The evidence comes from the seams that own those tables — B5 for
     * contacts, received messages and automation runs, the canonical
     * BusinessConversationReadModel for conversations (both a thread opened
     * in the window and a customer writing in an older one), H-2's visit
     * marker for a member being present. This class adds no query of its own
     * to any of them.
     */
    public function test_each_canonical_activity_signal_on_its_own_makes_a_business_active(): void
    {
        $gate = app(AiBusinessActivityGate::class);
        $recent = CarbonImmutable::now()->subDays(3);

        $signals = ['contact', 'conversation', 'ongoing_conversation', 'incoming_message', 'automation_run', 'member_visit'];

        foreach ($signals as $signal) {
            [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Signal ' . $signal, 'Account ' . $signal);

            $this->assertTrue($gate->isDormant($business), "[{$signal}] a Business with no activity at all is dormant.");

            $this->recordSignal($signal, $business, $recent, (int) $customer->user_id);

            $this->assertFalse($gate->isDormant($business->fresh()), "[{$signal}] must count as activity.");
        }
    }

    /**
     * Correction 2's architectural half. Conversation activity is a question
     * the conversations seam answers; the AI layer must ask it rather than
     * learn the tables. A second reader would be a second definition of
     * "a conversation", and the two would drift.
     */
    public function test_the_ai_layer_reads_conversations_only_through_the_canonical_seam(): void
    {
        $files = new \Symfony\Component\Finder\Finder();
        $files->files()->in(app_path('Library/Ai'))->name('*.php');

        $readers = 0;

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

            $this->assertStringNotContainsString("'chat_boxes'", $source, $relative . ' must not query chat_boxes itself.');
            $this->assertStringNotContainsString("'chat_box_messages'", $source, $relative . ' must not query chat_box_messages itself.');
            $this->assertStringNotContainsString('ChatBox::', $source, $relative . ' must not reach the conversation models directly.');

            if (str_contains($source, 'BusinessConversationReadModel')) {
                $readers++;
            }
        }

        $this->assertSame(
            1,
            $readers,
            'Exactly one file in the AI layer — the dormancy gate — talks to the conversation read model.'
        );

        $gate = (string) file_get_contents(app_path('Library/Ai/AiBusinessActivityGate.php'));
        $this->assertStringContainsString('$this->conversations->startedCount(', $gate);
        $this->assertStringContainsString('$this->conversations->incomingCount(', $gate);
    }

    /** The threshold is config, and the boundary is exact. */
    public function test_the_dormancy_window_is_the_configured_number_of_days(): void
    {
        config(['coo.dormant_after_days' => 30]);
        $gate = app(AiBusinessActivityGate::class);
        $now = CarbonImmutable::parse('2026-09-20 12:00:00');

        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        // Just inside the window.
        $this->recordSignal('contact', $business, $now->subDays(29), 0);
        $this->assertFalse($gate->isDormant($business, $now));

        // Move the same evidence to just outside it.
        DB::table('contacts')->where('business_id', $business->id)->update(['created_at' => $now->subDays(31)]);
        $this->assertTrue($gate->isDormant($business, $now), 'Activity older than the window is not activity.');

        // And the window itself is configuration, not a literal.
        config(['coo.dormant_after_days' => 60]);
        $this->assertFalse($gate->isDormant($business, $now), 'A longer window reaches the same evidence.');
    }

    /**
     * Which categories the gate applies to. Scheduled COO work is gated; a
     * customer's own explicit request is not, and neither is any of the
     * three categories that are themselves a customer doing something.
     */
    public function test_only_scheduled_product_coo_work_is_dormancy_gated(): void
    {
        $this->assertTrue(AiUsageCategory::CooDiagnosis->isDormancyGated());
        $this->assertTrue(AiUsageCategory::ConversationCompaction->isDormancyGated());

        $this->assertFalse(AiUsageCategory::CooInteractive->isDormancyGated(), 'An explicit request is exempt from dormancy alone.');

        foreach ([AiUsageCategory::WebsiteGeneration, AiUsageCategory::CampaignMessageDraft, AiUsageCategory::AgencyProspectReply] as $category) {
            $this->assertFalse($category->isDormancyGated(), $category->value . ' IS a customer acting right now.');
        }
    }

    /**
     * Workspace-level work has no Business whose dormancy could be asked
     * about, and is never refused for it.
     */
    public function test_workspace_level_work_is_never_dormancy_gated(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Agency);

        $result = app(AiGateway::class)->complete($this->request($workspace, null, AiUsageCategory::AgencyProspectReply));

        $this->assertTrue($result->ok);
        $this->assertNotSame(AiRefusalReason::Dormant, $result->refusalReason);
    }

    // =================================================================
    // 3 — a billable provider failure
    // =================================================================

    public function test_a_failure_that_reported_usage_commits_exactly_that_usage_and_releases_only_the_remainder(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $router = app(AiModelRouter::class);

        $this->fakeClient->setDefaultResult(AiCompletionResult::failure(
            providerModel: 'unused-in-assertions',
            inputTokens: 900,
            outputTokens: 120,
        ));

        // A prompt whose reservation comfortably covers what the provider
        // then reports, so this measures the commit and not the clamp.
        $messages = [['role' => 'user', 'content' => str_repeat('a', 6_000)]];
        $result = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration, $messages));

        $this->assertFalse($result->ok);

        $expected = $router->actualCostMicrousd(AiModelRoute::Routine, 900, 0, 120);
        $entry = AiUsageLedgerEntry::query()->latest('id')->firstOrFail();
        $period = $this->workspacePeriod($workspace);

        $this->assertSame(AiUsageEntryStatus::Failed, $entry->status);
        $this->assertSame($expected, (int) $entry->actual_cost_microusd, 'A billed call is never recorded as free.');
        $this->assertSame(900, (int) $entry->input_tokens);
        $this->assertSame(120, (int) $entry->output_tokens);
        $this->assertSame($expected, (int) $period->committed_microusd);
        $this->assertSame(0, (int) $period->reserved_microusd, 'Only the remainder was released.');
    }

    /**
     * A provider that reports more than the reservation held cannot push a
     * period past the cap the customer was promised: the commit is bounded
     * by what was actually reserved. The conservative estimator (Correction
     * 9) is what keeps this bound from mattering in practice.
     */
    public function test_a_commit_can_never_exceed_what_was_reserved(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $entry = $this->reserveOne($workspace, $business);
        $reserved = (int) $entry->estimated_cost_microusd;

        app(AiUsageLedgerManager::class)->commitActual($entry, 'model', 10_000_000, 0, 10_000_000, $reserved * 1_000);

        $this->assertSame($reserved, (int) $entry->fresh()->actual_cost_microusd);
        $this->assertSame($reserved, (int) $this->workspacePeriod($workspace)->committed_microusd);
    }

    public function test_a_failure_that_reported_no_usage_releases_the_whole_reservation(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $this->fakeClient->setDefaultResult(AiCompletionResult::failure());

        $this->assertFalse(app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration))->ok);

        $period = $this->workspacePeriod($workspace);

        $this->assertSame(0, (int) $period->committed_microusd, 'Nothing was used, so nothing is charged.');
        $this->assertSame(0, (int) $period->reserved_microusd);
    }

    // =================================================================
    // 4 — max_input_tokens
    // =================================================================

    public function test_input_at_the_routes_ceiling_is_accepted_and_one_token_over_is_refused(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $router = app(AiModelRouter::class);
        $limit = (int) config('ai.routes.routine.max_input_tokens');

        // A per-request cost ceiling high enough that only the INPUT limit
        // can be what refuses — otherwise this would prove the wrong guard.
        config(['ai.routes.routine.max_request_cost_microusd' => 100_000_000]);

        $atLimit = $this->messagesOfExactlyInputTokens($limit);
        $this->assertSame($limit, $router->estimateInputTokens($atLimit), 'Fixture must sit exactly on the boundary.');

        $accepted = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration, $atLimit));
        $this->assertTrue($accepted->ok, 'Exactly at the ceiling is inside it.');

        $overLimit = $this->messagesOfExactlyInputTokens($limit + 1);
        $this->assertSame($limit + 1, $router->estimateInputTokens($overLimit));

        $callsBefore = $this->fakeClient->callCount();
        $refused = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration, $overLimit));

        $this->assertSame(AiRefusalReason::InputTooLarge, $refused->refusalReason, 'Oversized input is refused, never silently sent.');
        $this->assertSame($callsBefore, $this->fakeClient->callCount(), 'And never reaches the provider.');
        $this->assertSame(1, AiUsageLedgerEntry::count(), 'Nor reserves anything: only the accepted call is on the ledger.');
    }

    // =================================================================
    // 7 — one canonical lock order, and settle-once
    // =================================================================

    /**
     * A provider call completing while the sweep releases the same
     * reservation. Whichever wins, the counters move exactly once — proven
     * deterministically by driving both sides in order, with no sleep.
     */
    public function test_an_expiry_that_wins_the_race_leaves_a_later_settlement_with_nothing_to_do(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $ledger = app(AiUsageLedgerManager::class);

        // A reservation whose caller is still mid-provider-call.
        $entry = $this->reserveOne($workspace, $business);
        $reserved = (int) $entry->estimated_cost_microusd;
        $this->assertSame($reserved, (int) $this->workspacePeriod($workspace)->reserved_microusd);

        // The sweep gets there first.
        AiUsageLedgerEntry::query()->where('id', $entry->id)->update(['created_at' => Carbon::now()->subHour()]);
        $this->assertSame(1, $ledger->expireStaleReservations());

        $afterExpiry = $this->workspacePeriod($workspace);
        $this->assertSame(0, (int) $afterExpiry->reserved_microusd);
        $this->assertSame(0, (int) $afterExpiry->committed_microusd);

        // Now the provider call returns and tries to settle the same entry.
        $ledger->commitActual($entry, 'model', 100, 0, 50, 999);

        $afterSettle = $this->workspacePeriod($workspace);
        $this->assertSame(0, (int) $afterSettle->reserved_microusd, 'The reservation is not released twice.');
        $this->assertSame(0, (int) $afterSettle->committed_microusd, 'And an expired hold cannot be charged afterwards.');
        $this->assertSame(AiUsageEntryStatus::Released, $entry->fresh()->status);
    }

    public function test_a_settlement_that_wins_the_race_makes_the_later_expiry_a_no_op(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $ledger = app(AiUsageLedgerManager::class);

        $entry = $this->reserveOne($workspace, $business);

        // Inside the reservation, so the commit is the whole story.
        $actual = max(1, intdiv((int) $entry->estimated_cost_microusd, 2));
        $ledger->commitActual($entry, 'model', 100, 0, 50, $actual);

        $committed = (int) $this->workspacePeriod($workspace)->committed_microusd;
        $this->assertSame($actual, $committed);

        // The sweep arrives late and must change nothing.
        AiUsageLedgerEntry::query()->where('id', $entry->id)->update(['created_at' => Carbon::now()->subHour()]);
        $this->assertSame(0, $ledger->expireStaleReservations(), 'A settled entry is not stale work.');

        $after = $this->workspacePeriod($workspace);
        $this->assertSame($committed, (int) $after->committed_microusd, 'The committed cost stands exactly once.');
        $this->assertSame(0, (int) $after->reserved_microusd);
        $this->assertSame(AiUsageEntryStatus::Committed, $entry->fresh()->status);
    }

    /**
     * The other half of one canonical lock order: opening a period must not
     * take a lock on a row that is not there yet.
     *
     * `SELECT ... FOR UPDATE` for a missing row locks the GAP it would live
     * in, and gap locks are shared — so every caller in the first burst of a
     * new period gets one, and the insert each then attempts needs an
     * insert-intention lock in that same gap. They wait on each other and
     * InnoDB kills one with a deadlock. The racing-processes test above is
     * the behavioural proof; this pins the shape so the locking read can
     * never move back in front of the insert.
     */
    public function test_a_period_is_opened_without_locking_a_row_that_does_not_exist_yet(): void
    {
        $source = (string) file_get_contents(app_path('Library/Ai/AiUsageLedgerManager.php'));

        $body = (string) preg_replace('/^.*private function lockOrCreatePeriod/s', '', $source);
        $body = (string) preg_replace('/\n    }\n.*$/s', '', $body);

        $probe = strpos($body, '->exists()');
        $insert = strpos($body, '->insertOrIgnore([');
        $lock = strpos($body, '->lockForUpdate()');

        $this->assertNotFalse($probe, 'The existence check must be an ordinary unlocked read.');
        $this->assertNotFalse($insert, 'The insert must tolerate losing the race on its own.');
        $this->assertNotFalse($lock, 'The row is still locked for the rest of the transaction.');

        $this->assertLessThan($insert, $probe, 'The unlocked probe comes first.');
        $this->assertLessThan($lock, $insert, 'The locking read runs only once the row certainly exists.');

        // And a concurrency error is retried rather than handed to a caller.
        $this->assertStringContainsString('private const TRANSACTION_ATTEMPTS = 3;', $source);
        $this->assertSame(2, substr_count($source, 'attempts: self::TRANSACTION_ATTEMPTS'), 'Both the reservation and the settlement retry.');
    }

    /**
     * Behaviourally, in one process: the second call of a period finds the
     * row the first one opened, spends against it, and the period is opened
     * exactly once with the cap it snapshotted.
     */
    public function test_the_first_two_calls_of_a_period_open_exactly_one_row(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $this->fakeClient->setDefaultResult(AiCompletionResult::success('ok', 'model', 120, 40));

        $first = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration));
        $second = app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration));

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok);

        $this->assertSame(
            1,
            AiUsagePeriod::query()
                ->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)
                ->where('scope_id', $workspace->id)
                ->count(),
            'One Workspace period per period key, however many callers opened it.'
        );

        $period = $this->workspacePeriod($workspace);

        $this->assertSame(
            (int) $first->ledgerEntry->actual_cost_microusd + (int) $second->ledgerEntry->actual_cost_microusd,
            (int) $period->committed_microusd,
            'Both calls were charged to the same row.'
        );
        $this->assertSame(0, (int) $period->reserved_microusd, 'And neither left a hold behind.');
        $this->assertSame((int) config('ai.budgets.growth.workspace_cap_microusd'), (int) $period->cap_microusd);
    }
    public function test_settlement_and_expiry_take_their_locks_in_the_same_order(): void
    {
        // Both paths funnel through the one private settle(), which claims
        // the ledger entry before it touches any period row. Asserted over
        // the source because a lock-order inversion is a property of the
        // code, not of any one interleaving a test can reproduce.
        $source = file_get_contents(app_path('Library/Ai/AiUsageLedgerManager.php'));

        $this->assertMatchesRegularExpression(
            '/private function settle\(.*?AiUsageLedgerEntry::query\(\)->lockForUpdate\(\)->find\(.*?\$this->adjustPeriods\(/s',
            $source,
            'settle() must claim the entry under lock BEFORE adjusting the period rows.'
        );

        foreach (['commitActual', 'releaseAsFailed', 'expireStaleReservations'] as $method) {
            $this->assertMatchesRegularExpression(
                '/function ' . $method . '\(.*?\$this->settle\(/s',
                $source,
                $method . '() must settle through the one canonical path.'
            );
        }
    }

    // =================================================================
    // 9 — the estimate accounts for chat framing
    // =================================================================

    /**
     * Twenty one-word messages carry far more provider-billed tokens than
     * their characters suggest, because every message is framed. An
     * estimator that only divided characters by three would reserve a
     * fraction of the real cost — and under concurrent hard-enforced calls
     * that undercount is exactly how committed + reserved creeps past a cap.
     */
    public function test_many_short_messages_reserve_more_than_their_content_bytes_alone_would(): void
    {
        $router = app(AiModelRouter::class);

        $many = [];
        for ($i = 0; $i < 20; $i++) {
            $many[] = ['role' => 'user', 'content' => 'hi'];
        }

        $contentChars = array_sum(array_map(static fn (array $m): int => strlen($m['content']), $many));
        $contentOnlyTokens = (int) ceil($contentChars / (int) config('ai.estimator.chars_per_token'));

        $estimated = $router->estimateInputTokens($many);

        $this->assertGreaterThan(
            $contentOnlyTokens * 4,
            $estimated,
            'Framing dominates when the messages are short, and the estimate must say so.'
        );

        // The same characters in one message cost far less, which is the
        // whole point: shape matters, not just size.
        $one = [['role' => 'user', 'content' => str_repeat('hi', 20)]];
        $this->assertGreaterThan($router->estimateInputTokens($one), $estimated);
    }

    public function test_the_reservation_covers_a_plausible_provider_report_for_the_same_shape(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $router = app(AiModelRouter::class);

        $messages = [];
        for ($i = 0; $i < 30; $i++) {
            $messages[] = ['role' => 'user', 'content' => 'ok'];
        }

        // A provider that bills ~7 tokens of framing per message plus its
        // content — inside what the estimator reserved.
        $plausibleProviderInputTokens = 30 * 7 + 30;

        $this->assertGreaterThanOrEqual(
            $plausibleProviderInputTokens,
            $router->estimateInputTokens($messages),
            'The reservation must not depend on commitActual to catch up afterwards.'
        );

        $this->fakeClient->setDefaultResult(AiCompletionResult::success(
            content: 'ok',
            providerModel: 'model',
            inputTokens: $plausibleProviderInputTokens,
            outputTokens: 10,
        ));

        $this->assertTrue(app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration, $messages))->ok);

        $entry = AiUsageLedgerEntry::query()->latest('id')->firstOrFail();
        $this->assertGreaterThanOrEqual(
            (int) $entry->actual_cost_microusd,
            (int) $entry->estimated_cost_microusd,
            'The estimate was an upper bound on what the provider then reported.'
        );
    }

    // =================================================================
    // 10 — routing uses the period's snapshotted cap
    // =================================================================

    public function test_a_config_cap_raised_mid_period_does_not_change_an_open_periods_headroom(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $reasoning = config('ai.routes.reasoning');
        $required = $reasoning['max_request_cost_microusd'] * $reasoning['min_headroom_multiple'];

        // An open period that snapshotted a cap with too little headroom.
        $this->openPeriod($workspace, capMicrousd: $required, committed: 1);

        // Raising the configured cap must not conjure headroom the period
        // will never actually be allowed to spend.
        config(['ai.budgets.core.workspace_cap_microusd' => $required * 100]);

        $result = app(AiGateway::class)->complete(
            $this->request($workspace, $business, AiUsageCategory::WebsiteGeneration, route: AiModelRoute::Reasoning)
        );

        $this->assertTrue($result->ok);
        $this->assertSame(
            AiModelRoute::Routine->value,
            AiUsageLedgerEntry::query()->latest('id')->firstOrFail()->model_route->value,
            'Headroom is measured against the cap this period is enforced against.'
        );
    }

    public function test_a_config_cap_lowered_mid_period_does_not_withhold_headroom_the_period_still_has(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $reasoning = config('ai.routes.reasoning');
        $required = $reasoning['max_request_cost_microusd'] * $reasoning['min_headroom_multiple'];

        $this->openPeriod($workspace, capMicrousd: $required * 10, committed: 0);

        // The configured cap drops below the requirement; the open period
        // keeps the allowance it snapshotted.
        config(['ai.budgets.core.workspace_cap_microusd' => 1]);

        $result = app(AiGateway::class)->complete(
            $this->request($workspace, $business, AiUsageCategory::WebsiteGeneration, route: AiModelRoute::Reasoning)
        );

        $this->assertTrue($result->ok);
        $this->assertSame(
            AiModelRoute::Reasoning->value,
            AiUsageLedgerEntry::query()->latest('id')->firstOrFail()->model_route->value,
            'An open period keeps the cap it opened with.'
        );
    }

    // -----------------------------------------------------------------

    private function request(
        Workspace $workspace,
        ?Business $business,
        AiUsageCategory $category,
        ?array $messages = null,
        ?AiModelRoute $route = null,
    ): AiRequest {
        $router = app(AiModelRouter::class);
        $route ??= $router->defaultRouteFor($category);

        return new AiRequest(
            workspace: $workspace,
            business: $business,
            category: $category,
            lane: AiLane::Product,
            route: $route,
            messages: $messages ?? [['role' => 'user', 'content' => 'hello world']],
            maxOutputTokens: 50,
            idempotencyKey: (string) Str::uuid(),
            actorUserId: null,
        );
    }

    /** Messages whose estimated input tokens are exactly $target. */
    private function messagesOfExactlyInputTokens(int $target): array
    {
        $router = app(AiModelRouter::class);
        $charsPerToken = (int) config('ai.estimator.chars_per_token');

        // One message: framing is fixed, so solve for the content length and
        // then trim to land exactly on the target.
        $content = str_repeat('a', max(1, ($target - 20) * $charsPerToken));
        $messages = [['role' => 'user', 'content' => $content]];

        while ($router->estimateInputTokens($messages) > $target && strlen($content) > 1) {
            $content = substr($content, 0, -1);
            $messages = [['role' => 'user', 'content' => $content]];
        }

        while ($router->estimateInputTokens($messages) < $target) {
            $content .= 'a';
            $messages = [['role' => 'user', 'content' => $content]];
        }

        return $messages;
    }

    private function reserveOne(Workspace $workspace, Business $business): AiUsageLedgerEntry
    {
        // A real reservation, taken the way the gateway takes one, but left
        // unsettled — the state a caller that died mid-call leaves behind.
        $this->fakeClient->setDefaultResult(AiCompletionResult::success('ok', 'model', 10, 5));
        app(AiGateway::class)->complete($this->request($workspace, $business, AiUsageCategory::WebsiteGeneration));

        $settled = AiUsageLedgerEntry::query()->latest('id')->firstOrFail();

        // Put it back into the reserved state, with its hold standing.
        DB::table('ai_usage_ledger')->where('id', $settled->id)->update([
            'status' => AiUsageEntryStatus::Reserved->value,
            'actual_cost_microusd' => null,
            'settled_at' => null,
        ]);
        DB::table('ai_usage_periods')->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)->where('scope_id', $workspace->id)->update([
            'reserved_microusd' => $settled->estimated_cost_microusd,
            'committed_microusd' => 0,
        ]);
        DB::table('ai_usage_periods')->where('scope_type', AiUsagePeriod::SCOPE_BUSINESS)->where('scope_id', $business->id)->update([
            'reserved_microusd' => $settled->estimated_cost_microusd,
            'committed_microusd' => 0,
        ]);

        return $settled->fresh();
    }

    private function openPeriod(Workspace $workspace, int $capMicrousd, int $committed): void
    {
        AiUsagePeriod::create([
            'scope_type' => AiUsagePeriod::SCOPE_WORKSPACE,
            'scope_id' => $workspace->id,
            'workspace_id' => $workspace->id,
            'period_key' => Carbon::now('UTC')->format('Y-m'),
            'policy_key' => 'core',
            'policy_version' => 1,
            'cap_microusd' => $capMicrousd,
            'committed_microusd' => $committed,
        ]);
    }

    private function workspacePeriod(Workspace $workspace): AiUsagePeriod
    {
        return AiUsagePeriod::query()
            ->where('scope_type', AiUsagePeriod::SCOPE_WORKSPACE)
            ->where('scope_id', $workspace->id)
            ->firstOrFail();
    }

    /** One piece of canonical activity evidence, written where it lives. */
    private function recordSignal(string $signal, Business $business, CarbonImmutable $at, int $userId): void
    {
        match ($signal) {
            'contact' => $this->recordContact($business, $at),
            'conversation' => DB::table('chat_boxes')->insert([
                'uid' => (string) Str::uuid(),
                'user_id' => $business->customer_id,
                'business_id' => $business->id,
                'from' => '18005550100',
                'to' => '1202555' . random_int(1000, 9999),
                'notification' => 0,
                'created_at' => $at,
                'updated_at' => $at,
            ]),
            'ongoing_conversation' => $this->recordOngoingConversation($business, $at),
            'incoming_message' => DB::table('reports')->insert([
                'uid' => uniqid('', true),
                'user_id' => $business->customer_id,
                'business_id' => $business->id,
                'from' => '12025550100',
                'to' => '18005550100',
                'message' => 'Fixture inbound',
                'sms_type' => 'plain',
                'status' => 'Delivered',
                'customer_status' => 'Delivered',
                'direction' => 'incoming',
                'cost' => '0',
                'created_at' => $at,
                'updated_at' => $at,
            ]),
            'automation_run' => $this->recordAutomationRun($business, $at),
            'member_visit' => DB::table('business_home_visits')->insert([
                'user_id' => $userId > 0 ? $userId : $business->customer_id,
                'business_id' => $business->id,
                'current_visit_started_at' => $at,
                'current_visit_last_seen_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ]),
        };
    }

    /**
     * A conversation opened long before the window that the customer wrote in
     * inside it. startedCount cannot see this Business — the thread is years
     * old — so it proves the gate really does ask the read model for messages
     * too, which is what H-4's incomingCount exists to answer.
     */
    private function recordOngoingConversation(Business $business, CarbonImmutable $at): void
    {
        $boxId = DB::table('chat_boxes')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => '18005550100',
            'to' => '1202555' . random_int(1000, 9999),
            'notification' => 0,
            'created_at' => CarbonImmutable::parse('2020-01-01 00:00:00'),
            'updated_at' => CarbonImmutable::parse('2020-01-01 00:00:00'),
        ]);

        DB::table('chat_box_messages')->insert([
            'box_id' => $boxId,
            'message' => 'Still here, are you?',
            'sms_type' => 'sms',
            'send_by' => 'to',
            'direction' => 'incoming',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function recordContact(Business $business, CarbonImmutable $at): void
    {
        $groupId = DB::table('contact_groups')->insertGetId([
            'uid' => uniqid('', true),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Dormancy fixture',
            'status' => true,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('contacts')->insert([
            'uid' => uniqid('', true),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $groupId,
            'phone' => '1202555' . random_int(1000, 9999),
            'status' => 'subscribe',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function recordAutomationRun(Business $business, CarbonImmutable $at): void
    {
        $automationId = DB::table('automations')->insertGetId([
            'uid' => uniqid('', true),
            'business_id' => $business->id,
            'user_id' => $business->customer_id,
            'name' => 'Dormancy fixture',
            'status' => 'active',
            'trigger_type' => 'contact_created',
            'trigger_config' => json_encode(['contact_group_id' => null]),
            'action_type' => 'update_contact_field',
            'action_config' => json_encode(['field_id' => 1, 'value' => 'x']),
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $groupId = DB::table('contact_groups')->insertGetId([
            'uid' => uniqid('', true),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Automation fixture',
            'status' => true,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        // The run needs a contact, and this one is deliberately OLD so the
        // automation signal is proven on its own rather than by the contact
        // that happens to exist beside it.
        $contactId = DB::table('contacts')->insertGetId([
            'uid' => uniqid('', true),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $groupId,
            'phone' => '1303555' . random_int(1000, 9999),
            'status' => 'subscribe',
            'created_at' => CarbonImmutable::parse('2020-01-01 00:00:00'),
            'updated_at' => CarbonImmutable::parse('2020-01-01 00:00:00'),
        ]);

        DB::table('automation_executions')->insert([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'automation_id' => $automationId,
            'contact_id' => $contactId,
            'trigger_type' => 'contact_created',
            'idempotency_key' => 'dormancy:' . uniqid('', true),
            'status' => 'succeeded',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
