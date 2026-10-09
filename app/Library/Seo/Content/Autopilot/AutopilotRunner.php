<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Seo\ArticleStatus;
use App\Jobs\Seo\WriteAutopilotArticleJob;
use App\Library\Ai\AiUsageLedgerManager;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\ContentAutopilotSetting;
use App\Models\Website;
use App\Models\WebsiteArticle;
use Carbon\CarbonInterface;

/**
 * Content Autopilot - one Business, one run. Runs from the daily tick (RunContentAutopilotJob) and is deterministic and free:
 * it never calls a model itself, it only queues the writer job (which is where, and the only place, money is spent).
 *
 *   1. gates      switched on, not paused, plan includes it, has a website - otherwise it does nothing and says why;
 *   2. reconcile  decisions follow their article (published / scheduled / returned to draft / archived);
 *   3. resume     stuck or budget-deferred work continues (a deferred article only once the budget period has rolled over);
 *   4. publish    a validated draft that the niche, the trust ramp and the cadence allow is scheduled;
 *   5. start      at most ONE new article - and only if nothing is in flight, too few drafts wait, the last one was long
 *                 enough ago, and the planner finds a topic genuinely worth writing. Most runs start nothing.
 */
final class AutopilotRunner
{
    private const STALE_DRAFTING_MINUTES = 45;
    private const RETRY_AFTER_HOURS = 6;
    private const MAX_WRITE_ATTEMPTS = 3;

    public function __construct(
        private readonly AutopilotPlanner $planner,
        private readonly AutopilotPublisher $publisher,
        private readonly AutopilotSwitch $switch,
        private readonly EntitlementManager $entitlements,
        private readonly AiUsageLedgerManager $ledger,
    ) {
    }

    /**
     * @return array{ran: bool, skipped: ?string, actions: list<string>}
     */
    public function run(Business $business, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        if (($why = $this->gate($business)) !== null) {
            return $this->skipped($why);
        }

        $actions = [];
        $period = $now->copy()->utc()->format('Y-m');

        $this->reconcile($business, $now);
        array_push($actions, ...$this->resume($business, $now, $period));
        array_push($actions, ...$this->publish($business, $now));

        if ($this->hasDeferredForBudget($business, $period)) {
            $this->switch->markPaused($business, AutopilotSwitch::PAUSE_BUDGET);

            return ['ran' => true, 'skipped' => null, 'actions' => $actions];
        }

        array_push($actions, ...$this->start($business, $now));

        return ['ran' => true, 'skipped' => null, 'actions' => $actions];
    }

    /**
     * Why Autopilot must do nothing for this Business right now (`off`, `paused_by_owner`, `plan`, `no_website`), or null when
     * it may work - recording (or clearing) the reason on the setting. Shared by the daily run and the weekly maintenance.
     */
    public function gate(Business $business): ?string
    {
        $setting = ContentAutopilotSetting::query()->where('business_id', $business->id)->first();

        if ($setting === null || ! $setting->enabled) {
            return 'off';
        }

        if ($setting->paused_reason === AutopilotSwitch::PAUSE_OWNER) {
            return 'paused_by_owner';
        }

        if (! $this->entitlements->decide($business->workspace, $business, PlatformFeature::SeoModule->value, (int) $business->customer_id)->allowed) {
            $this->switch->markPaused($business, AutopilotSwitch::PAUSE_PLAN);

            return 'plan';
        }

        if (Website::query()->where('business_id', $business->id)->doesntExist()) {
            $this->switch->markPaused($business, AutopilotSwitch::PAUSE_NO_WEBSITE);

            return 'no_website';
        }

        $this->switch->markPaused($business, null);

        return null;
    }

    /** Decisions follow their article: the owner may publish, schedule, un-schedule or archive it at any time. */
    private function reconcile(Business $business, CarbonInterface $now): void
    {
        Decision::query()->where('business_id', $business->id)->where('kind', Decision::KIND_CREATE)->whereNotNull('article_id')
            ->whereIn('state', [Decision::STATE_AWAITING_APPROVAL, Decision::STATE_SCHEDULED])->with('article')->get()
            ->each(function (Decision $decision) use ($now) {
                $article = $decision->article;

                if ($article === null) {
                    return;
                }

                match ($article->status) {
                    ArticleStatus::Published => $decision->forceFill(['state' => Decision::STATE_PUBLISHED, 'resolved_at' => $now])->save(),
                    ArticleStatus::Archived => $decision->forceFill(['state' => Decision::STATE_RESOLVED, 'resolved_at' => $now])->save(),
                    ArticleStatus::Scheduled => $decision->state === Decision::STATE_SCHEDULED ? null : $decision->forceFill(['state' => Decision::STATE_SCHEDULED, 'reason_code' => 'owner_scheduled'])->save(),
                    // Back to a draft (publish-due refused it, or the owner un-scheduled it): wait for the owner - never re-schedule on our own.
                    ArticleStatus::Draft => $decision->state === Decision::STATE_SCHEDULED ? $decision->forceFill(['state' => Decision::STATE_AWAITING_APPROVAL, 'reason_code' => 'returned_to_draft'])->save() : null,
                };
            });
    }

