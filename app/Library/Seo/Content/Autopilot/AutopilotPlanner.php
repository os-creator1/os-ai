<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Models\Business;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\Website;
use Carbon\CarbonInterface;

/**
 * Content Autopilot - the decision step. Deterministic and cheap (no AI, no provider call): rank the computed
 * opportunities, apply the limits, and record ONE outcome:
 *
 *   create       the best eligible topic, with its structured brief;
 *   needs_input  nothing is eligible only because one fact is missing - ask for it, once;
 *   hold         topics exist but are waiting (out of season, thin facts, no page to support);
 *   none         nothing is worth writing. "Do nothing" is a successful outcome.
 *
 * The monthly maximum is a CEILING, never a target: a month with nothing worth saying publishes nothing. Writing,
 * validating, scheduling and publishing are later steps that take a `create` decision from here.
 *
 * `evaluate(persist: false)` is the dry run: the same answer, nothing written.
 */
final class AutopilotPlanner
{
    public const REASON_NO_WEBSITE = 'no_website';
    public const REASON_MONTHLY_CEILING = 'monthly_ceiling';
    public const REASON_NO_CANDIDATES = 'no_candidates';
    public const REASON_WAITING = 'topics_waiting';
    public const REASON_ELIGIBLE = 'eligible';
    public const REASON_NEEDS_FACT = 'needs_fact';

    /** The order we would rather ask: real customer questions help every article, so they come first. */
    private const ASK_ORDER = ['common_questions', 'differentiators', 'emphasis'];

    private const PROMPTS = [
        'common_questions' => 'What do customers ask you most? A couple of real questions let us write articles that answer them.',
        'differentiators' => 'What makes you different from other providers? One or two things are enough.',
        'emphasis' => 'Is there a service or topic you would like us to focus on?',
    ];

    public function __construct(
        private readonly AutopilotScorer $scorer,
        private readonly ArticleBriefBuilder $briefs,
        private readonly ContentFactPack $facts,
        private readonly ContentProfile $profile,
    ) {
    }

    /**
     * @return array{decision: string, reason: string, selected: ?array<string, mixed>, ranked: list<array<string, mixed>>, record: ?Decision}
     */
    public function evaluate(Business $business, bool $persist = true, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $period = $now->copy()->utc()->format('Y-m');

        if (Website::query()->where('business_id', $business->id)->doesntExist()) {
            return $this->outcome($business, $persist, $now, $period, Decision::DECISION_NONE, self::REASON_NO_WEBSITE, [], null, null);
        }

        $pack = $this->facts->forBusiness($business);

        if ($persist) {
            $this->resolveAnsweredQuestions($business, $now);
        }

        $ranked = $this->scorer->rank($business, $pack, $now);

        $blocked = $this->blockedKeys($business, $now);
        $ranked = array_map(fn (array $s) => $s + ['blocked' => isset($blocked[$s['key']])], $ranked);
        $open = array_values(array_filter($ranked, fn (array $s) => ! $s['blocked']));

        if ($this->createdThisMonth($business, $period) >= $this->monthlyMaximum()) {
            return $this->outcome($business, $persist, $now, $period, Decision::DECISION_NONE, self::REASON_MONTHLY_CEILING, $ranked, null, $pack);
        }

        foreach ($open as $scored) {
            if ($scored['band'] === AutopilotScorer::BAND_ELIGIBLE) {
                return $this->outcome($business, $persist, $now, $period, Decision::DECISION_CREATE, self::REASON_ELIGIBLE, $ranked, $scored, $pack);
            }
        }

        if (! $this->hasOpenQuestion($business)) {
            foreach ($open as $scored) {
                if ($scored['band'] === AutopilotScorer::BAND_HOLD && $this->scorer->couldBeEligibleWithMoreFacts($scored)) {
                    $key = $this->nextQuestion($business);

                    if ($key !== null) {
                        return $this->outcome($business, $persist, $now, $period, Decision::DECISION_NEEDS_INPUT, self::REASON_NEEDS_FACT, $ranked, $scored, $pack, $key);
                    }
                }
            }
        }

        $waiting = array_values(array_filter($open, fn (array $s) => $s['band'] === AutopilotScorer::BAND_HOLD));

        return $waiting !== []
            ? $this->outcome($business, $persist, $now, $period, Decision::DECISION_HOLD, self::REASON_WAITING, $ranked, $waiting[0], $pack)
            : $this->outcome($business, $persist, $now, $period, Decision::DECISION_NONE, self::REASON_NO_CANDIDATES, $ranked, null, $pack);
    }

