<?php

namespace App\Library\Coo\Insight;

use App\Enums\Coo\CooInsightKind;
use App\Enums\Coo\CooInsightOrigin;
use App\Enums\Coo\CooInsightStatementClass;
use App\Library\Ai\AiBudgetPolicyResolver;
use App\Library\Ai\AiUsagePresenter;
use App\Library\Ai\AiUsageReadModel;
use App\Library\Ai\Enums\AiUsageState;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Coo\Context\CooContextEnvelope;
use App\Models\Business;
use App\Models\CooInsight;
use Illuminate\Support\Carbon;

/**
 * Contract §2.5, §9.2 (slice AI-3) — the ONE cached insight row the Business
 * Home may read, and whether it may be shown.
 *
 * READ, NEVER GENERATE. This class has no AI client, no gateway and no job in
 * its dependency graph. The Home read is one indexed statement: the newest
 * insight for this Business, this window, the current prompt and policy
 * versions, not invalidated.
 *
 * §9.2, exactly:
 *  - invalidated → never shown (excluded by the read itself);
 *  - not expired → shown;
 *  - expired → shown ONLY while it cannot be replaced: AI is switched off, or
 *    this account's included AI is used up (AI-2's own state). While the AI
 *    could regenerate, an expired answer is not presented as current.
 *    That last check costs its reads only on this rare branch.
 *
 * Anything that does not look like validated output renders nothing: a row
 * is shown whole or not at all.
 *
 * Implementation Contract 19 sub-slice 19.A added the authorization half of
 * that read. A cached answer is now selectable only by an actor whose
 * CooContextEnvelope — recomputed from live state by the caller, never
 * restored from storage — carries EXACTLY the fingerprint the row was
 * generated under (§5.8 R-22, R-31), plus the §5.9 origin rule: a background
 * row belongs to nobody and is readable by any exactly-matching scope, while
 * a human's own answer is readable only by that human.
 *
 * There is NO fallback (R-32). When nothing matches exactly the method
 * returns null and Home renders without an AI line; it never relaxes a
 * component of the fingerprint, ignores one, or degrades to `business_id`.
 */
final class CooInsightDisplayReader
{
    public function __construct(
        private readonly AiBudgetPolicyResolver $policies,
        private readonly AiUsageReadModel $usage,
        private readonly AiUsagePresenter $usagePresenter,
    ) {
    }

    /**
     * @return array{statements: array<int, array{class: string, label: string, text: string}>, updated: string, generated_at: string}|null
     */
    public function forHome(Business $business, AnalyticsDateRange $range, CooContextEnvelope $envelope): ?array
    {
        if ($envelope->businessId !== (int) $business->id) {
            return null;
        }

        $insight = CooInsight::query()
            ->where('scope', $envelope->scope->value)
            // Contract 19 §5.8 R-22 — the fingerprint compared here was
            // recomputed from the live envelope by the caller. The stored copy
            // is never trusted as proof of authorization; it is only the thing
            // the live claim must match, exactly (R-31).
            ->where('authorization_scope_fingerprint', $envelope->authorizationScopeFingerprint)
            ->where('business_id', (int) $business->id)
            ->where('kind', CooInsightKind::PerformanceDiagnosis->value)
            ->where('subject_type', CooInsight::SUBJECT_BUSINESS)
            ->where('subject_id', (int) $business->id)
            ->where('period_key', $range->cacheKey())
            ->where('prompt_version', (int) config('coo.insight.prompt_version'))
            ->where('policy_version', (int) config('coo.insight.policy_version'))
            ->whereNull('invalidated_at')
            ->where(function ($query) use ($envelope): void {
                // §5.9 — a background row was written for an audience and
                // belongs to nobody, so anyone whose live scope matches
                // exactly may read it. A human's own answer belongs to them
                // alone (R-26) and is never served to a second actor.
                $query->where('origin', CooInsightOrigin::System->value);

                if ($envelope->actorUserId !== null) {
                    $query->orWhere(function ($ownRow) use ($envelope): void {
                        $ownRow->where('origin', CooInsightOrigin::OnDemand->value)
                            ->where('actor_user_id', $envelope->actorUserId);
                    });
                }
            })
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first(['id', 'business_id', 'workspace_id', 'output', 'generated_at', 'expires_at']);

        // R-32 — no fallback. When nothing matches exactly, Home renders
        // without an AI insight; it never relaxes a component of the
        // fingerprint, never ignores one, and never degrades to business_id.
        if ($insight === null) {
            return null;
        }

        if ($insight->expires_at !== null && $insight->expires_at->isPast() && ! $this->cannotBeReplaced($business)) {
            return null;
        }

        $statements = $this->statements($insight);

        if ($statements === null) {
            return null;
        }

        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));

        return [
            'statements' => $statements,
            'updated' => 'Updated ' . Carbon::parse($insight->generated_at)->setTimezone($timezone)->format('j M Y'),
            'generated_at' => Carbon::parse($insight->generated_at)->toIso8601String(),
        ];
    }

    /** §9.2 — AI off, or the account's included AI used up (L-12: cached AI stays visible). */
    private function cannotBeReplaced(Business $business): bool
    {
        if (! (bool) config('services.openai.active')) {
            return true;
        }

        $business->loadMissing('workspace');

        if ($business->workspace === null) {
            return false;
        }

        $policy = $this->policies->resolveFor($business->workspace);

        if ($policy->workspaceCapMicrousd <= 0) {
            return false;
        }

        return $this->usagePresenter->stateFor($this->usage->workspaceStanding((int) $business->workspace->id, $policy)) === AiUsageState::LimitReached;
    }

    /** @return array<int, array{class: string, label: string, text: string}>|null */
    private function statements(CooInsight $insight): ?array
    {
        $raw = is_array($insight->output) ? ($insight->output['statements'] ?? null) : null;

        if (! is_array($raw) || $raw === [] || count($raw) > CooInsightOutputValidator::MAX_STATEMENTS) {
            return null;
        }

        $statements = [];

        foreach ($raw as $statement) {
            $class = is_array($statement) && is_string($statement['class'] ?? null) ? CooInsightStatementClass::tryFrom($statement['class']) : null;
            $text = is_array($statement) && is_string($statement['text'] ?? null) ? trim($statement['text']) : '';

            if ($class === null || $text === '' || mb_strlen($text) > CooInsightOutputValidator::MAX_TEXT_LENGTH) {
                return null;
            }

            $statements[] = ['class' => $class->value, 'label' => $class->label(), 'text' => $text];
        }

        return $statements;
    }
}
