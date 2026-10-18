<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calendar / Booking facts — CURRENT main's canonical tables only.
 *
 * Four constant-count queries (active types, their staff, the staff's weekly
 * availability rules, next-7-day appointments), however many types, staff or
 * appointments exist.
 *
 * What "ready" means here is deliberately the minimum the current product can
 * prove: an active booking type is bookable only if it has at least one
 * assigned staff member AND at least one of those staff has a weekly
 * availability rule at the type's Location. (Richer readiness — buffers,
 * notice windows, public-page settings — lives on branches that are NOT on
 * main; see GROWTH-CENTER-OPPORTUNITY-ENGINE-V1.md §Deferred.)
 *
 * Fact shape (domain `booking`):
 *   active_type_count  int
 *   not_ready          array<int, array{count, uids}>   keyed by Location id
 *   capacity           array<int, array{weekly_minutes, booked_minutes}>
 *                       keyed by Location id, for READY types' staff only
 *
 * Capacity maths. A 7-day window contains each weekday exactly once, so the
 * bookable minutes in "the next 7 days" equal the sum of the staff's weekly
 * rule minutes, whatever day it is and whichever time zone applies. Staff
 * time off is NOT subtracted, so the figure can only OVERSTATE availability —
 * the low-availability rule therefore never fires on a guess that availability
 * is lower than it is. Booked minutes are scheduled appointments overlapping
 * the window. A staff member counted for two types at one Location is counted
 * once.
 */
final class GrowthBookingFactReader implements GrowthFactReader
{
    private const UID_CAP = 10;

    public function domain(): string
    {
        return 'booking';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::Calendar;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $types = DB::table('booking_types as t')
            ->join('business_locations as l', 'l.id', '=', 't.business_location_id')
            ->where('l.business_id', $business->id)
            ->where('l.lifecycle_state', 'active')
            ->where('t.is_active', true)
            ->orderBy('t.id')
            ->get(['t.id', 't.uid', 't.business_location_id']);

        if ($types->isEmpty()) {
            return GrowthFactSet::available($this->domain(), ['active_type_count' => 0, 'not_ready' => [], 'capacity' => []]);
        }

        $staffByType = DB::table('booking_type_staff')
            ->whereIn('booking_type_id', $types->pluck('id'))
            ->get(['booking_type_id', 'staff_user_id'])
            ->groupBy('booking_type_id');

        $locationIds = $types->pluck('business_location_id')->unique()->values();

        $rules = DB::table('staff_availability_rules')
            ->whereIn('business_location_id', $locationIds)
            ->get(['business_location_id', 'staff_user_id', 'start_time', 'end_time']);

        $minutesByLocationStaff = [];

        foreach ($rules as $rule) {
            $minutes = max(0, (strtotime('1970-01-01 ' . $rule->end_time) - strtotime('1970-01-01 ' . $rule->start_time)) / 60);
            $key = $rule->business_location_id . ':' . $rule->staff_user_id;
            $minutesByLocationStaff[$key] = ($minutesByLocationStaff[$key] ?? 0) + (int) $minutes;
        }

        $notReady = [];
        $readyStaff = [];

        foreach ($types as $type) {
            $locationId = (int) $type->business_location_id;
            $staffIds = ($staffByType->get($type->id) ?? collect())->pluck('staff_user_id')->all();
            $hasHours = false;

            foreach ($staffIds as $staffId) {
                if (($minutesByLocationStaff[$locationId . ':' . $staffId] ?? 0) > 0) {
                    $hasHours = true;
                    $readyStaff[$locationId][$staffId] = true;
                }
            }

            if ($staffIds === [] || ! $hasHours) {
                $notReady[$locationId] ??= ['count' => 0, 'uids' => []];
                $notReady[$locationId]['count']++;

                if (count($notReady[$locationId]['uids']) < self::UID_CAP) {
                    $notReady[$locationId]['uids'][] = $type->uid;
                }
            }
        }

        $windowEnd = $now->addDays(7);
        $booked = DB::table('appointments')
            ->whereIn('business_location_id', array_keys($readyStaff) ?: [0])
            ->where('status', 'scheduled')
            ->where('start_at', '<', $windowEnd)
            ->where('end_at', '>', $now)
            ->selectRaw('business_location_id, SUM(TIMESTAMPDIFF(MINUTE, GREATEST(start_at, ?), LEAST(end_at, ?))) AS minutes', [$now, $windowEnd])
            ->groupBy('business_location_id')
            ->pluck('minutes', 'business_location_id');

        $capacity = [];

        foreach ($readyStaff as $locationId => $staff) {
            $weekly = 0;

            foreach (array_keys($staff) as $staffId) {
                $weekly += $minutesByLocationStaff[$locationId . ':' . $staffId] ?? 0;
            }

            $capacity[$locationId] = ['weekly_minutes' => $weekly, 'booked_minutes' => (int) ($booked[$locationId] ?? 0)];
        }

        return GrowthFactSet::available($this->domain(), [
            'active_type_count' => $types->count(),
            'not_ready' => $notReady,
            'capacity' => $capacity,
        ]);
    }
}
