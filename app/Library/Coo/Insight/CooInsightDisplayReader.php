<?php

namespace App\Library\Coo\Insight;

use App\Enums\Coo\CooInsightKind;
use App\Enums\Coo\CooInsightStatementClass;
use App\Library\Ai\AiBudgetPolicyResolver;
use App\Library\Ai\AiUsagePresenter;
use App\Library\Ai\AiUsageReadModel;
use App\Library\Ai\Enums\AiUsageState;
use App\Library\Analytics\AnalyticsDateRange;
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
    public function forHome(Business $business, AnalyticsDateRange $range): ?array
    {
        $insight = CooInsight::query()
            ->where('business_id', (int) $business->id)
            ->where('kind', CooInsightKind::PerformanceDiagnosis->value)
            ->where('subject_type', CooInsight::SUBJECT_BUSINESS)
            ->where('subject_id', (int) $business->id)
            ->where('period_key', $range->cacheKey())
            ->where('prompt_version', (int) config('coo.insight.prompt_version'))
            ->where('policy_version', (int) config('coo.insight.policy_version'))
            ->whereNull('invalidated_at')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first(['id', 'business_id', 'workspace_id', 'output', 'generated_at', 'expires_at']);

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
