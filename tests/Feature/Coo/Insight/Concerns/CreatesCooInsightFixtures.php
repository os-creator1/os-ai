<?php

namespace Tests\Feature\Coo\Insight\Concerns;

use App\Enums\Coo\CooInsightKind;
use App\Enums\Coo\CooInsightOrigin;
use App\Enums\Coo\CooScope;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Coo\Context\CooContextEnvelope;
use App\Library\Coo\Context\CooContextEnvelopeFactory;
use App\Library\ViewAs\ViewAsContext;
use App\Models\Business;
use App\Models\CooInsight;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;

/**
 * AI-3 fixtures: the frozen clock and Business performance window the Home
 * uses, a period whose facts E-1 accepts, a provider fake that answers with
 * valid output, and cached insight rows.
 *
 * Clock: 2026-09-10 15:00 UTC. "This month" for an America/New_York Business
 * is 1–10 Sep; the window before it is 22–31 Aug.
 */
trait CreatesCooInsightFixtures
{
    use CreatesDashboardFixtures;

    protected FakeAiCompletionClient $fakeAi;

    protected function setUpCooInsights(): void
    {
        $this->freezeClock();

        config(['services.openai.active' => true]);

        $this->fakeAi = new FakeAiCompletionClient();
        $this->fakeAi->setDefaultResult($this->validAnswer());
        $this->app->instance(\App\Library\Ai\Contracts\AiCompletionClient::class, $this->fakeAi);
    }

    /** New contacts 20 vs 5 and messages received 14 vs 3: two material increases. */
    protected function materialPeriod(Business $business): void
    {
        $this->contactsAdded($business, 20, '2026-09-05');
        $this->contactsAdded($business, 5, '2026-08-25');
        $this->receivedAt($business, 14, $this->localNoon('2026-09-06', $business->timezone));
        $this->receivedAt($business, 3, $this->localNoon('2026-08-26', $business->timezone));
    }

    protected function thisMonth(Business $business): AnalyticsDateRange
    {
        return AnalyticsDateRange::preset(BusinessDashboardAnalyticsPresenter::DEFAULT_PRESET, $business->timezone);
    }

    protected function validAnswer(): AiCompletionResult
    {
        return AiCompletionResult::success(json_encode(['statements' => [
            ['class' => 'known', 'text' => 'New contacts rose to 20, from 5 in the ten days before.', 'fact_refs' => ['metric.new_contacts']],
            ['class' => 'likely', 'text' => 'More people may be finding the business than before.', 'fact_refs' => ['metric.new_contacts', 'metric.messages_received']],
            ['class' => 'unknown', 'text' => 'What changed for customers is not visible in these figures.', 'fact_refs' => []],
        ]]), 'fixture-model-2026', 400, 120);
    }

    /**
     * Contract 19 §5.9b — a background generation's envelope, computed for the
     * declared audience (the canonical Workspace owner). This is what the
     * scheduled triggers produce, and what any actor whose live authorization
     * scope is exactly equal may read back.
     */
    protected function backgroundEnvelope(Business $business): CooContextEnvelope
    {
        $envelope = app(CooContextEnvelopeFactory::class)->forBackgroundAudience($business);

        $this->assertNotNull($envelope, 'the fixture Business must have a resolvable background audience.');

        return $envelope;
    }

    /** Contract 19 §5.2 — a human-initiated envelope for one real actor. */
    protected function actorEnvelope(Business $business, User $actor, ?ViewAsContext $viewAs = null): CooContextEnvelope
    {
        $envelope = app(CooContextEnvelopeFactory::class)->forActor($business, $actor, $viewAs);

        $this->assertNotNull($envelope, 'the fixture actor must have a resolvable authorization scope.');

        return $envelope;
    }

    /** @param array<string, mixed> $overrides */
    protected function cachedInsight(Business $business, array $overrides = []): CooInsight
    {
        $now = Carbon::now();

        // A cached row only exists inside an authorization scope. Unless a
        // test says otherwise it is the background one — what the scheduled
        // triggers actually write.
        $envelope = array_key_exists('authorization_scope_fingerprint', $overrides)
            ? null
            : $this->backgroundEnvelope($business);

        return CooInsight::query()->create(array_merge([
            'scope' => CooScope::Business->value,
            'origin' => CooInsightOrigin::System->value,
            'authorization_scope_fingerprint' => $envelope?->authorizationScopeFingerprint,
            'actor_user_id' => null,
            'audience_user_id' => $envelope?->audienceUserId,
            'business_id' => $business->id,
            'workspace_id' => $business->workspace_id,
            'kind' => CooInsightKind::PerformanceDiagnosis->value,
            'subject_type' => CooInsight::SUBJECT_BUSINESS,
            'subject_id' => $business->id,
            'period_key' => $this->thisMonth($business)->cacheKey(),
            'signal_fingerprint' => hash('sha256', (string) Str::uuid()),
            'facts_snapshot' => ['period' => ['key' => $this->thisMonth($business)->cacheKey()], 'facts' => []],
            'output' => ['statements' => [
                ['class' => 'known', 'text' => 'New contacts rose to 20, from 5 in the ten days before.', 'fact_refs' => ['metric.new_contacts']],
                ['class' => 'likely', 'text' => 'More people may be finding the business than before.', 'fact_refs' => []],
            ]],
            'prompt_version' => (int) config('coo.insight.prompt_version'),
            'policy_version' => (int) config('coo.insight.policy_version'),
            'model_route' => 'routine',
            'provider_model' => 'fixture-model-2026',
            'generated_at' => $now->copy()->subDay(),
            'expires_at' => $now->copy()->addDays(6),
        ], $overrides));
    }
}
