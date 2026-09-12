<?php

namespace App\Library\Dashboard;

use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Unified Business Home §2.3 (Slice H-2) — the per-user, per-Business visit
 * marker, and the activity window derived from it.
 *
 * Rules this class exists to keep:
 *
 *  - The window is read BEFORE anything is written, so a request never
 *    computes its window from its own write.
 *  - A refresh does not restart the window. A Home view within
 *    `home.visit_gap_minutes` of the last one is the SAME visit, and the
 *    "last seen" stamp is rewritten at most once a minute.
 *  - Two tabs racing produce the same window: every write is a single
 *    conditional statement whose WHERE clause stops matching once the other
 *    tab has won, so the loser changes nothing.
 *  - Viewing as a client, or an impersonated session, never writes — looking
 *    at a Business never consumes that customer's own window.
 *  - Nothing is inferred for a Business that has no row: a first visit has no
 *    window at all, and the band is absent.
 *
 * It reads and writes only `business_home_visits`.
 */
final class HomeVisitMarker
{
    public function __construct(private readonly HomeVisitClock $clock = new HomeVisitClock())
    {
    }

    /**
     * Records this Home view and returns the window to count over, or null on
     * a first visit (there is nothing earlier to compare against).
     *
     * @param  bool  $writable  false while viewing as a client or impersonating
     */
    public function observe(Business $business, int $userId, bool $writable): ?HomeActivityWindow
    {
        $now = $this->clock->now();
        $businessId = (int) $business->id;

        $row = DB::table('business_home_visits')
            ->where('user_id', $userId)
            ->where('business_id', $businessId)
            ->first(['window_start_at', 'current_visit_started_at', 'current_visit_last_seen_at']);

        if ($row === null) {
            if ($writable) {
                $this->insertFirstVisit($userId, $businessId, $now);
            }

            return null;
        }

        $lastSeen = CarbonImmutable::parse((string) $row->current_visit_last_seen_at);
        $windowStart = $row->window_start_at === null ? null : CarbonImmutable::parse((string) $row->window_start_at);
        $isNewVisit = $lastSeen->addMinutes($this->gapMinutes())->lessThanOrEqualTo($now);

        // Always derived from the state as it was BEFORE this request: a new
        // visit starts where the previous one was last seen.
        $previousVisitEnd = $isNewVisit ? $lastSeen : $windowStart;

        if ($writable) {
            if ($isNewVisit) {
                $this->startNewVisit($userId, $businessId, $now, $lastSeen);
            } else {
                $this->touchCurrentVisit($userId, $businessId, $now);
            }
        }

        if ($previousVisitEnd === null) {
            // Still inside the very first visit: no earlier point exists.
            return null;
        }

        return $this->windowFor($business, $previousVisitEnd, $now);
    }

    /**
     * The adaptive frame (product correction, 2026-09-12): a previous visit
     * earlier today covers the whole Business-local day, so an hourly visitor
     * still sees a useful day rather than a near-empty delta; 1-6 days counts
     * from that visit; 7+ days becomes a BOUNDED catch-up window of the same
     * length, never an unbounded historical delta.
     */
    private function windowFor(Business $business, CarbonImmutable $previousVisitEnd, CarbonImmutable $now): HomeActivityWindow
    {
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $storage = (string) config('app.timezone', 'UTC');
        $previousLocal = $previousVisitEnd->setTimezone($timezone);
        $nowLocal = $now->setTimezone($timezone);
        $catchUpDays = $this->catchUpDays();

        if ($previousLocal->isSameDay($nowLocal)) {
            return new HomeActivityWindow(
                HomeActivityWindow::MODE_TODAY,
                $nowLocal->startOfDay()->setTimezone($storage),
                $now,
                $timezone,
                $previousLocal,
                $catchUpDays,
            );
        }

        $catchUpStart = $now->subDays($catchUpDays);

        if ($previousVisitEnd->lessThan($catchUpStart)) {
            return new HomeActivityWindow(
                HomeActivityWindow::MODE_CATCH_UP,
                $catchUpStart,
                $now,
                $timezone,
                $previousLocal,
                $catchUpDays,
            );
        }

        return new HomeActivityWindow(
            HomeActivityWindow::MODE_SINCE_LAST_VISIT,
            $previousVisitEnd,
            $now,
            $timezone,
            $previousLocal,
            $catchUpDays,
        );
    }

    /**
     * insertOrIgnore, so two tabs opening a Business for the very first time
     * leave exactly one row and neither fails.
     */
    private function insertFirstVisit(int $userId, int $businessId, CarbonImmutable $now): void
    {
        DB::table('business_home_visits')->insertOrIgnore([
            'user_id' => $userId,
            'business_id' => $businessId,
            'window_start_at' => null,
            'current_visit_started_at' => $now,
            'current_visit_last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * One conditional statement: it applies only while the row still carries
     * the last-seen stamp that made this a new visit, so a second tab in the
     * same moment writes nothing and both read the same window.
     */
    private function startNewVisit(int $userId, int $businessId, CarbonImmutable $now, CarbonImmutable $lastSeen): void
    {
        DB::table('business_home_visits')
            ->where('user_id', $userId)
            ->where('business_id', $businessId)
            ->where('current_visit_last_seen_at', '=', $lastSeen)
            ->update([
                'window_start_at' => $lastSeen,
                'current_visit_started_at' => $now,
                'current_visit_last_seen_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /** Inside the current visit: at most one stamp write per minute. */
    private function touchCurrentVisit(int $userId, int $businessId, CarbonImmutable $now): void
    {
        DB::table('business_home_visits')
            ->where('user_id', $userId)
            ->where('business_id', $businessId)
            ->where('current_visit_last_seen_at', '<=', $now->subSeconds($this->lastSeenWriteSeconds()))
            ->update([
                'current_visit_last_seen_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function gapMinutes(): int
    {
        return max(1, (int) config('home.visit_gap_minutes', 30));
    }

    private function lastSeenWriteSeconds(): int
    {
        return max(0, (int) config('home.visit_last_seen_write_seconds', 60));
    }

    private function catchUpDays(): int
    {
        return max(1, (int) config('home.activity_catch_up_days', 7));
    }
}
