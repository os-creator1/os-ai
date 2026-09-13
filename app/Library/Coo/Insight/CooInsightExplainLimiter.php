<?php

namespace App\Library\Coo\Insight;

use Illuminate\Support\Facades\Cache;

/**
 * Contract §8.2 E-4 — one "Explain this change" per subject per window
 * (`config('coo.insight.explain_window_hours')`, 24 by default).
 *
 * The subject is the Business whose Performance band asked. `claim()` is a
 * single atomic cache add, so two clicks racing get one explanation, not two;
 * `claimed()` is a cache read the Home uses to say "requested" instead of
 * offering the button again. Neither touches the database.
 *
 * The limiter bounds how often a customer can ask; it is not what bounds cost.
 * Cost stays bounded by the gateway's interactive-lane cap and by the insight
 * identity, which never pays twice for identical facts.
 */
final class CooInsightExplainLimiter
{
    public function claim(int $businessId): bool
    {
        return Cache::add($this->key($businessId), time(), $this->windowSeconds());
    }

    public function claimed(int $businessId): bool
    {
        return Cache::has($this->key($businessId));
    }

    private function key(int $businessId): string
    {
        return 'coo_insight:explain:business:' . $businessId;
    }

    private function windowSeconds(): int
    {
        return max(1, (int) config('coo.insight.explain_window_hours', 24)) * 3600;
    }
}
