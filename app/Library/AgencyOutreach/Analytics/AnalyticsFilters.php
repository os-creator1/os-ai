<?php

namespace App\Library\AgencyOutreach\Analytics;

use Illuminate\Http\Request;

/**
 * The ONE filter set every Outreach analytics number, funnel, chart, table and export shares. It is
 * parsed once from the query string and translated into SQL conditions, so a KPI and its drill-down
 * table can never disagree about which prospects are in scope.
 */
final class AnalyticsFilters
{
    public const PERIODS = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'all' => 'All time'];

    public const RATING_BANDS = ['4.5+' => [4.5, 5.01], '4-4.4' => [4.0, 4.5], '3-3.9' => [3.0, 4.0], 'under-3' => [0.0, 3.0]];

    public const REVIEW_BANDS = ['50+' => [50, null], '10-49' => [10, 50], 'under-10' => [0, 10]];

    public const OUTCOMES = ['no_response', 'replied', 'interested', 'rejected', 'opted_out', 'booked', 'won'];

    public function __construct(
        public readonly ?string $campaignUid = null,
        public readonly string $period = '30',
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $businessType = null,
        public readonly ?string $stepKey = null,
        public readonly ?string $geo = null,
        public readonly ?string $rating = null,
        public readonly ?string $reviews = null,
        public readonly ?string $website = null,
        public readonly ?string $batchUid = null,
        public readonly ?string $outcome = null,
        public readonly ?int $channelId = null,
        public readonly ?int $scriptVersion = null,
        public readonly ?string $q = null,
        public readonly ?string $groupUid = null,
        public readonly ?string $cfKey = null,
        public readonly ?string $cfValue = null,
    ) {
    }

    public static function fromRequest(Request $r): self
    {
        $s = fn (string $k) => ($v = trim((string) $r->query($k, ''))) === '' ? null : mb_substr($v, 0, 120);
        $period = (string) $r->query('period', '30');

        return new self(
            campaignUid: $s('campaign'),
            period: array_key_exists($period, self::PERIODS) ? $period : ($period === 'custom' ? 'custom' : '30'),
            from: self::date($s('from')),
            to: self::date($s('to')),
            businessType: $s('type'),
            stepKey: $s('step'),
            geo: $s('geo'),
            rating: isset(self::RATING_BANDS[(string) $s('rating')]) ? $s('rating') : null,
            reviews: isset(self::REVIEW_BANDS[(string) $s('reviews')]) ? $s('reviews') : null,
            website: in_array($s('website'), ['yes', 'no', 'unknown'], true) ? $s('website') : null,
            batchUid: $s('batch'),
            outcome: in_array($s('outcome'), self::OUTCOMES, true) ? $s('outcome') : null,
            channelId: ctype_digit((string) $s('number')) ? (int) $s('number') : null,
            scriptVersion: ctype_digit((string) $s('script')) ? (int) $s('script') : null,
            q: $s('q'),
            groupUid: $s('group'),
            cfKey: preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) $s('cf')) === 1 ? $s('cf') : null,
            cfValue: $s('cfv'),
        );
    }

    /** @return array{0: ?\Carbon\Carbon, 1: ?\Carbon\Carbon} inclusive start, exclusive end */
    public function window(): array
    {
        if ($this->period === 'custom' && ($this->from !== null || $this->to !== null)) {
            return [$this->from !== null ? \Carbon\Carbon::parse($this->from)->startOfDay() : null,
                $this->to !== null ? \Carbon\Carbon::parse($this->to)->addDay()->startOfDay() : null];
        }

        if ($this->period === 'all') {
            return [null, null];
        }

        return [now()->subDays((int) $this->period)->startOfDay(), null];
    }

    public function active(): bool
    {
        return $this->campaignUid !== null || $this->businessType !== null || $this->stepKey !== null || $this->geo !== null
            || $this->rating !== null || $this->reviews !== null || $this->website !== null || $this->batchUid !== null
            || $this->outcome !== null || $this->channelId !== null || $this->scriptVersion !== null || $this->q !== null
            || $this->groupUid !== null || $this->cfKey !== null
            || $this->period !== '30';
    }

    /** Query string for links that must carry the SAME filters (drill-downs, export). */
    public function toQuery(array $override = []): array
    {
        return array_filter(array_merge([
            'campaign' => $this->campaignUid, 'period' => $this->period !== '30' ? $this->period : null, 'from' => $this->from, 'to' => $this->to,
            'type' => $this->businessType, 'step' => $this->stepKey, 'geo' => $this->geo, 'rating' => $this->rating, 'reviews' => $this->reviews,
            'website' => $this->website, 'batch' => $this->batchUid, 'outcome' => $this->outcome, 'number' => $this->channelId,
            'script' => $this->scriptVersion, 'q' => $this->q, 'group' => $this->groupUid, 'cf' => $this->cfKey, 'cfv' => $this->cfValue,
        ], $override), fn ($v) => $v !== null && $v !== '');
    }

    private static function date(?string $v): ?string
    {
        return $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }
}
