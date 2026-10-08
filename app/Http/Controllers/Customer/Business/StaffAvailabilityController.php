<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Calendar\StaffAvailabilityAuthorityException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\StaffAvailabilityService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Implementation Contract 15 §5.2, §5.3, §6, §12.B — recurring availability
 * windows and User-global time off.
 *
 * SAME THREE GATES as every other authenticated Calendar route (tenancy →
 * Calendar entitlement → LocationAccessGuard), and then a FOURTH that is
 * unique to this surface: §6's availability-authority table, applied per
 * target staff member and re-derived at write time by
 * StaffAvailabilityService.
 *
 * TWO DIFFERENT REFUSALS, deliberately distinguished:
 *   - 404 for anything about existence or reach — an unentitled feature, a
 *     Location this actor cannot reach, a Location belonging to another
 *     Business, a rule id from a sibling Location. The actor must not learn
 *     the resource exists.
 *   - 403 for the authority table itself — the actor legitimately reached
 *     this Location and surface but may not write THIS target's
 *     availability. Mirrors BusinessLocationsController's own precedent for
 *     a role refusal on a resource the actor can see.
 *
 * TIME OFF IS USER-GLOBAL (§5.3). The Location in the route establishes
 * authority and nothing else: no Location is written to `staff_time_off`,
 * and the surface says so plainly, because time off removes that person
 * from every Location they are granted rather than only this one.
 */
