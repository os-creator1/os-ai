<?php

namespace App\Library\Dashboard\Home;

use App\Enums\Seo\SeoRankTrackingState;
use App\Library\Seo\Rank\SeoRankHistoryReader;
use App\Library\Seo\SeoConfig;
use App\Library\Seo\SeoKeywordManager;
use App\Models\Business;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Growth Home — "Google visibility": a plain-language summary of STORED rank observations. It never starts a rank check (no provider
 * call, no paid work), never shows an average-rank score, and only reports movement when a genuine ~30-day comparison exists.
 *
 * States: not_tracking · establishing (tracked, nothing recorded yet) · first_rankings (recorded, no 30-day comparison yet) ·
 * improving / stable / declining (real 30-day comparison) · unavailable (could not be read — never zero).
 */
final class HomeGoogleVisibility
{
    public function __construct(
        private readonly SeoKeywordManager $keywords,
        private readonly SeoRankHistoryReader $history,
        private readonly SeoConfig $config,
    ) {
    }

    /**
     * @return array{state: string, label: string, detail: ?string, url: ?string}
     */
    public function summary(Business $business, int $actorUserId, ?string $keywordsUrl, ?CarbonImmutable $now = null, bool $mayManage = false, bool $rankEntitled = false): array
    {
        $now ??= CarbonImmutable::now('UTC');

        try {
            $active = $this->keywords->listVisible($actorUserId, $business)->filter(fn ($k) => $k->isActive());
            $targetIds = $active->isEmpty() ? [] : SeoRankTarget::query()
                ->where('business_id', $business->id)
                ->where('tracking_state', SeoRankTrackingState::Tracking->value)
                ->whereIn('seo_keyword_id', $active->pluck('id')->all())
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($targetIds === []) {
                return $this->result('not_tracking', 'Not tracking', 'Add keywords to see how you appear on Google.', $keywordsUrl)
                    + ['activation' => $mayManage && $rankEntitled ? ['available' => true] : null];
            }

            $read = $this->history->thirtyDayChanges($targetIds, $now, $this->config->rankStaleAfterDays());
        } catch (Throwable $e) {
            report($e);

            return $this->result('unavailable', 'Unavailable', 'Google rankings could not be read just now.', $keywordsUrl);
        }

        if (! $read['observed']) {
            return $this->result('establishing', 'Establishing visibility', 'Your keywords are tracked; rankings appear after the first check.', $keywordsUrl);
        }

        if (! $read['fresh']) {
            return $this->result('no_recent', 'No recent rankings', 'No current Google position was found in the latest checks.', $keywordsUrl);
        }

        // One vote per tracked target: local results are preferred (this is a local-business Home); organic is used only when no
        // target has a comparable local pair, and the source is always named.
        $local = array_values(array_filter($read['comparable'], fn (array $c): bool => $c['type'] === 'local'));
        $rows = $local !== [] ? $local : array_values(array_filter($read['comparable'], fn (array $c): bool => $c['type'] === 'organic'));

        if ($rows === []) {
            return $this->result('first_rankings', 'First rankings recorded', 'A 30-day comparison appears once there is enough history.', $keywordsUrl);
        }

        $up = count(array_filter($rows, fn (array $c): bool => $c['change'] > 0));
        $down = count(array_filter($rows, fn (array $c): bool => $c['change'] < 0));
        $detail = ($local !== [] ? 'Local results' : 'Website (organic) results') . ' over 30 days: ' . $up . ' improved, ' . $down . ' declined, ' . (count($rows) - $up - $down) . ' unchanged.';

        return match (true) {
            $up > 0 && $down > 0 => $this->result('mixed', 'Mixed movement', $detail, $keywordsUrl),
            $up > 0 => $this->result('improving', 'Improving', $detail, $keywordsUrl),
            $down > 0 => $this->result('declining', 'Declining', $detail, $keywordsUrl),
            default => $this->result('stable', 'Stable', $detail, $keywordsUrl),
        };
    }

    // Keyword suggestions are deliberately loaded by the existing Keywords page, not Home.
    // Home uses the existing request entitlement snapshot; no paid provider or extra SEO setup reads.

    private function result(string $state, string $label, string $detail, ?string $url): array
    {
        return ['state' => $state, 'label' => $label, 'detail' => $detail, 'url' => $url];
    }
}
