<?php

declare(strict_types=1);

namespace App\Library\Workspace\Migration;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Business\BusinessLocationManager;
use App\Library\Entitlement\EntitlementManager;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessPayerAssignment;
use App\Models\Workspace;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Implementation Contract 10 — Agency Data Migration.
 *
 * Versioned, immutable, invoked by a thin Artisan wrapper (mirrors
 * WorkspaceBackfillV1's own pattern). Moves each non-primary ("client")
 * Business out of a legacy multi-Business Agency Workspace into its own
 * new Client Workspace, establishes the Contract 01 relationship, and
 * resolves the Contract 09 payer matrix — by REASSIGNING the existing
 * Business row, never creating a duplicate one.
 *
 * REUSE, NOT REIMPLEMENTATION. This class owns none of the following —
 * it only sequences calls to them in the contract's own corrected order
 * (§4): WorkspaceManager::createWorkspace()/reassignBusiness(),
 * EntitlementManager::assignFirstPlan(), AgencyClientRelationshipManager::
 * createForMigration(), BusinessLocationManager::upsertPrimaryLocation().
 * WorkspaceManager.php and WorkspaceMembershipBusinessRepository.php are
 * never modified — reassignBusiness() already calls
 * removeAllForBusinessInWorkspace() internally.
 *
 * ACTOR CHOICE (mechanically verified, not assumed): every legacy Business
 * sharing one Agency Workspace also shares that Workspace's OWNER's own
 * customer_id (confirmed: WorkspaceController::storeBusiness() creates a
 * second Business in an existing Workspace under Auth::user()->customer —
 * the SAME owner, never a distinct real client User). There is no separate
 * real "client" identity to assign the new Client Workspace to, so it is
 * created under the SAME real owner. This is what makes
 * WorkspaceManager::reassignBusiness()'s own authority requirement (owner-
 * or-active-admin over BOTH the source and target Workspace, independently
 * — confirmed by direct reading, no platform-operator bypass exists in
 * WorkspaceManager) satisfiable with a real, non-fabricated actor: the
 * Agency owner genuinely owns both Workspaces once the new one is created.
 * This mirrors the EXISTING product feature at
 * WorkspaceController::reassignBusiness() (RFC-003 Milestone 4 Slice 4E) —
 * an owner moving a Business between two Workspaces they own — run here
 * systematically by an operator, under the same real owner's own standing
 * authority, not a fabricated one. This is distinct from, and narrower
 * than, the operator authority Contract 01's own createForMigration()
 * requires for the RELATIONSHIP step (§6) — the Agency owner did not
 * decide to enter into an Agency<->Client relationship with a stranger
 * Workspace; the operator is the one asserting that new cross-tenant fact,
 * and is recorded honestly as its establishing actor.
 *
 * ATOMICITY / CONCURRENCY (§7). Each Business's cutover is its OWN
 * transaction — not one transaction per Agency — so a failure migrating
 * one Business never disturbs an already-completed sibling, and a partial
 * multi-Business run is safely resumable (§7's own "never leaving some in
 * old shape, some in new, indefinitely" framing implies exactly this: a
 * single Business's move is the true atomic unit). The Agency Workspace
 * row and the target Business row are BOTH locked (`findForUpdate`) as the
 * very first statements inside that transaction, and the Business's
 * `workspace_id` is re-verified against the Agency Workspace's id under
 * that lock before anything else happens — a second concurrent attempt at
 * the same Business blocks on this lock, then finds it no longer a
 * candidate once the first attempt commits, and skips cleanly with ZERO
 * Workspace/plan/relationship created for the loser.
 *
 * RESUMABILITY (§7). No bespoke tracking column: a migrated Business's
 * `workspace_id` is simply no longer the original Agency Workspace's id,
 * so it silently drops out of the very query that selects candidates —
 * confirmed sufficient by §3's full entity inventory (no table needs its
 * own migration-tracking column).
 */
final class AgencyBusinessMigrationV1
{
    private const MIGRATION_PLAN_TIER = WorkspacePlanTier::Core;

