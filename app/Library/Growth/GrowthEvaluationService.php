<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Opportunity\Exceptions\RunAlreadyActiveException;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Models\GrowthScoreSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one full Growth evaluation for one Business:
 *
 *   facts (read once)  ->  rule outcomes (pure)  ->  per-worker engine runs
 *                                                ->  one score snapshot
 *
 * ISOLATION. Each engine worker (sales / website / seo / reputation) is its
 * own RFC-002 run, so one failing worker never blocks another: a broken Ads
 * or SEO reader cannot hide CRM opportunities, and an unreadable domain makes
 * the workers that depend on it FAIL their run (recorded, visible to the
 * Platform Owner) instead of "reading zero" and resolving everything they
 * owned. Already-stored Opportunities are left exactly as they were.
 *
 * COST. Facts come from platform-owned tables and cached module readers only.
 * This class, the readers and the rules never call a provider or an AI.
 *
 * It honours the Opportunity Engine's master switch (`opportunity.enabled`)
 * and the AI COO entitlement the engine already sits behind.
 */
final class GrowthEvaluationService
{
    public const PRODUCER_VERSION = 1;

    private const SAFE_ERROR = 'We could not refresh these opportunities right now. Please try again later.';

    public function __construct(
        private readonly GrowthFactSnapshotBuilder $builder,
        private readonly OpportunityManager $manager,
        private readonly GrowthScoreRecorder $scores,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * @return array{
     *     ran: bool,
     *     reason: string|null,
     *     workers: array<string, string>,
     *     outcomes: array<string, GrowthRuleOutcome>,
     *     score: GrowthScoreSnapshot|null
     * }
     */
    public function evaluate(Business $business, ?CarbonImmutable $now = null): array
    {
        if (! config('opportunity.enabled', false)) {
            return $this->skipped('engine_disabled');
        }

        $workspace = $business->workspace;

        if ($workspace === null
            || ! $this->entitlements->decide($workspace, $business, PlatformFeature::AiCooBasic->value, 0)->allowed) {
            return $this->skipped('not_entitled');
        }

        $facts = $this->builder->build($business, $now);
        $outcomes = $this->evaluateRules($facts);

        $workers = [];

        foreach (GrowthRuleRegistry::workerKeys() as $workerKey) {
            $workers[$workerKey] = $this->runWorker(OpportunityWorkerKey::from($workerKey), $business, $facts, $outcomes);
        }

        // The score is recorded only when every worker that reads a failed
        // domain has been accounted for: a half-read evaluation must not write
        // a day's score that omits a category it simply failed to read.
        $score = $facts->failedDomains === [] ? $this->scores->record($business, $facts, $outcomes) : null;

        return ['ran' => true, 'reason' => null, 'workers' => $workers, 'outcomes' => $outcomes, 'score' => $score];
    }

    /**
     * @return array<string, GrowthRuleOutcome> rule key => outcome (every registered rule has one)
     */
    public function evaluateRules(GrowthFactSnapshot $facts): array
    {
        $outcomes = [];

        foreach (GrowthRuleRegistry::all() as $key => $rule) {
            $domain = $rule->definition()->domain;

            if (! $facts->set($domain)->isAvailable()) {
                $outcomes[$key] = GrowthRuleOutcome::notApplicable();

                continue;
            }

            $outcomes[$key] = $rule->evaluate($facts);
        }

        return $outcomes;
    }

    /** @param  array<string, GrowthRuleOutcome>  $outcomes */
    private function runWorker(OpportunityWorkerKey $worker, Business $business, GrowthFactSnapshot $facts, array $outcomes): string
    {
        $domains = array_unique(array_map(
            fn (GrowthRule $r) => $r->definition()->domain,
            GrowthRuleRegistry::forWorker($worker->value),
        ));

        $dependsOnFailedReader = array_filter($domains, fn (string $d) => $facts->hasFailed($d)) !== [];

        $run = null;

        try {
            $run = $this->manager->beginRun($business, $worker, self::PRODUCER_VERSION);
        } catch (RunAlreadyActiveException) {
            return 'skipped_active_run';
        }

        try {
            if ($dependsOnFailedReader) {
                $this->manager->failRun($run, self::SAFE_ERROR);

                return 'failed_reader';
            }

            $producer = new GrowthWorkerProducer($worker, $outcomes, $facts);

            foreach ($producer->produce($business) as $candidate) {
                $this->manager->stageCandidate($run, $candidate);
            }

            $this->manager->finalizeSuccessfulRun($run);

            return 'succeeded';
        } catch (Throwable $e) {
            Log::error('Growth worker run failed', ['business_id' => $business->id, 'worker' => $worker->value, 'exception' => $e]);

            try {
                $this->manager->failRun($run, self::SAFE_ERROR);
            } catch (Throwable $inner) {
                Log::error('Growth worker run also failed to record its failure', ['business_id' => $business->id, 'worker' => $worker->value, 'exception' => $inner]);
            }

            return 'failed';
        }
    }

    /** @return array{ran: bool, reason: string|null, workers: array<string, string>, outcomes: array<string, GrowthRuleOutcome>, score: null} */
    private function skipped(string $reason): array
    {
        return ['ran' => false, 'reason' => $reason, 'workers' => [], 'outcomes' => [], 'score' => null];
    }
}