class StaffAvailabilityController extends Controller
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly StaffAvailabilityService $availability,
        private readonly CalendarLocationResolver $locations,
    ) {
    }

    /** Display order of the weekly editor (Monday first) as day_of_week values (0 = Sunday). */
    private const EDITOR_DAYS = [1, 2, 3, 4, 5, 6, 0];

    /** BusinessLocation::hours keys by day_of_week. */
    private const HOURS_KEYS = [0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday'];

    public function index(Request $request, string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $actorId = (int) Auth::id();
        $eligibleStaff = $this->locations->eligibleStaff($workspace, $business, $location);
        $isOwner = $this->availability->isOwner($actorId, $workspace, $business);

        // Whose week the editor shows: only someone this actor may manage
        // (an owner: any eligible person; anyone else: themselves).
        $selectable = $isOwner
            ? $eligibleStaff
            : $eligibleStaff->filter(static fn ($user) => (int) $user->id === $actorId)->values();
        $person = $selectable->firstWhere('id', (int) $request->query('person')) ?? $selectable->first();
        $rules = $this->availability->rulesForLocation($location);

        // "Use business hours": the Location's own canonical hours, offered as
        // an UNSAVED starting point. Nothing is written until the person
        // presses Save; after that they are ordinary editable availability.
        $businessWeek = $this->weekFromBusinessHours($location->hours);
        $prefilled = $request->query('prefill') === 'business' && $businessWeek !== null;
        $week = $prefilled
            ? $businessWeek
            : $this->weekFromRules($person === null ? collect() : $rules->where('staff_user_id', $person->id));

        return view('customer.business.calendar.availability.index', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'rules' => $rules,
            'person' => $person,
            'week' => $week,
            'editorDays' => self::EDITOR_DAYS,
            'hasBusinessHours' => $businessWeek !== null,
            'prefilled' => $prefilled,
            'timeOff' => $this->availability->timeOffForStaff(
                $eligibleStaff->pluck('id')->map(static fn ($id) => (int) $id)->all()
            ),
            'eligibleStaff' => $eligibleStaff,
            'actorId' => $actorId,
            // Presentation only: the same predicate the service enforces, so
            // the surface offers no control the write path would refuse.
            'isOwner' => $isOwner,
        ]);
    }

    /**
     * Replace one person's weekly windows at this Location (the editor's
     * Save). A day is open only if its switch is on; each open day needs at
     * least one window, each window must end after it starts, and a day's
     * windows may not overlap. The write itself is
     * StaffAvailabilityService::replaceWeek(), with the same authority as the
     * per-window writes.
     */
    public function updateWeek(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $validated = $request->validate([
            'staff_user_id' => ['required', 'integer'],
            'open' => ['nullable', 'array'],
            'open.*' => ['in:1'],
            'days' => ['nullable', 'array'],
            'days.*' => ['array'],
            'days.*.*.start' => ['nullable', 'date_format:H:i'],
            'days.*.*.end' => ['nullable', 'date_format:H:i'],
        ]);

        $names = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
        $week = [];
        $problems = [];

        foreach ($names as $day => $name) {
            $week[$day] = [];

            if (! isset(($validated['open'] ?? [])[$day])) {
                continue; // closed
            }

            $windows = [];

            foreach (($validated['days'][$day] ?? []) as $window) {
                $start = $window['start'] ?? null;
                $end = $window['end'] ?? null;

                if ($start === null && $end === null) {
                    continue;
                }

                if ($start === null || $end === null || $end <= $start) {
                    $problems["days.{$day}"] = "{$name}: each set of hours needs an opening time and a later closing time.";

                    continue 2;
                }

                $windows[] = ['start' => $start, 'end' => $end];
            }

            usort($windows, static fn ($a, $b) => strcmp($a['start'], $b['start']));

            foreach ($windows as $i => $window) {
                if ($i > 0 && $window['start'] < $windows[$i - 1]['end']) {
                    $problems["days.{$day}"] = "{$name}: hours must not overlap.";

                    continue 2;
                }
            }

            if ($windows === []) {
                $problems["days.{$day}"] = "{$name}: add opening hours, or switch the day to closed.";

                continue;
            }

            $week[$day] = $windows;
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $this->attempt(fn () => $this->availability->replaceWeek(
            $workspace,
            $business,
            $location,
            (int) $validated['staff_user_id'],
            $week,
            (int) Auth::id()
        ));

        return $this->back($workspaceUid, $businessUid, $locationUid, (int) $validated['staff_user_id'])
            ->with('flash_success', 'Weekly hours saved.');
    }

    public function storeRule(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $validated = $request->validate([
            'staff_user_id' => ['required', 'integer'],
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        $this->attempt(fn () => $this->availability->createRule(
            $workspace,
            $business,
            $location,
            (int) $validated['staff_user_id'],
            (int) $validated['day_of_week'],
            $validated['start_time'] . ':00',
            $validated['end_time'] . ':00',
            (int) Auth::id()
        ));

        return $this->back($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', 'Availability window added.');
    }

    public function destroyRule(string $workspaceUid, string $businessUid, string $locationUid, int $ruleId): RedirectResponse
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $rule = $this->availability->findRuleForLocation($location, $ruleId) ?? abort(404);

        $this->attempt(fn () => $this->availability->deleteRule($workspace, $business, $location, $rule, (int) Auth::id()));

        return $this->back($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', 'Availability window removed.');
    }

    public function storeTimeOff(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $validated = $request->validate([
            'staff_user_id' => ['required', 'integer'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->attempt(fn () => $this->availability->createTimeOff(
            $workspace,
            $business,
            $location,
            (int) $validated['staff_user_id'],
            Carbon::parse($validated['start_at']),
            Carbon::parse($validated['end_at']),
            $validated['reason'] ?? null,
            (int) Auth::id()
        ));

        return $this->back($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', 'Time off added. It applies at every location this person works.');
    }

    public function destroyTimeOff(string $workspaceUid, string $businessUid, string $locationUid, int $timeOffId): RedirectResponse
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $timeOff = $this->availability->findTimeOffWithinReach($location, $timeOffId) ?? abort(404);

        $this->attempt(fn () => $this->availability->deleteTimeOff($workspace, $business, $location, $timeOff, (int) Auth::id()));

        return $this->back($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', 'Time off removed.');
    }

    /**
     * @return array{0: Workspace, 1: Business, 2: BusinessLocation}
     */
    private function scope(string $workspaceUid, string $businessUid, string $locationUid): array
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy(
            $workspaceUid,
            $businessUid,
            PlatformFeature::Calendar->value
        );

        $location = $this->locations->resolveForActor($business, $locationUid, (int) Auth::id());

        if ($location === null) {
            abort(404);
        }

        return [$workspace, $business, $location];
    }

    /**
     * §6's authority table refused this write. The resource is legitimately
     * visible to this actor, so this is a 403 and not a 404 — see the class
     * docblock for why the two are kept apart.
     */
    private function attempt(callable $write): void
    {
        try {
            $write();
        } catch (StaffAvailabilityAuthorityException $exception) {
            throw new AuthorizationException($exception->getMessage());
        }
    }

    private function back(string $workspaceUid, string $businessUid, string $locationUid, ?int $personId = null): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.calendar.availability.index', [
            $workspaceUid, $businessUid, $locationUid,
        ] + ($personId === null ? [] : ['person' => $personId]));
    }

    /**
     * @param  iterable<\App\Models\StaffAvailabilityRule>  $rules  one person's rules
     * @return array<int, list<array{start: string, end: string}>>
     */
    private function weekFromRules(iterable $rules): array
    {
        $week = array_fill(0, 7, []);

        foreach ($rules as $rule) {
            $week[(int) $rule->day_of_week][] = [
                'start' => substr((string) $rule->start_time, 0, 5),
                'end' => substr((string) $rule->end_time, 0, 5),
            ];
        }

        return $week;
    }

    /**
     * The Location's canonical hours (business_locations.hours: weekday key =>
     * [{open, close}], "24:00" the end-of-day sentinel) as editor windows, or
     * null when the Location has none to offer. "24:00" becomes 23:59, because
     * an availability window is a same-day H:i pair.
     *
     * @return array<int, list<array{start: string, end: string}>>|null
     */
    private function weekFromBusinessHours(?array $hours): ?array
    {
        if ($hours === null) {
            return null;
        }

        $week = array_fill(0, 7, []);
        $any = false;

        foreach (self::HOURS_KEYS as $day => $key) {
            foreach ((array) ($hours[$key] ?? []) as $period) {
                $open = $period['open'] ?? null;
                $close = (($period['close'] ?? null) === '24:00') ? '23:59' : ($period['close'] ?? null);

                if (! is_string($open) || ! is_string($close) || $close <= $open) {
                    continue;
                }

                $week[$day][] = ['start' => $open, 'end' => $close];
                $any = true;
            }
        }

        return $any ? $week : null;
    }
}