    private const PLAN_ASSIGNMENT_REASON = 'Implementation Contract 10 — Agency data migration.';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly BusinessLocationManager $locationManager,
        private readonly BusinessLocationRepository $locationRepository,
        private readonly AgencyClientRelationshipManager $relationshipManager,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
        private readonly BusinessPayerAssignmentRepository $payerAssignmentRepository,
    ) {
    }

    /**
     * Contract 09's own migration must already be applied — Contract 10
     * creates no schema of its own (§12) and never proceeds without this.
     */
    public function schemaReady(): bool
    {
        return Schema::hasColumns('business_payer_assignments', [
            'managing_agency_relationship_id',
            'agency_rebill_consented_at',
            'agency_rebill_consented_by_user_id',
        ]);
    }

    /**
     * Read-only preflight (§8.1). Performs zero writes — every decision
     * branch §4/§5 would take is computed and reported, never executed.
     *
     * @param  array<int, int>|null  $agencyWorkspaceIds  restrict to these Workspace ids, or null for every candidate
     * @return array{schema_ready: bool, agencies: array<int, array<string, mixed>>}
     */
    public function preflight(?array $agencyWorkspaceIds = null): array
    {
        if (! $this->schemaReady()) {
            return ['schema_ready' => false, 'agencies' => []];
        }

        $agencies = $this->candidateAgencyWorkspaces($agencyWorkspaceIds);

        return [
            'schema_ready' => true,
            'agencies' => $agencies->map(fn (Workspace $agency) => $this->reportForAgency($agency))->values()->all(),
        ];
    }

    /**
     * Executes the migration. $dryRun reuses the exact same read/decision
     * logic as preflight() (§8.2) — it is never a "real run wrapped in a
     * transaction that gets rolled back," since that would still dispatch
     * real domain events for listeners that are not themselves undone by a
     * later ROLLBACK. A dry run therefore calls zero mutating manager
     * methods at all.
     *
     * @param  array<int, int>|null  $agencyWorkspaceIds
     * @return array{schema_ready: bool, dry_run?: bool, agencies: array<int, array<string, mixed>>}
     */
    public function run(int $operatorUserId, bool $dryRun = false, ?array $agencyWorkspaceIds = null): array
    {
        if (! $this->schemaReady()) {
            return ['schema_ready' => false, 'agencies' => []];
        }

        $agencies = $this->candidateAgencyWorkspaces($agencyWorkspaceIds);

        $results = $agencies->map(function (Workspace $agency) use ($operatorUserId, $dryRun) {
            return $dryRun
                ? $this->reportForAgency($agency)
                : $this->executeAgency($operatorUserId, $agency);
        })->values()->all();

        return [
            'schema_ready' => true,
            'dry_run' => $dryRun,
            'agencies' => $results,
        ];
    }

    /**
     * Every Agency-tier Workspace currently holding more than one Business
     * (Contract 10 §1's exact population) — never a heuristic, never
     * inferred from a switcher or membership shape. `additional_business_
     * slot_agreements` is deliberately not consulted here: it explains WHY
     * a legacy Workspace may hold extra Businesses, but the actual
     * candidate test is the Businesses that exist today, per Workspace.
     *
     * @param  array<int, int>|null  $agencyWorkspaceIds
     * @return \Illuminate\Support\Collection<int, Workspace>
     */
    private function candidateAgencyWorkspaces(?array $agencyWorkspaceIds): \Illuminate\Support\Collection
    {
        $agencyTierWorkspaceIds = DB::table('workspaces')
            ->join('workspace_plan_assignments', 'workspace_plan_assignments.workspace_id', '=', 'workspaces.id')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_assignments.workspace_plan_catalog_id')
            ->where('workspace_plan_catalog.tier', WorkspacePlanTier::Agency->value)
            ->pluck('workspaces.id');

        $multiBusinessWorkspaceIds = DB::table('businesses')
            ->select('workspace_id')
            ->whereIn('workspace_id', $agencyTierWorkspaceIds)
            ->whereNotNull('workspace_id')
            ->groupBy('workspace_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('workspace_id');

        $candidateIds = $agencyWorkspaceIds === null
            ? $multiBusinessWorkspaceIds
            : $multiBusinessWorkspaceIds->intersect($agencyWorkspaceIds);

        return \App\Models\Workspace::query()
            ->whereIn('id', $candidateIds->values())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportForAgency(Workspace $agency): array
    {
        $primaries = Business::query()
            ->where('workspace_id', $agency->id)
            ->where('is_primary', true)
            ->orderBy('id')
            ->get();

        if ($primaries->count() !== 1) {
            return [
                'agency_workspace_id' => $agency->id,
                'agency_workspace_uid' => $agency->uid,
                'status' => 'blocked',
                'reason' => 'ambiguous_primary_business',
                'primary_business_count' => $primaries->count(),
                'businesses' => [],
            ];
        }

        $candidates = Business::query()
            ->where('workspace_id', $agency->id)
            ->where('is_primary', false)
            ->orderBy('id')
            ->get();

        return [
            'agency_workspace_id' => $agency->id,
            'agency_workspace_uid' => $agency->uid,
            'status' => $candidates->isEmpty() ? 'no_action' : 'ready',
            'primary_business_id' => $primaries->first()->id,
            'businesses' => $candidates->map(fn (Business $business) => $this->reportForBusiness($business))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportForBusiness(Business $business): array
    {
        $classification = $this->classifyPayer($business);
        $hasPrimaryLocation = $this->locationRepository->findPrimary($business) !== null;

        return [
            'business_id' => $business->id,
            'business_uid' => $business->uid,
            'business_name' => $business->name,
            'payer_action' => $classification['action'],
            'payer_type_current' => $classification['current_type'],
            'primary_location_present' => $hasPrimaryLocation,
            'blocking' => $classification['action'] === 'unresolved',
            'blocking_reason' => $classification['reason'] ?? null,
        ];
    }

    /**
     * §5's exact three-case matrix, read-only. Never guesses: any shape
     * that is not one of the three named cases is 'unresolved' and halts
     * only this one Business, never the whole migration (§5's own closing
     * sentence, acceptance criterion 2).
     *
     * @return array{action: string, current_type: ?string, reason?: string}
     */
    private function classifyPayer(Business $business): array
    {
        $assignment = $this->payerAssignmentRepository->findByBusinessId((int) $business->id);

        if ($assignment === null) {
            return ['action' => 'unresolved', 'current_type' => null, 'reason' => 'missing_payer_assignment'];
        }

        return match ($assignment->payer_type) {
            PayerType::Business => ['action' => 'unchanged', 'current_type' => PayerType::Business->value],
            PayerType::Workspace => ['action' => 'convert_to_pending_agency_rebill', 'current_type' => PayerType::Workspace->value],
            PayerType::AgencyRebill => ['action' => 'unresolved', 'current_type' => PayerType::AgencyRebill->value, 'reason' => 'already_agency_rebill_before_migration'],
        };
    }

    /**
     * Real writes for one Agency Workspace: each of its non-primary
     * Businesses is attempted independently (§4/§7); one Business's
     * failure is recorded and never aborts its siblings.
     *
     * @return array<string, mixed>
     */
    private function executeAgency(int $operatorUserId, Workspace $agency): array
    {
        $primaries = Business::query()
            ->where('workspace_id', $agency->id)
            ->where('is_primary', true)
            ->orderBy('id')
            ->get();

        if ($primaries->count() !== 1) {
            return [
                'agency_workspace_id' => $agency->id,
                'agency_workspace_uid' => $agency->uid,
                'status' => 'blocked',
                'reason' => 'ambiguous_primary_business',
                'primary_business_count' => $primaries->count(),
                'businesses' => [],
            ];
        }

        // Re-selected fresh for every Agency, not memoized across the run:
        // an earlier Business in this same loop may have just migrated,
        // which is exactly how resumability requires this query to behave
        // even within a single invocation of run().
        $candidates = Business::query()
            ->where('workspace_id', $agency->id)
            ->where('is_primary', false)
            ->orderBy('id')
            ->get();

        $businessResults = $candidates
            ->map(fn (Business $business) => $this->migrateOneBusiness($operatorUserId, $agency, $business))
            ->values()
            ->all();

        return [
            'agency_workspace_id' => $agency->id,
            'agency_workspace_uid' => $agency->uid,
            'status' => 'processed',
            'primary_business_id' => $primaries->first()->id,
            'businesses' => $businessResults,
        ];
    }

    /**
     * The corrected six-step per-Business cutover (§4), one transaction,
     * all or nothing. The Agency Workspace and target Business rows are
     * locked FIRST — before step 1 ever runs — so a second concurrent
     * attempt at the exact same Business can never create a second Client
     * Workspace: it blocks on this lock, then observes (under its own
     * lock) that the Business already moved, and returns 'already_migrated'
     * having created nothing.
     *
     * @return array<string, mixed>
     */
    private function migrateOneBusiness(int $operatorUserId, Workspace $agency, Business $business): array
    {
        try {
            return DB::transaction(function () use ($operatorUserId, $agency, $business) {
                $lockedAgency = $this->workspaceRepository->findForUpdate((int) $agency->id);

                if ($lockedAgency === null) {
                    throw new \App\Exceptions\Workspace\WorkspaceNotFoundException((int) $agency->id);
                }

                $lockedBusiness = $this->businessRepository->findForUpdate((int) $business->id);

                if ($lockedBusiness === null) {
                    throw new \App\Exceptions\Workspace\WorkspaceBusinessNotFoundException((int) $business->id);
                }

                // Resumability + same-Business concurrency safety: re-verify
                // under lock, not from the (possibly stale) caller-supplied
                // model. A prior run, or a racing process that won, already
                // moved this Business — nothing to do, nothing created.
                if ((int) $lockedBusiness->workspace_id !== (int) $lockedAgency->id) {
                    return $this->outcome($lockedBusiness, 'already_migrated');
                }

                if ((bool) $lockedBusiness->is_primary) {
                    // Defense in depth: the candidate query already excludes
                    // primaries, so this should be unreachable.
                    return $this->outcome($lockedBusiness, 'skipped', 'primary_business');
                }

                $lockedPayerAssignment = $this->payerAssignmentRepository->findForUpdateByBusinessId((int) $lockedBusiness->id);
                $classification = $this->classifyPayerAssignment($lockedPayerAssignment);

                if ($classification['action'] === 'unresolved') {
                    return $this->outcome($lockedBusiness, 'blocked', $classification['reason']);
                }

                // Step 1 — Contract 07's own createWorkspace(), unchanged.
                // Owner = the SAME real owner as the Agency Workspace (see
                // class docblock: no separate real client identity exists
                // for a legacy multi-Business Agency Workspace).
                $clientWorkspace = $this->workspaceManager->createWorkspace(
                    (int) $lockedAgency->owner_user_id,
                    $lockedBusiness->name,
                );

                // Step 2 — plan assignment BEFORE reassignment (bug fix 1,
                // §4): a bare createWorkspace() has no plan, and
                // reassignBusiness()'s own capacity check would otherwise
                // throw WorkspacePlanUnassignedException every time.
                $this->entitlementManager->assignFirstPlan(
                    $clientWorkspace,
                    self::MIGRATION_PLAN_TIER,
                    $operatorUserId,
                    self::PLAN_ASSIGNMENT_REASON,
                    true,
                    0,
                );

                // Step 3 — reassign the EXISTING Business row. Actor = the
                // real owner, who now owns both Workspaces (never the
                // operator, who owns neither) — see class docblock.
                $this->workspaceManager->reassignBusiness(
                    (int) $lockedAgency->owner_user_id,
                    $lockedBusiness,
                    $clientWorkspace,
                );

                // Step 4 — the Contract 01 relationship, immediately after
                // the move (bug fix 2, §4): the payer decision (step 6)
                // needs this relationship's id to exist. Actor = the real
                // human operator (§6) — never a fabricated Agency action.
                $relationship = $this->relationshipManager->createForMigration(
                    $operatorUserId,
                    $lockedAgency,
                    $clientWorkspace,
                );

                // Step 5 — primary Location repair ONLY if genuinely
                // absent (§3). BusinessLocation rows key on business_id and
                // survived the reassignment automatically and untouched;
                // this never runs for a Business that already has one.
                $primaryLocationCreated = false;

                if ($this->locationRepository->findPrimary($lockedBusiness) === null) {
                    $this->locationManager->upsertPrimaryLocation($lockedBusiness, [
                        'service_mode' => 'storefront',
                        'country_code' => 'US',
                    ]);
                    $primaryLocationCreated = true;
                }

                // Step 6 — the payer matrix (§5), only now that the
                // relationship exists. 'business' rows are untouched
                // (self-pay, business_id FK unaffected by the Workspace
                // move); 'workspace' rows convert to a pre-consent
                // agency_rebill row — the timestamp/actor consent columns
                // are deliberately NEVER written here, so no charge can
                // proceed until the real Agency owner consents via
                // Contract 09's own flow.
                if ($classification['action'] === 'convert_to_pending_agency_rebill') {
                    $this->payerAssignmentRepository->update($lockedPayerAssignment, [
                        'payer_type' => PayerType::AgencyRebill,
                        'managing_agency_relationship_id' => $relationship->id,
                    ]);
                }

                return $this->outcome(
                    $lockedBusiness,
                    'migrated',
                    null,
                    clientWorkspace: $clientWorkspace,
                    relationship: $relationship,
                    payerAction: $classification['action'],
                    primaryLocationCreated: $primaryLocationCreated,
                );
            });
        } catch (Throwable $e) {
            return $this->outcome($business, 'failed', $e::class . ': ' . $e->getMessage());
        }
    }

    private function classifyPayerAssignment(?BusinessPayerAssignment $assignment): array
    {
        if ($assignment === null) {
            return ['action' => 'unresolved', 'reason' => 'missing_payer_assignment'];
        }

        return match ($assignment->payer_type) {
            PayerType::Business => ['action' => 'unchanged'],
            PayerType::Workspace => ['action' => 'convert_to_pending_agency_rebill'],
            PayerType::AgencyRebill => ['action' => 'unresolved', 'reason' => 'already_agency_rebill_before_migration'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(
        Business $business,
        string $status,
        ?string $reason = null,
        ?Workspace $clientWorkspace = null,
        ?\App\Models\AgencyClientWorkspaceRelationship $relationship = null,
        ?string $payerAction = null,
        bool $primaryLocationCreated = false,
    ): array {
        return [
            'business_id' => $business->id,
            'business_uid' => $business->uid,
            'business_name' => $business->name,
            'status' => $status,
            'reason' => $reason,
            'client_workspace_id' => $clientWorkspace?->id,
            'client_workspace_uid' => $clientWorkspace?->uid,
            'relationship_id' => $relationship?->id,
            'payer_action' => $payerAction,
            'primary_location_created' => $primaryLocationCreated,
        ];
    }
}
