<?php

namespace App\Library\Automation\Workflow;

use App\Library\Workspace\LocationAccessGuard;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
     * May this actor operate this EXISTING workflow at all — open, edit, publish,
     * pause, resume, archive, read its history and logs, stop it, enroll into it,
     * test it? Fail closed.
     *
     * The authority is the workflow's LIVE scope, read from the published version's
     * own column (never from a contact, never from node config):
     *   - bound to a Location  → the actor must reach that Location;
     *   - Business-wide        → the actor must reach every Location, for the same
     *                            reason only they may publish one.
     * A workflow never published has no live scope yet, so it is judged on its draft:
     * a draft bound to a Location needs reach of it; an unscoped draft belongs to its
     * creator (who must be able to open it to choose a Location) and to actors with
     * full reach.
     */
    public function mayOperate(int $userId, Business $business, AutomationWorkflow $workflow): bool
    {
        if ((int) $workflow->business_id !== (int) $business->id) {
            return false;
        }

        // The scope normally arrives with the workflow row (resolveWorkflow selects it
        // as subselects, costing no extra read); a bare model is read here instead.
        $attributes = $workflow->getAttributes();

        if ($workflow->published_version_id !== null) {
            $live = array_key_exists('live_location_id', $attributes)
                ? $attributes['live_location_id']
                : AutomationWorkflowVersion::query()
                    ->where('workflow_id', (int) $workflow->id)
                    ->whereKey((int) $workflow->published_version_id)
                    ->value('business_location_id');

            return $this->scopeAllows($userId, $business, $live === null ? null : (int) $live);
        }

        $declared = array_key_exists('draft_location_id', $attributes)
            ? $attributes['draft_location_id']
            : ($this->scopeOf((array) ($workflow->draftVersion()?->definition ?? []))[1]);
        $scope = (is_int($declared) || (is_string($declared) && ctype_digit($declared))) && (int) $declared > 0 ? (int) $declared : null;

        if ($scope !== null) {
            return $this->scopeAllows($userId, $business, $scope);
        }

        return ($workflow->created_by_user_id !== null && (int) $workflow->created_by_user_id === $userId)
            || $this->hasFullReach($userId, $business);
    }

    /**
     * Does the actor's reach cover a workflow scope: a Location they reach, or —
     * for Business-wide, or a Location id that is not even this Business's (a
     * tampered row nobody could otherwise ever open or fix) — every Location.
     */
    private function scopeAllows(int $userId, Business $business, ?int $scope): bool
    {
        if ($scope !== null) {
            $reach = $this->reachableIds($userId, $business);

            if (in_array($scope, $reach, true)) {
                return true;
            }

            $ownLocation = BusinessLocation::query()->where('business_id', (int) $business->id)->whereKey($scope)->exists();

            if ($ownLocation) {
                return false;
            }
        }

        return $this->hasFullReach($userId, $business);
    }

    /**
     * Narrow the workflow LIST to what the actor may operate, in the page query
     * itself (no row is fetched and then hidden). One read — the actor's reach — and
     * the "reaches every Location" test is a NOT EXISTS inside the same statement.
     * Same rule as mayOperate(), expressed in SQL.
     */
    public function restrictListing(EloquentBuilder $query, int $userId, Business $business): void
    {
        $reach = array_map('intval', $this->reachableIds($userId, $business));
        $ids = $reach === [] ? [-1] : $reach;
        $in = implode(',', $ids);

        $query->where(function (EloquentBuilder $visible) use ($business, $in, $ids, $userId): void {
            $visible
                // Reaches every Location the Business has: sees everything.
                ->whereRaw(
                    'NOT EXISTS (SELECT 1 FROM business_locations bl WHERE bl.business_id = ? AND bl.id NOT IN (' . $in . '))',
                    [(int) $business->id],
                )
                // Live and bound to a Location they reach.
                ->orWhereExists(fn ($live) => $live->selectRaw('1')
                    ->from('automation_workflow_versions as pv')
                    ->whereColumn('pv.id', 'automation_workflows.published_version_id')
                    ->whereIn('pv.business_location_id', $ids))
                // Never published: their own, or a draft bound to a Location they reach.
                ->orWhere(function (EloquentBuilder $draftOnly) use ($ids, $userId): void {
                    $draftOnly->whereNull('automation_workflows.published_version_id')
                        ->where(function (EloquentBuilder $mine) use ($ids, $userId): void {
                            $mine->where('automation_workflows.created_by_user_id', $userId)
                                ->orWhereExists(fn ($draft) => $draft->selectRaw('1')
                                    ->from('automation_workflow_versions as dv')
                                    ->whereColumn('dv.workflow_id', 'automation_workflows.id')
                                    ->where('dv.state', 'draft')
                                    ->whereRaw(
                                        "CAST(JSON_UNQUOTE(JSON_EXTRACT(dv.definition, '$.root.config.business_location_id')) AS UNSIGNED) IN ("
                                        . implode(',', $ids) . ')',
                                    ));
                        });
                });
        });
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
