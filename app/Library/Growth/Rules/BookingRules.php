<?php

declare(strict_types=1);

namespace App\Library\Growth\Rules;

use App\Enums\Business\BusinessGoal;
use App\Enums\Growth\GrowthActionSafetyClass;
use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthMoney;
use App\Library\Growth\GrowthRuleDefinition;

/**
 * booking.type_not_ready:v1 and booking.low_near_term_availability:v1, from
 * CURRENT main's canonical Calendar facts only (see GrowthBookingFactReader).
 * Rules that need the richer booking-type settings / public scheduler fields
 * are documented as deferred, not coded against branches that are not merged.
 */
final class BookingRules extends AbstractGrowthRule
{
    public function __construct(private readonly bool $availability = false)
    {
    }

    public static function lowAvailability(): self
    {
        return new self(true);
    }

    public function definition(): GrowthRuleDefinition
    {
        return $this->availability
            ? new GrowthRuleDefinition(
                key: 'booking.low_near_term_availability:v1',
                worker: OpportunityWorkerKey::Sales,
                category: GrowthCategory::Bookings,
                sourceModule: 'calendar',
                domain: 'booking',
                scope: 'location',
                title: 'Very little time is open for booking this week',
                summary: 'After existing appointments, there is little bookable time left in the next seven days.',
                factKey: 'low_open_booking_time',
                evidenceSummary: 'Bookable minutes in the next 7 days, from staff availability, minus scheduled appointments.',
                actionKey: 'growth_open_availability',
                actionLabel: 'Review availability',
                target: 'calendar',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 2,
                minSample: 1,
                why: 'If there is almost nothing left to book, a new customer who wants to schedule cannot.',
                expected: 'Adding availability gives new customers more times to choose from.',
                goalKeys: [BusinessGoal::LeadGeneration->value],
            )
            : new GrowthRuleDefinition(
                key: 'booking.type_not_ready:v1',
                worker: OpportunityWorkerKey::Sales,
                category: GrowthCategory::Bookings,
                sourceModule: 'calendar',
                domain: 'booking',
                scope: 'location',
                title: 'Some booking types cannot be booked yet',
                summary: 'These active booking types have no assigned staff, or none of their staff has working hours set.',
                factKey: 'booking_types_not_ready',
                evidenceSummary: 'Active booking types with no assigned staff, or whose staff have no availability.',
                actionKey: 'growth_fix_booking_types',
                actionLabel: 'Fix booking types',
                target: 'booking_types',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 3,
                minSample: 1,
                why: 'A booking type that looks live but has nobody available means customers cannot actually book it.',
                expected: 'Once staff and hours are set, customers can book these types.',
                goalKeys: [BusinessGoal::LeadGeneration->value, BusinessGoal::WebsiteConversion->value],
            );
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $booking = $facts->set('booking');
        $out = [];

        if (! $this->availability) {
            foreach ($booking->get('not_ready', []) as $locationId => $row) {
                $out[(int) $locationId] = [
                    'impact' => 4, 'urgency' => 3, 'effort' => 2, 'confidence' => 1.0,
                    'evidence' => ['count' => $row['count'], 'uids' => $row['uids']],
                ];
            }

            return $out;
        }

        $threshold = $facts->thresholds->get('low_availability_open_minutes');

        foreach ($booking->get('capacity', []) as $locationId => $row) {
            $open = max(0, $row['weekly_minutes'] - $row['booked_minutes']);

            if ($row['weekly_minutes'] > 0 && $open <= $threshold) {
                $out[(int) $locationId] = [
                    'impact' => 3, 'urgency' => 3, 'effort' => 2,
                    // Time off is not subtracted, so the true figure can only be lower.
                    'confidence' => 1.0,
                    'evidence' => ['count' => 1, 'open_minutes' => $open, 'weekly_minutes' => $row['weekly_minutes'], 'booked_minutes' => $row['booked_minutes'], 'threshold_minutes' => $threshold],
                ];
            }
        }

        return $out;
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        $booking = $facts->set('booking');

        return $this->availability
            ? count($booking->get('capacity', []))
            : (int) $booking->get('active_type_count', 0);
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['types' => (int) $facts->set('booking')->get('active_type_count', 0)];
    }

    public function headline(array $evidence): string
    {
        if ($this->availability) {
            $hours = round(((int) ($evidence['open_minutes'] ?? 0)) / 60, 1);

            return 'Only ' . rtrim(rtrim(number_format($hours, 1), '0'), '.') . ' hours are open for booking in the next 7 days.';
        }

        return GrowthMoney::plural((int) ($evidence['count'] ?? 0), 'booking type cannot', 'booking types cannot') . ' be booked yet.';
    }

    public function positiveStatement(array $positive): ?string
    {
        return ! $this->availability && $positive['types'] > 0
            ? GrowthMoney::plural($positive['types'], 'booking type is', 'booking types are') . ' ready to take bookings.'
            : null;
    }
}