    /** @return list<string> */
    private function resume(Business $business, CarbonInterface $now, string $period): array
    {
        $actions = [];

        // A worker that died mid-draft: free it. The ledger's idempotency keys make a second attempt safe (a paid draft is held, never re-bought).
        Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_DRAFTING)
            ->where('updated_at', '<', $now->copy()->subMinutes(self::STALE_DRAFTING_MINUTES))
            ->update(['state' => Decision::STATE_BRIEFED, 'reason_code' => 'stale_drafting']);

        // Budget-deferred work continues only once the budget period has rolled over - never before.
        Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_DEFERRED_BUDGET)
            ->where('period_key', '<', $period)->get()
            ->each(function (Decision $decision) use (&$actions) {
                $decision->forceFill(['state' => Decision::STATE_BRIEFED, 'reason_code' => 'budget_period_rolled'])->save();
                WriteAutopilotArticleJob::dispatch($decision->id);
                $actions[] = 'resumed:' . $decision->uid;
            });

        // A provider outage: try again a few times, a few hours apart, then give up for good (held, not retried).
        Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_BRIEFED)->where('reason_code', 'ai_unavailable')
            ->where('updated_at', '<', $now->copy()->subHours(self::RETRY_AFTER_HOURS))->get()
            ->each(function (Decision $decision) use (&$actions, $now) {
                if ($this->ledger->idempotencyFamily('content_autopilot:' . $decision->uid . ':draft:')['attempts'] >= self::MAX_WRITE_ATTEMPTS) {
                    $decision->forceFill(['state' => Decision::STATE_HELD, 'reason_code' => 'ai_unavailable_gave_up', 'resolved_at' => $now])->save();
                    $actions[] = 'gave_up:' . $decision->uid;

                    return;
                }

                WriteAutopilotArticleJob::dispatch($decision->id);
                $actions[] = 'retried:' . $decision->uid;
            });

        return $actions;
    }

    /** @return list<string> */
    private function publish(Business $business, CarbonInterface $now): array
    {
        $actions = [];

        Decision::query()->where('business_id', $business->id)->where('kind', Decision::KIND_CREATE)->where('state', Decision::STATE_AWAITING_APPROVAL)
            ->whereNotNull('article_id')->orderBy('id')->get()
            ->each(function (Decision $decision) use (&$actions, $now) {
                $wait = $this->publisher->scheduleIfAllowed($decision, $now);

                $actions[] = $wait === null ? 'scheduled:' . $decision->uid : 'waiting:' . $decision->uid . ':' . $wait;
            });

        return $actions;
    }

    /** @return list<string> */
    private function start(Business $business, CarbonInterface $now): array
    {
        $inFlight = Decision::query()->where('business_id', $business->id)->where('kind', Decision::KIND_CREATE)->whereIn('state', [
            Decision::STATE_BRIEFED, Decision::STATE_DRAFTING, Decision::STATE_VALIDATING, Decision::STATE_DEFERRED_BUDGET,
        ])->exists();

        if ($inFlight) {
            return ['wait:in_flight'];
        }

        $waiting = Decision::query()->where('business_id', $business->id)->where('kind', Decision::KIND_CREATE)->whereIn('state', [Decision::STATE_AWAITING_APPROVAL])->count();

        if ($waiting >= max(1, (int) config('seo.content_autopilot.max_awaiting_approval', 2))) {
            return ['wait:drafts_waiting'];
        }

        $spacing = (int) config('seo.content_autopilot.min_days_between_articles', 5);
        $lastStarted = Decision::query()->where('business_id', $business->id)->where('decision', Decision::DECISION_CREATE)
            ->whereNotIn('state', [Decision::STATE_HELD, Decision::STATE_REJECTED, Decision::STATE_RESOLVED])->max('evaluated_at');

        if ($lastStarted !== null && \Illuminate\Support\Carbon::parse($lastStarted)->greaterThan($now->copy()->subDays($spacing))) {
            return ['wait:spacing'];
        }

        $result = $this->planner->evaluate($business, true, $now);

        if ($result['decision'] === Decision::DECISION_CREATE && $result['record'] !== null) {
            WriteAutopilotArticleJob::dispatch($result['record']->id);

            return ['started:' . $result['record']->uid];
        }

        return ['decided:' . $result['decision'] . ':' . $result['reason']];
    }

    private function hasDeferredForBudget(Business $business, string $period): bool
    {
        return Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_DEFERRED_BUDGET)->where('period_key', '>=', $period)->exists();
    }

    /** @return array{ran: bool, skipped: string, actions: list<string>} */
    private function skipped(string $why): array
    {
        return ['ran' => false, 'skipped' => $why, 'actions' => []];
    }
}