    /** The one open question for the owner, if any. */
    public function openQuestion(Business $business): ?Decision
    {
        return Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_NEEDS_INPUT)->latest('id')->first();
    }

    private function outcome(Business $business, bool $persist, CarbonInterface $now, string $period, string $decision, string $reason, array $ranked, ?array $selected, ?array $pack, ?string $questionKey = null): array
    {
        $record = null;

        if ($persist) {
            $record = $this->record($business, $now, $period, $decision, $reason, $selected, $pack, $questionKey);
        }

        return ['decision' => $decision, 'reason' => $reason, 'selected' => $selected, 'ranked' => $ranked, 'record' => $record];
    }

    private function record(Business $business, CarbonInterface $now, string $period, string $decision, string $reason, ?array $selected, ?array $pack, ?string $questionKey): ?Decision
    {
        $attributes = [
            'business_id' => $business->id,
            'kind' => Decision::KIND_CREATE,
            'decision' => $decision,
            'state' => Decision::STATE_NONE,
            'opportunity_key' => $selected['key'] ?? null,
            'score' => $selected['score'] ?? null,
            'score_breakdown' => $selected !== null ? ['factors' => $selected['breakdown'], 'flags' => $selected['flags'], 'band' => $selected['band']] : null,
            'reason_code' => $reason,
            'fact_hash' => $pack['fact_hash'] ?? null,
            'period_key' => $period,
            'evaluated_at' => $now,
        ];

        if ($decision === Decision::DECISION_CREATE) {
            $brief = $this->briefs->build($business, $selected, $pack);
            $attributes['state'] = Decision::STATE_BRIEFED;
            $attributes['brief'] = $brief;
            $attributes['brief_hash'] = $brief['brief_hash'];
        } elseif ($decision === Decision::DECISION_NEEDS_INPUT) {
            $attributes['state'] = Decision::STATE_NEEDS_INPUT;
            $attributes['needs_input'] = ['key' => $questionKey, 'prompt' => self::PROMPTS[$questionKey]];
        } elseif ($this->sameAsLastIdle($business, $attributes, $now)) {
            // "Nothing to do" / "waiting" for the same reason and the same facts is recorded once, not every tick.
            return null;
        }

        return Decision::query()->create($attributes);
    }

    private function sameAsLastIdle(Business $business, array $attributes, CarbonInterface $now): bool
    {
        $last = Decision::query()->where('business_id', $business->id)->latest('id')->first();

        return $last !== null
            && $last->decision === $attributes['decision']
            && $last->reason_code === $attributes['reason_code']
            && $last->opportunity_key === $attributes['opportunity_key']
            && $last->fact_hash === $attributes['fact_hash']
            && $last->evaluated_at->greaterThan($now->copy()->subDays((int) config('seo.content_autopilot.idle_record_days', 7)));
    }

    /** @return array<string, true> opportunity keys that must not be selected now */
    private function blockedKeys(Business $business, CarbonInterface $now): array
    {
        $cooldown = $now->copy()->subDays((int) config('seo.content_autopilot.retry_cooldown_days', 90));

        $keys = Decision::query()
            ->where('business_id', $business->id)
            ->whereNotNull('opportunity_key')
            ->where(function ($q) use ($cooldown) {
                $q->whereIn('state', Decision::IN_FLIGHT)
                    ->orWhere(fn ($q) => $q->whereIn('state', [Decision::STATE_HELD, Decision::STATE_REJECTED])->where('evaluated_at', '>', $cooldown));
            })
            ->pluck('opportunity_key');

        return array_fill_keys($keys->all(), true);
    }

    /** Real work started this period: briefed, written, awaiting approval, scheduled, published or deferred for budget. */
    private function createdThisMonth(Business $business, string $period): int
    {
        return Decision::query()
            ->where('business_id', $business->id)
            ->where('decision', Decision::DECISION_CREATE)
            ->where('period_key', $period)
            ->whereNotIn('state', [Decision::STATE_HELD, Decision::STATE_REJECTED, Decision::STATE_RESOLVED])
            ->count();
    }

    private function monthlyMaximum(): int
    {
        return max(0, (int) config('seo.content_autopilot.max_new_articles_per_month', 4));
    }

    private function hasOpenQuestion(Business $business): bool
    {
        return $this->openQuestion($business) !== null;
    }

    private function nextQuestion(Business $business): ?string
    {
        $gaps = $this->profile->gaps($business);

        foreach (self::ASK_ORDER as $key) {
            if (in_array($key, $gaps, true)) {
                return $key;
            }
        }

        return null;
    }

    /** Close an open question whose answer now exists (the owner just gave it); the next evaluation re-scores with the new fact. */
    public function closeAnsweredQuestions(Business $business, ?CarbonInterface $now = null): void
    {
        $this->resolveAnsweredQuestions($business, $now ?? now());
    }

    /** An open question whose answer now exists is closed; the next evaluation re-scores with the new fact. */
    private function resolveAnsweredQuestions(Business $business, CarbonInterface $now): void
    {
        $gaps = $this->profile->gaps($business);

        Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_NEEDS_INPUT)->get()
            ->each(function (Decision $open) use ($gaps, $now) {
                if (! in_array($open->needs_input['key'] ?? null, $gaps, true)) {
                    $open->forceFill(['state' => Decision::STATE_RESOLVED, 'resolved_at' => $now])->save();
                }
            });
    }
}
