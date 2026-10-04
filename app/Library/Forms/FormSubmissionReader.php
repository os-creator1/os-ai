<?php

namespace App\Library\Forms;

use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * Forms V1 — the read side of submissions, where "submission visibility follows
 * the operational Location" is enforced.
 *
 * ONE ACL ALGORITHM. Which Locations an actor may see comes from
 * `LocationAccessGuard::accessibleLocationIdsForBusiness()` — the same
 * decision `userCanAccessLocation()` makes, resolved in a constant number of
 * queries — and every query below is bounded to exactly those ids. A
 * Location-limited staff member therefore never receives another Location's
 * rows, a count of them, or a form's total.
 *
 * BOUNDED. A page is PAGE_SIZE rows, one `count`, one page query and three
 * constant eager loads (form, Location, Contact), however many submissions or
 * Locations exist. Filters (`location`, `form`) are NARROWING only and are
 * re-proven: a Location the actor cannot reach is not found, exactly as an
 * unknown one is, rather than ignored.
 */
final class FormSubmissionReader
{
    public const PAGE_SIZE = 25;

    public function __construct(private readonly LocationAccessGuard $locations)
    {
    }

    /**
     * Locations of $business the actor may see, id => model, in a fixed number of queries.
     *
     * @return array<int, BusinessLocation>
     */
    public function visibleLocations(Business $business, int $userId): array
    {
        $ids = $this->locations->accessibleLocationIdsForBusiness($userId, $business);

        if ($ids === []) {
            return [];
        }

        return BusinessLocation::query()
            ->where('business_id', $business->id)
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<int, BusinessLocation>  $visible  the result of visibleLocations() for this actor
     * @return ?LengthAwarePaginator null when $locationUid names a Location this actor cannot see (the caller answers 404)
     */
    public function page(Business $business, array $visible, ?string $locationUid, ?Form $form, int $page): ?LengthAwarePaginator
    {
        $locationIds = array_keys($visible);

        if ($locationUid !== null && $locationUid !== '') {
            $match = collect($visible)->first(fn (BusinessLocation $location) => $location->uid === $locationUid);

            if ($match === null) {
                return null;
            }

            $locationIds = [(int) $match->id];
        }

        if ($locationIds === []) {
            return new Paginator([], 0, self::PAGE_SIZE, $page);
        }

        $query = FormSubmission::query()
            ->where('business_id', $business->id)
            ->whereIn('business_location_id', $locationIds)
            ->when($form !== null, fn ($q) => $q->where('form_id', $form->id))
            ->with(['form:id,uid,name', 'location:id,uid,name', 'contact:id,uid,phone', 'version:id,form_id,version,pages,fields'])
            ->orderByDesc('id');

        return $query->paginate(self::PAGE_SIZE, ['*'], 'page', max(1, $page));
    }
}
