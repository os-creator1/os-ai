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
     * own columns/rows (never from a contact, never from node config):
     *   - one Location / selected Locations → the actor must reach EVERY Location it
     *                            names;
     *   - Business-wide        → the actor must reach every Location, for the same
     *                            reason only they may publish one.
     * A workflow never published has no live scope yet, so it is judged on its draft:
     * a draft bound to Locations needs reach of all of them; an unscoped draft belongs
     * to its creator (who must be able to open it to choose a scope) and to actors
     * with full reach.
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
            if (array_key_exists('live_scope_mode', $attributes)) {
                $scope = $this->liveScope(
                    $attributes['live_scope_mode'],
                    $attributes['live_location_id'] ?? null,
                    $attributes['live_scope_location_ids'] ?? null,
                );
            } else {
                $scope = AutomationWorkflowVersion::query()
                    ->where('workflow_id', (int) $workflow->id)
                    ->whereKey((int) $workflow->published_version_id)
                    ->first()?->scope() ?? new WorkflowLocationScope(WorkflowLocationScope::ONE, []);
            }

            return $this->scopeAllows($userId, $business, $scope);
        }

        $declared = array_key_exists('draft_scope_config', $attributes)
            ? $this->decodeConfig($attributes['draft_scope_config'])
            : (array) (($workflow->draftVersion()?->definition ?? [])['root']['config'] ?? []);
        $scope = WorkflowLocationScope::fromTriggerConfig($declared);

        if ($scope->isBound()) {
            return $this->scopeAllows($userId, $business, $scope);
        }

        return ($workflow->created_by_user_id !== null && (int) $workflow->created_by_user_id === $userId)
            || $this->hasFullReach($userId, $business);
    }

    /**
     * The published scope from the columns the workflow row carried as subselects.
     * An unknown mode reads as a bound scope that names nothing (never Business-wide).
     */
    private function liveScope(mixed $mode, mixed $oneId, mixed $listCsv): WorkflowLocationScope
    {
        return match ((string) $mode) {
            WorkflowLocationScope::BUSINESS => WorkflowLocationScope::business(),
            WorkflowLocationScope::ONE => new WorkflowLocationScope(WorkflowLocationScope::ONE, $oneId === null ? [] : [(int) $oneId]),
            WorkflowLocationScope::SELECTED => new WorkflowLocationScope(
                WorkflowLocationScope::SELECTED,
                array_map('intval', array_filter(explode(',', (string) $listCsv), fn (string $id): bool => $id !== '')),
            ),
            default => new WorkflowLocationScope(WorkflowLocationScope::ONE, []),
        };
    }

    /** @return array<string, mixed> */
    private function decodeConfig(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Does the actor's reach cover a workflow scope: every Location it names — or,
     * for Business-wide, a scope that names nothing, or a Location id that is not
     * even this Business's (a tampered row nobody could otherwise ever open or fix),
     * every Location.
     */
    private function scopeAllows(int $userId, Business $business, WorkflowLocationScope $scope): bool
    {
        if ($scope->isBound() && $scope->ids() !== []) {
            $reach = $this->reachableIds($userId, $business);

            if (array_diff($scope->ids(), $reach) === []) {
                return true;
            }

            $own = BusinessLocation::query()
                ->where('business_id', (int) $business->id)
                ->whereIn('id', $scope->ids())
                ->count();

            // Every named Location is this Business's and one is out of reach: refuse.
            if ($own === count($scope->ids())) {
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
        $reachJson = json_encode(array_values($ids));

        $query->where(function (EloquentBuilder $visible) use ($business, $in, $ids, $userId, $reachJson): void {
            $visible
                // Reaches every Location the Business has: sees everything.
                ->whereRaw(
                    'NOT EXISTS (SELECT 1 FROM business_locations bl WHERE bl.business_id = ? AND bl.id NOT IN (' . $in . '))',
                    [(int) $business->id],
                )
                // Live and bound to one Location they reach.
                ->orWhereExists(fn ($live) => $live->selectRaw('1')
                    ->from('automation_workflow_versions as pv')
                    ->whereColumn('pv.id', 'automation_workflows.published_version_id')
                    ->where('pv.scope_mode', WorkflowLocationScope::ONE)
                    ->whereIn('pv.business_location_id', $ids))
                // Live and bound to selected Locations, every one of which they reach.
                ->orWhereExists(fn ($live) => $live->selectRaw('1')
                    ->from('automation_workflow_versions as pv')
                    ->whereColumn('pv.id', 'automation_workflows.published_version_id')
                    ->where('pv.scope_mode', WorkflowLocationScope::SELECTED)
                    ->whereExists(fn ($any) => $any->selectRaw('1')
                        ->from('automation_workflow_version_locations as vl')
                        ->whereColumn('vl.version_id', 'pv.id'))
                    ->whereNotExists(fn ($outside) => $outside->selectRaw('1')
                        ->from('automation_workflow_version_locations as vl')
                        ->whereColumn('vl.version_id', 'pv.id')
                        ->whereNotIn('vl.business_location_id', $ids)))
                // Never published: their own, or a draft bound to Locations they reach.
                ->orWhere(function (EloquentBuilder $draftOnly) use ($ids, $userId, $reachJson): void {
                    $draftOnly->whereNull('automation_workflows.published_version_id')
                        ->where(function (EloquentBuilder $mine) use ($ids, $userId, $reachJson): void {
                            $mine->where('automation_workflows.created_by_user_id', $userId)
                                ->orWhereExists(fn ($draft) => $draft->selectRaw('1')
                                    ->from('automation_workflow_versions as dv')
                                    ->whereColumn('dv.workflow_id', 'automation_workflows.id')
                                    ->where('dv.state', 'draft')
                                    ->whereRaw(
                                        "CAST(JSON_UNQUOTE(JSON_EXTRACT(dv.definition, '$.root.config.business_location_id')) AS UNSIGNED) IN ("
                                        . implode(',', $ids) . ')',
                                    ))
                                ->orWhereExists(fn ($draft) => $draft->selectRaw('1')
                                    ->from('automation_workflow_versions as dv')
                                    ->whereColumn('dv.workflow_id', 'automation_workflows.id')
                                    ->where('dv.state', 'draft')
                                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(dv.definition, '$.root.config.scope_mode')) = 'selected'")
                                    ->whereRaw("JSON_LENGTH(JSON_EXTRACT(dv.definition, '$.root.config.business_location_ids')) > 0")
                                    ->whereRaw("JSON_CONTAINS(CAST(? AS JSON), JSON_EXTRACT(dv.definition, '$.root.config.business_location_ids'))", [$reachJson]));
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
     * @return array{0: string, 1: WorkflowLocationScope}
     */
    public function scopeOf(array $definition): array
    {
        $root = is_array($definition['root'] ?? null) ? $definition['root'] : [];
        $config = is_array($root['config'] ?? null) ? $root['config'] : [];

        return [(string) ($root['key'] ?? WorkflowDefinitionValidator::DOCUMENT_KEY), WorkflowLocationScope::fromTriggerConfig($config)];
    }

    /**
     * DRAFT rule: a Location id the actor cannot reach is never saved.
     *
     * @param array<string, mixed> $definition
     * @throws ValidationException
     */
    public function assertMayDraft(int $userId, Business $business, array $definition): void
    {
        [$key, $scope] = $this->scopeOf($definition);

        if (! $scope->isBound() || $scope->ids() === []) {
            return;
        }

        if (array_diff($scope->ids(), $this->reachableIds($userId, $business)) !== []) {
            throw ValidationException::withMessages([$key => [
                $scope->mode() === WorkflowLocationScope::SELECTED
                    ? 'You do not have access to one or more of those locations.'
                    : 'You do not have access to that location.',
            ]]);
        }
    }

    /**
     * PUBLISH rule: a bound workflow needs access to every Location it names; a
     * Business-wide one needs access to every Location of the Business.
     *
     * @param array<string, mixed> $definition
     * @throws ValidationException
     */
    public function assertMayPublish(int $userId, Business $business, array $definition): void
    {
        [$key, $scope] = $this->scopeOf($definition);

        if ($scope->isBound()) {
            $this->assertMayDraft($userId, $business, $definition);

            return;
        }

        if (! $this->hasFullReach($userId, $business)) {
            throw ValidationException::withMessages([$key => [
                'Choose one of your locations. A whole-business workflow runs for every location, so it needs access to all of them.',
            ]]);
        }
    }
}