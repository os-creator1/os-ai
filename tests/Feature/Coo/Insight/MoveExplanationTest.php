<?php

namespace Tests\Feature\Coo\Insight;

use App\Enums\Coo\CooInsightKind;
use App\Enums\Coo\CooInsightTrigger;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Coo\Context\CooContextEnvelopeFactory;
use App\Library\Coo\Insight\CooInsightGenerator;
use App\Library\Coo\Insight\CooInsightInvalidator;
use App\Library\Coo\Insight\CooInsightOutcome;
use App\Library\Dashboard\DashboardSnapshot;
use App\Models\Business;
use App\Models\CooInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 19 §12 19.C — the AI COO explains the deterministic
 * NextBestMoveSelector pick; it never selects, reorders or changes one (R-1).
 *
 * Covers exactly the sub-slice's own §12 test list: the move renders
 * identically whether the explanation is absent, present or invalidated
 * (the mutation-test guarantee — nothing here ever asserts on `key`/`kind`/
 * `headline`/`actionUrl` changing), grounding (every explanation statement's
 * fact_refs resolve inside the stored facts_snapshot), and no provider call
 * on a Home render — plus the subject/invalidation wiring this sub-slice
 * adds to the existing Contract 19 seams (CooInsight::SUBJECT_OPPORTUNITY,
 * CooInsightInvalidator).
 */
class MoveExplanationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true, 'services.openai.active' => true]);
    }

    public function test_attention_based_move_gets_a_grounded_explanation_and_it_attaches_to_the_move(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Lost Connection Venue', 'Lost Connection Account');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer);

        // A Home visit is real Business activity (AiBusinessActivityGate),
        // so the background trigger below is not refused as dormant.
        $before = $this->move($customer->user);
        $this->assertSame('google_connection_lost', $before['key']);
        $this->assertNull($before['explanation'], 'No cached explanation exists yet.');

        $fakeAi = $this->bindFakeAi('attention.google_connection_lost');
        $outcome = $this->generate($business);

        $this->assertSame(CooInsightOutcome::GENERATED, $outcome->status);
        $this->assertSame(1, $fakeAi->callCount());

        $insight = CooInsight::query()->sole();
        $this->assertSame(CooInsightKind::MoveExplanation, $insight->kind);
        $this->assertSame(CooInsight::SUBJECT_NEXT_BEST_MOVE, $insight->subject_type);
        $this->assertSame(2, $insight->subject_id, 'NextBestMoveSelector::attentionSubjectId(GoogleConnectionLost).');
        $this->assertGroundedInFacts($insight);

        $after = $this->move($customer->user);
        $this->assertSame('google_connection_lost', $after['key'], 'The AI never changes which move is selected (R-1).');
        $this->assertSame($before['headline'], $after['headline']);
        $this->assertSame($before['actionUrl'], $after['actionUrl']);
        $this->assertSame($before['why'], $after['why'], '"Why this?" stays deterministic and untouched.');
        $this->assertNotNull($after['explanation'] ?? null, 'The explanation is now attached.');
        $this->assertNotEmpty($after['explanation']['statements']);
    }

    public function test_opportunity_based_move_gets_a_grounded_explanation_with_the_opportunity_as_its_subject(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Queue Head Venue', 'Queue Head Account');
        $opportunityId = $this->recommendation($business);
        $this->authenticateAs($customer);

        $before = $this->move($customer->user);
        $this->assertSame('opportunity', $before['key']);

        $fakeAi = $this->bindFakeAi('opportunity.missing_website');
        $outcome = $this->generate($business);

        $this->assertSame(CooInsightOutcome::GENERATED, $outcome->status);
        $this->assertSame(1, $fakeAi->callCount());

        $insight = CooInsight::query()->sole();
        $this->assertSame(CooInsight::SUBJECT_OPPORTUNITY, $insight->subject_type);
        $this->assertSame($opportunityId, $insight->subject_id);
        $this->assertGroundedInFacts($insight);

        $after = $this->move($customer->user);
        $this->assertSame($before['key'], $after['key'], 'The AI never changes which move is selected (R-1).');
        $this->assertSame($before['headline'], $after['headline']);
        $this->assertSame($before['actionUrl'], $after['actionUrl']);
        $this->assertSame($before['why'], $after['why'], '"Why this?" stays deterministic and untouched.');
        $this->assertNotNull($after['explanation'] ?? null);

        // Contract 19 §12 19.C reuses CooInsightInvalidator::invalidateForOpportunityWork()
        // unchanged: completing the very Opportunity this explanation is
        // about retires it, exactly as it already does for a PerformanceDiagnosis
        // that cites the same Opportunity type.
        app(CooInsightInvalidator::class)->invalidateForOpportunityWork($business->id, $opportunityId, 'missing_website');
        $this->assertNotNull($insight->fresh()->invalidated_at);
    }

    public function test_nothing_selected_is_skipped_without_any_provider_call(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Caught Up Venue', 'Caught Up Account');
        $this->website($business, 'published');
        $this->googleConnection($business, GoogleConnectionState::Active);
        $this->authenticateAs($customer);

        $before = $this->move($customer->user);
        $this->assertNull($before, 'Nothing to recommend.');

        $fakeAi = $this->bindFakeAi('metric.new_contacts');
        $outcome = $this->generate($business);

        $this->assertSame(CooInsightOutcome::CONDITION_NOT_MET, $outcome->status);
        $this->assertSame(0, $fakeAi->callCount());
        $this->assertSame(0, CooInsight::query()->count());
    }

    public function test_a_context_change_invalidates_a_cached_attention_based_explanation(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Context Venue', 'Context Account');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer);
        $this->move($customer->user);

        $this->bindFakeAi('attention.google_connection_lost');
        $this->generate($business);
        $insight = CooInsight::query()->sole();

        app(CooInsightInvalidator::class)->invalidateContext($business->id);

        $this->assertNotNull($insight->fresh()->invalidated_at);
    }

    public function test_home_render_with_a_cached_explanation_makes_no_provider_call(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Read Only Venue', 'Read Only Account');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer);
        $this->move($customer->user);

        $fakeAi = $this->bindFakeAi('attention.google_connection_lost');
        $this->generate($business);
        $this->assertSame(1, $fakeAi->callCount());

        $move = $this->move($customer->user);
        $this->assertNotNull($move['explanation'] ?? null);
        $this->assertSame(1, $fakeAi->callCount(), 'Reading Home never spends a second call.');
    }

    /**
     * Requested correction — a MoveExplanation whose only statement cites no
     * fact at all (previously accepted, since an "unknown" statement needs no
     * citation for a PerformanceDiagnosis) is now rejected outright: nothing
     * is stored, and Home shows no explanation for the move at all, rather
     * than an unfounded one.
     */
    public function test_an_ungrounded_response_is_rejected_and_never_displayed(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Ungrounded Venue', 'Ungrounded Account');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer);
        $this->move($customer->user);

        $fakeAi = new FakeAiCompletionClient();
        $fakeAi->setDefaultResult(AiCompletionResult::success(json_encode(['statements' => [
            ['class' => 'unknown', 'text' => 'These figures alone do not show what to do about it.', 'fact_refs' => []],
        ]]), 'fixture-model-2026', 200, 40));
        $this->app->instance(AiCompletionClient::class, $fakeAi);

        $outcome = $this->generate($business);

        $this->assertSame(CooInsightOutcome::OUTPUT_REJECTED, $outcome->status);
        $this->assertSame(1, $fakeAi->callCount(), 'The provider was still paid for — rejection is a validator decision, not a refusal.');
        $this->assertSame(0, CooInsight::query()->count(), 'An ungrounded answer is discarded, not stored.');

        $move = $this->move($customer->user);
        $this->assertSame('google_connection_lost', $move['key']);
        $this->assertNull($move['explanation'], 'No explanation is displayed when none was ever grounded.');
    }

    /**
     * The contract's own mutation test (§12 19.C): "a model returning a
     * different action changes nothing on screen." The fake answer here
     * names a completely different action (publishing the website) in its
     * text while still citing a real fact_ref, so it passes validation and
     * is stored — and the deterministic move is asserted, field by field, to
     * be byte-for-byte the one BusinessHomePresenter::nextBestMove() already
     * built before the explanation was ever read (R-1).
     */
    public function test_the_ai_never_changes_which_move_is_selected_even_when_its_text_describes_a_different_action(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Mutation Venue', 'Mutation Account');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->authenticateAs($customer);

        $before = $this->move($customer->user);
        $this->assertSame('google_connection_lost', $before['key']);

        $fakeAi = new FakeAiCompletionClient();
        $fakeAi->setDefaultResult(AiCompletionResult::success(json_encode(['statements' => [
            ['class' => 'unknown', 'text' => 'Publishing the website may matter more than this right now.', 'fact_refs' => ['attention.google_connection_lost']],
        ]]), 'fixture-model-2026', 200, 40));
        $this->app->instance(AiCompletionClient::class, $fakeAi);

        $outcome = $this->generate($business);
        $this->assertSame(CooInsightOutcome::GENERATED, $outcome->status, 'Precondition: the ungrounded-text answer is still stored — validation grounds fact_refs, not the model\'s opinion of which action matters.');

        $after = $this->move($customer->user);
        $this->assertSame($before['kind'], $after['kind']);
        $this->assertSame($before['key'], $after['key'], 'A different action named in the AI text changes nothing on screen (R-1).');
        $this->assertSame($before['headline'], $after['headline']);
        $this->assertSame($before['actionLabel'], $after['actionLabel']);
        $this->assertSame($before['actionUrl'], $after['actionUrl']);
        $this->assertSame($before['why'], $after['why'], '"Why this?" stays deterministic and untouched.');
        $this->assertNotNull($after['explanation'], 'The (differently-worded) explanation still attaches — it just changes nothing else.');
    }

    // -----------------------------------------------------------------
    // Fixtures and helpers
    // -----------------------------------------------------------------

    /**
     * A genuinely grounded, meaningful explanation citing $factRef — never an
     * "unknown" statement with empty fact_refs, which Contract 19 §12 19.C's
     * own grounding requirement now rejects for this kind (see
     * test_an_ungrounded_response_is_rejected_and_never_displayed).
     */
    private function bindFakeAi(string $factRef): FakeAiCompletionClient
    {
        $fakeAi = new FakeAiCompletionClient();
        $fakeAi->setDefaultResult(AiCompletionResult::success(json_encode(['statements' => [
            ['class' => 'unknown', 'text' => 'This may be worth addressing first, though these figures alone do not settle it.', 'fact_refs' => [$factRef]],
        ]]), 'fixture-model-2026', 200, 40));
        $this->app->instance(AiCompletionClient::class, $fakeAi);

        return $fakeAi;
    }

    /**
     * MoveExplanation's origin() is System (background), exactly like
     * E-1/E-2/E-3 — it is dispatched, unactored, from TriggerCooInsightOnWorkFinished
     * and DispatchCooInsightReviews. Its envelope is therefore the declared
     * background audience (the Workspace owner), never an actor envelope
     * (§5.9b) — the same choice GenerateCooInsight::envelope() makes for
     * every other background trigger.
     */
    private function generate(Business $business): CooInsightOutcome
    {
        $fresh = $business->fresh();
        $envelope = app(CooContextEnvelopeFactory::class)->forBackgroundAudience($fresh);
        $this->assertNotNull($envelope, 'the fixture Business must have a resolvable background audience.');

        $range = AnalyticsDateRange::preset(BusinessDashboardAnalyticsPresenter::DEFAULT_PRESET, $fresh->timezone);

        return app(CooInsightGenerator::class)->generate($fresh, CooInsightTrigger::MoveExplanation, $range, $envelope);
    }

    /** @return array<string, mixed>|null */
    private function move(\App\Models\User $user): ?array
    {
        return $this->dashboardFor($user)->band(DashboardSnapshot::BAND_NEXT_BEST_MOVE)['move'] ?? null;
    }

    /**
     * Contract 19 §12 19.C's own grounding requirement: every displayed
     * MoveExplanation statement cites at least one fact_ref, and every
     * fact_ref cited resolves inside the row's own stored facts_snapshot —
     * never merely "happens not to be empty".
     */
    private function assertGroundedInFacts(CooInsight $insight): void
    {
        $refs = collect((array) ($insight->facts_snapshot['facts'] ?? []))
            ->pluck('ref')
            ->all();

        foreach ((array) $insight->output['statements'] as $statement) {
            $this->assertNotEmpty($statement['fact_refs'], 'Every MoveExplanation statement must cite at least one fact_ref.');

            foreach ((array) $statement['fact_refs'] as $ref) {
                $this->assertContains($ref, $refs, "fact_ref [{$ref}] must resolve inside facts_snapshot.");
            }
        }
    }
}
