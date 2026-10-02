<?php

namespace App\Library\Automation\Workflow;

use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Validation\ValidationException;

/**
 * WHO may scope a workflow to what — the actor half of Location run-scope.
 *
 * Every rule here is the platform's own Location ACL, asked of the one canonical
 * authority (LocationAccessGuard, which already knows owners, members, selected
 * Locations and a View As session). Nothing in this class is a second permission
 * system or a second reach algorithm.
 *
 * A Business-wide workflow is, in effect, authority over EVERY Location of the
 * Business: it enrolls facts from all of them and acts on their contacts. So:
 *
 *   * an actor whose reach is every Location of the Business (an owner, or an
 *     "all locations" member) may publish a Business-wide workflow or bind one to
 *     any Location;
 *   * an actor with SELECTED Locations may bind a workflow only to one of them,
 *     and may NOT publish a Business-wide one merely because they hold the generic
 *     `automations` capability — that would let them process Locations they
 *     cannot see.
 *
 * Drafts are the one softness: saving a draft names no authority until it is
 * published, so an actor with selected Locations may keep an unscoped draft while
 * they pick a Location, but a Location id outside their reach is refused even
 * there — a forged id never gets saved. Publish applies the full rule, against the
 * draft being published, inside its transaction.
 *
 * Automation EXECUTION has no actor and never comes through here.
 */
class WorkflowLocationAuthority
{
    public function __construct(private readonly LocationAccessGuard $guard)
    {
    }

    /** @return list<int> the Business's Location ids this actor may reach */
    public function reachableIds(int $userId, Business $business): array
    {
        return $this->guard->accessibleLocationIdsForBusiness($userId, $business);
    }

    /**
     * Whether the actor reaches EVERY Location the Business has — the test for
     * "may speak for the whole Business". Computed against the Business's
     * Locations as they are now, so a Location added later withdraws it.
     */
    public function hasFullReach(int $userId, Business $business): bool
    {
        $all = BusinessLocation::query()
            ->where('business_id', (int) $business->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $this->coversAll($this->reachableIds($userId, $business), $all);
    }

    /**
     * The same test over ids the caller already holds, so a page that has read the
     * Business's Locations (the builder's catalog) and the actor's reach once does
     * not read either again.
     *
     * @param list<int> $reachableIds
     * @param list<int> $allLocationIds every Location the Business has
     */
    public function coversAll(array $reachableIds, array $allLocationIds): bool
    {
        return array_diff($allLocationIds, $reachableIds) === [];
    }

    /**
     * The picker's rows: only Locations the actor may bind to.
     *
     * @param list<int> $reach the actor's reachable Location ids (reachableIds())
     * @param list<array{id: int, name: string, active: bool}> $locations the catalog's rows
     * @return list<array{id: int, name: string, active: bool}>
     */
    public function pickerLocations(array $reach, array $locations): array
    {
        return array_values(array_filter($locations, fn (array $row): bool => in_array((int) $row['id'], $reach, true)));
    }

    /**
     * The scope a definition's trigger declares, with the trigger's node key.
     *
     * @param array<string, mixed> $definition
     * @return array{0: string, 1: int|null}
     */
    public function scopeOf(array $definition): array
    {
        $root = is_array($definition['root'] ?? null) ? $definition['root'] : [];
        $config = is_array($root['config'] ?? null) ? $root['config'] : [];
        $value = $config['business_location_id'] ?? null;
        $id = (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;

        return [(string) ($root['key'] ?? WorkflowDefinitionValidator::DOCUMENT_KEY), $id];
    }

    /**
     * DRAFT rule: a Location id the actor cannot reach is never saved.
     *
     * @param array<string, mixed> $definition
     * @throws ValidationException
     */
    public function assertMayDraft(int $userId, Business $business, array $definition): void
    {
        [$key, $locationId] = $this->scopeOf($definition);

        if ($locationId !== null && ! $this->mayBindTo($userId, $business, $locationId)) {
            throw ValidationException::withMessages([$key => ['You do not have access to that location.']]);
        }
    }

    /**
     * PUBLISH rule: a bound workflow needs access to its Location; a Business-wide
     * one needs access to every Location of the Business.
     *
     * @param array<string, mixed> $definition
     * @throws ValidationException
     */
    public function assertMayPublish(int $userId, Business $business, array $definition): void
    {
        [$key, $locationId] = $this->scopeOf($definition);

        if ($locationId !== null) {
            $this->assertMayDraft($userId, $business, $definition);

            return;
        }

        if (! $this->hasFullReach($userId, $business)) {
            throw ValidationException::withMessages([$key => [
                'Choose one of your locations. A whole-business workflow runs for every location, so it needs access to all of them.',
            ]]);
        }
    }

    private function mayBindTo(int $userId, Business $business, int $locationId): bool
    {
        return in_array($locationId, $this->reachableIds($userId, $business), true);
    }
}
