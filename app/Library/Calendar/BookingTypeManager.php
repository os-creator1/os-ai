<?php

namespace App\Library\Calendar;

use App\Models\BookingType;
use App\Models\BusinessLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 15 §5.1, §12.B — the canonical write boundary for
 * Booking Types and their staff pool. Controllers hold no Calendar business
 * logic; every write lands here.
 *
 * AUTHORITY, stated because it differs from availability: §6 makes Booking
 * Type management Location-scoped and ROLE-BLIND — any actor authorized for
 * the Location (owner, Admin or Staff) may manage that Location's Booking
 * Types. This slice deliberately does not invent an Admin-only gate the
 * existing ACL model has no precedent for. The Location authorization itself
 * has already been established by CalendarLocationResolver before anything
 * here runs.
 *
 * NO booking engine, no round-robin execution, no appointment lifecycle:
 * those are Sub-slice C. This class creates and edits configuration rows and
 * nothing else.
 */
class BookingTypeManager
{
    public function __construct(private readonly CalendarLocationResolver $locations)
    {
    }

    /** @return Collection<int, BookingType> */
    public function forLocation(BusinessLocation $location): Collection
    {
        return BookingType::query()
            ->where('business_location_id', $location->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Re-derived from persistence and scoped to the Location, so a Booking
     * Type uid belonging to a sibling Location — or to another Business
     * entirely — is simply not found. The caller turns null into 404.
     */
    public function findForLocation(BusinessLocation $location, string $uid): ?BookingType
    {
        return BookingType::query()
            ->where('business_location_id', $location->id)
            ->where('uid', $uid)
            ->first();
    }

    public function create(BusinessLocation $location, array $attributes, int $actorUserId): BookingType
    {
        return BookingType::create([
            'business_location_id' => $location->id,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'duration_minutes' => (int) $attributes['duration_minutes'],
            'color' => $attributes['color'] ?? null,
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'created_by_user_id' => $actorUserId,
        ]);
    }

    /**
     * `business_location_id` is deliberately absent from the update set: a
     * Booking Type belongs to exactly one Location from creation (§5.1,
     * Addendum §5), and moving one between Locations is not a behaviour any
     * authority describes. `public_booking_uuid` is likewise never rewritten
     * — it is the stable public address (§5.1).
     */
    public function update(BookingType $bookingType, array $attributes): BookingType
    {
        $bookingType->fill([
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'duration_minutes' => (int) $attributes['duration_minutes'],
            'color' => $attributes['color'] ?? null,
        ]);

        if (array_key_exists('is_active', $attributes)) {
            $bookingType->is_active = (bool) $attributes['is_active'];
        }

        $bookingType->save();

        return $bookingType->refresh();
    }

    public function setActive(BookingType $bookingType, bool $isActive): BookingType
    {
        $bookingType->is_active = $isActive;
        $bookingType->save();

        return $bookingType->refresh();
    }

    /**
     * §5.1/§6 — the staff pool is CONFIGURATION INTENT, and this is the
     * configuration-time half of the eligibility rule.
     *
     * Every requested candidate is re-derived through LocationAccessGuard
     * against this Booking Type's OWN Location, at this moment. A candidate
     * who is not currently eligible is REFUSED and never written, so an
     * unrelated Workspace's User cannot be attached by supplying a raw id.
     * Refusals are returned rather than thrown so the surface can name them
     * while still saving the legitimate ones.
     *
     * Detaching is unconditional: removing a nomination never needs the
     * target to still be eligible, and requiring it would strand rows for
     * exactly the staff members who should be removed.
     *
     * @param  array<int, int>  $staffUserIds
     * @return array{attached: array<int, int>, refused: array<int, int>}
     */
    public function syncStaff(BookingType $bookingType, array $staffUserIds): array
    {
        $location = $bookingType->location;

        if ($location === null) {
            return ['attached' => [], 'refused' => array_values(array_unique($staffUserIds))];
        }

        $requested = collect($staffUserIds)
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $eligible = $requested
            ->filter(fn (int $id) => $this->locations->isEligible($id, $location))
            ->values();

        $refused = $requested->diff($eligible)->values();

        DB::transaction(function () use ($bookingType, $eligible): void {
            $bookingType->staff()->sync($eligible->all());
        });

        return [
            'attached' => $eligible->all(),
            'refused' => $refused->all(),
        ];
    }

    /**
     * The pool as CONFIGURED, paired with whether each member is STILL
     * eligible. A stale row is shown as stale rather than silently treated
     * as authorization — §6: "Stale configuration rows never restore access,
     * and this slice never deletes them to achieve that."
     *
     * @return array<int, array{user: \App\Models\User, eligible: bool}>
     */
    public function configuredStaffWithEligibility(BookingType $bookingType): array
    {
        $location = $bookingType->location;

        return $bookingType->staff()->orderBy('users.id')->get()
            ->map(fn ($user) => [
                'user' => $user,
                'eligible' => $location !== null && $this->locations->isEligible((int) $user->id, $location),
            ])
            ->all();
    }
}
