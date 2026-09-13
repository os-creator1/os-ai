<?php

namespace App\Library\Coo;

use App\Enums\Dashboard\AttentionType;
use App\Models\Opportunity;

/**
 * Unified Business Home §6.5 (C-2) — "Why this?", with no AI.
 *
 * Every line is either a sentence this application already owns or a fact
 * read from the same source that raised the move. Nothing is paraphrased by a
 * model, nothing is ranked by a score the customer never sees, and a fact the
 * data cannot supply is simply not said (there is no "the oldest has waited
 * since 10:42" because the reply count carries no timestamp).
 */
final class WhyThis
{
    /**
     * The facts behind an attention move, supplied by the presenter from the
     * reads that raised it. Absent keys are not guessed.
     *
     * @param  array{awaiting?: int, graceMinutes?: int, failedRuns?: int, window?: string, unhealthyListings?: int}  $facts
     * @return array<int, string>
     */
    public function forAttention(AttentionType $type, string $sentence, array $facts = []): array
    {
        $lines = [$sentence];

        $fact = match ($type) {
            AttentionType::ConversationsAwaitingReply => isset($facts['awaiting'], $facts['graceMinutes'])
                ? self::plural($facts['awaiting'], 'conversation has', 'conversations have')
                    . ' waited more than ' . $facts['graceMinutes'] . ' minutes for this business to reply, and the customer wrote last in each.'
                : null,
            AttentionType::GoogleConnectionLost => 'Google revoked or expired this authorization. Your linked listing has been kept, and reconnecting resumes reading it.',
            AttentionType::AutomationFailing => isset($facts['failedRuns'], $facts['window'])
                ? self::plural($facts['failedRuns'], 'automation run', 'automation runs') . ' failed ' . $facts['window'] . '.'
                : null,
            AttentionType::GoogleLocationUnhealthy => isset($facts['unhealthyListings'])
                ? self::plural($facts['unhealthyListings'], 'listing is', 'listings are')
                    . ' suspended, disabled, duplicated, unverified or in an ownership dispute on Google.'
                : null,
            AttentionType::WebsiteUnpublished => 'It is saved as a draft and has not been published.',
            default => null,
        };

        if ($fact !== null) {
            $lines[] = $fact;
        }

        return $lines;
    }

    /**
     * The facts behind an Opportunity move: the registry-rendered evidence
     * summaries stored on the opportunity — exactly what the Advisor page
     * shows, never a fact key, an observed value or the raw payload — then
     * its effort and impact in words. The priority score itself is never
     * shown (§6.5).
     *
     * @return array<int, string>
     */
    public function forOpportunity(Opportunity $opportunity): array
    {
        $lines = [];

        foreach (is_array($opportunity->evidence) ? $opportunity->evidence : [] as $item) {
            $summary = is_array($item) ? ($item['summary'] ?? null) : null;

            if (is_string($summary) && trim($summary) !== '') {
                $lines[] = $summary;
            }
        }

        $qualities = array_values(array_filter([
            (int) $opportunity->impact >= 4 ? 'High impact' : null,
            (int) $opportunity->effort <= 2 ? 'Quick to do' : null,
        ]));

        if ($qualities !== []) {
            $lines[] = implode(' · ', $qualities);
        }

        return $lines;
    }

    private static function plural(int $count, string $singular, string $plural): string
    {
        return number_format($count) . ' ' . ($count === 1 ? $singular : $plural);
    }
}
