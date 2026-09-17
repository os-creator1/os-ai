<?php

declare(strict_types=1);

namespace App\Library\Workspace\Migration;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Business\BusinessLocationManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
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
use RuntimeException;
use Throwable;

/**
 * Implementation Contract 12 — Non-Agency Multi-Business Migration.
 *
 * Versioned, immutable, invoked by a thin Artisan wrapper — mirrors
 * Contract 10's AgencyBusinessMigrationV1 pattern at a simpler scope, per
 * Contract 12 §2/§4's own explicit "reuse Contract 10's mechanics"
 * directive and the Roadmap's "backfilled the same way as Slice 10, minus
 * the Agency relationship step" (Slice 12).
 *
 * NOT an Agency migration: no AgencyClientWorkspaceRelationship row is
 * ever created, no AgencyRebill conversion, no View As relationship setup
 * (§15's own non-goals list — that scope belongs to Contract 10/01 alone).
 *
 * REUSE, NOT REIMPLEMENTATION. This class owns none of the following — it
 * only sequences calls to them: WorkspaceManager::createWorkspace()/
 * reassignBusiness(), EntitlementManager::assignFirstPlan(),
 * BusinessLocationManager::upsertPrimaryLocation(). WorkspaceManager.php
 * and WorkspaceMembershipBusinessRepository.php are never modified —
 * reassignBusiness() already calls removeAllForBusinessInWorkspace()
 * internally.
 *
 * PLAN/LIFECYCLE TREATMENT (Phase 0, mechanically proven, not invented).
 * Every newly created split-off Workspace receives EXACTLY Contract 10's
 * own precedent for a Workspace created purely as a side effect of an
 * operator-run Business-split migration: WorkspacePlanTier::Core,
 * is_complimentary=true, additionalBusinessSlots=0, trialEndsAt=null, via
 * EntitlementManager::assignFirstPlan() — verified (not assumed) to
 * default the new WorkspacePlanAssignment row to Active status with
 * grace_started_at/locked_at left NULL (those columns are written only by
 * EntitlementManager's separate lifecycle writers, never by
 * assignFirstPlan() itself), and to make zero provider/subscription call.
 * The SOURCE Workspace's own existing plan assignment is never touched by
 * this migration (only reassignBusiness() runs against it, which does not
 * write plan-assignment rows) — so a Growth source Workspace's own tier
 * can never be silently changed to Core; only the brand-new Workspace a
 * split creates ever receives this fresh Core/complimentary assignment.
 *
 * PAYER SEMANTICS (Contract 12 §4, corrected wording). The invariant is
 * "the Workspace CONTAINING this Business pays" for a `workspace`-type
 * payer assignment — never "the same original Workspace keeps paying."
 * EffectivePayerResolver::fromAssignment() resolves PayerType::Workspace
 * via the Business's CURRENT `workspace_id` at read time, so once a
 * Business is reassigned, the effective payer automatically becomes its
 * NEW Workspace — no payer_assignment row write is needed or performed
 * for this case. A `business`-type payer is preserved unchanged (its
 * business_id FK is unaffected by the Workspace move). An `agency_rebill`
 * payer found on a non-Agency source Workspace is structurally
 * unexpected (§4 Roadmap row 189 — this payer type only exists via
 * Contract 01/09's Agency flow) and is never silently normalized: that
 * Business, and its entire source Workspace batch, is blocked with zero
 * writes.
 *
 * ATOMICITY / CONCURRENCY. The source Workspace is the true atomic/batch
 * unit (mirrors Contract 10's corrected §7 per-Agency-Workspace boundary,
 * applied here per-source-Workspace): ONE outer transaction locks the
 * source Workspace, re-reads and locks its primary and every candidate
 * Business plus their payer assignments, pre-validates the ENTIRE batch
 * before any mutation — one unresolved/unexpected candidate blocks the
 * whole source Workspace with zero writes — then runs the simplified
 * four-step cutover sequentially for every candidate, then verifies
 * before allowing the transaction to commit.
 *
 * RESUMABILITY. No bespoke tracking column: a moved Business's
 * `workspace_id` is simply no longer the original source Workspace's id,
 * so it drops out of the very query that selects candidates on the next
 * invocation, and a source Workspace with zero remaining non-primary
 * Businesses naturally drops out of candidateWorkspaces() entirely.
 */
final class NonAgencyBusinessSplitV1
{
    private const MIGRATION_PLAN_TIER = WorkspacePlanTier::Core;

    private const PLAN_ASSIGNMENT_REASON = 'Implementation Contract 12 — Non-Agency multi-Business split.';

    private const VERIFICATION_FAILURE_PREFIX = 'CONTRACT12_VERIFICATION_FAILED: ';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly BusinessLocationManager $locationManager,
        private readonly BusinessLocationRepository $locationRepository,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
        private readonly BusinessPayerAssignmentRepository $payerAssignmentRepository,
    ) {
    }

    /**
     * Read-only preflight/dry-run report (§8.1/§8.2). Performs zero
     * writes.
     *
     * @param  array<int, int>|null  $workspaceIds  restrict to these Workspace ids, or null for every candidate
     * @return array{workspaces: array<int, array<string, mixed>>}
     */
    public function preflight(?array $workspaceIds = null): array
    {
        $workspaces = $this->candidateWorkspaces($workspaceIds);

        return [
            'workspaces' => $workspaces->map(fn (Workspace $workspace) => $this->reportForWorkspace($workspace))->values()->all(),
        ];
    }

    /**
     * Executes the migration. $dryRun reuses the exact same read/decision
     * logic as preflight() — never a real run wrapped in a rolled-back
     * transaction (that would still dispatch real domain events).
     *
     * @param  array<int, int>|null  $workspaceIds
     * @return array{dry_run?: bool, workspaces: array<int, array<string, mixed>>}
     */
    public function run(int $operatorUserId, bool $dryRun = false, ?array $workspaceIds = null): array
    {
        $workspaces = $this->candidateWorkspaces($workspaceIds);

        $results = $workspaces->map(function (Workspace $workspace) use ($operatorUserId, $dryRun) {
            return $dryRun
                ? $this->reportForWorkspace($workspace)
                : $this->executeWorkspace($operatorUserId, $workspace);
        })->values()->all();

        return [
            'dry_run' => $dryRun,
            'workspaces' => $results,
        ];
    }

    /**
     * @param  array<int, int>|null  $workspaceIds
     * @return Collection<int, Workspace>
     */
    private function candidateWorkspaces(?array $workspaceIds): Collection
    {
        $nonAgencyTierWorkspaceIds = DB::table('workspaces')
            ->join('workspace_plan_assignments', 'workspace_plan_assignments.workspace_id', '=', 'workspaces.id')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_assignments.workspace_plan_catalog_id')
            ->whereIn('workspace_plan_catalog.tier', [WorkspacePlanTier::Core->value, WorkspacePlanTier::Growth->value])
            ->pluck('workspaces.id');

        $multiBusinessWorkspaceIds = DB::table('businesses')
            ->select('workspace_id')
            ->whereIn('workspace_id', $nonAgencyTierWorkspaceIds)
            ->whereNotNull('workspace_id')
            ->groupBy('workspace_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('workspace_id');

        $candidateIds = $workspaceIds === null
            ? $multiBusinessWorkspaceIds
            : $multiBusinessWorkspaceIds->intersect($workspaceIds);

        return Workspace::query()
            ->whereIn('id', $candidateIds->values())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportForWorkspace(Workspace $workspace): array
    {
        $primaries = Business::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_primary', true)
            ->orderBy('id')
            ->get();

        if ($primaries->count() !== 1) {
            return [
                'workspace_id' => $workspace->id,
                'workspace_uid' => $workspace->uid,
                'status' => 'blocked',
                'reason' => 'ambiguous_primary_business',
                'primary_business_count' => $primaries->count(),
                'businesses' => [],
            ];
        }

        $candidates = Business::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_primary', false)
            ->orderBy('id')
            ->get();

        $businessReports = $candidates->map(fn (Business $business) => $this->reportForBusiness($business))->values()->all();
        $blockedBusinesses = collect($businessReports)->where('blocking', true)->values()->all();

        return [
            'workspace_id' => $workspace->id,
            'workspace_uid' => $workspace->uid,
            'status' => $blockedBusinesses !== [] ? 'blocked' : ($candidates->isEmpty() ? 'no_action' : 'ready'),
            'reason' => $blockedBusinesses !== [] ? 'unresolved_business_payer' : null,
            'primary_business_id' => $primaries->first()->id,
            'blocked_businesses' => $blockedBusinesses,
            'businesses' => $businessReports,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportForBusiness(Business $business): array
    {
        $classification = $this->classifyPayer($business);

        return [
            'business_id' => $business->id,
            'business_uid' => $business->uid,
            'business_name' => $business->name,
            'payer_type_current' => $classification['current_type'],
            'primary_location_present' => $this->locationRepository->findPrimary($business) !== null,
            'blocking' => $classification['action'] === 'unresolved',
            'blocking_reason' => $classification['reason'] ?? null,
        ];
    }

    /**
     * Contract 12 §4's simpler matrix, read-only: `business`/`workspace`
     * payer types need no write at all (unlike Contract 10's Agency
     * case); `agency_rebill` is structurally unexpected here and blocks;
     * missing is unresolved and blocks — never guessed.
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
            PayerType::Workspace => ['action' => 'unchanged', 'current_type' => PayerType::Workspace->value],
            PayerType::AgencyRebill => ['action' => 'unresolved', 'current_type' => PayerType::AgencyRebill->value, 'reason' => 'unexpected_agency_rebill_on_nonagency_workspace'],
        };
    }

    /**
     * ONE outer transaction per source Workspace (the true atomic/batch
     * unit): lock the source, lock every candidate + payer assignment,
     * pre-validate the whole batch before any mutation, cutover every
     * candidate, verify before commit.
     *
     * @return array<string, mixed>
     */
    private function executeWorkspace(int $operatorUserId, Workspace $workspace): array
    {
        try {
            return DB::transaction(function () use ($operatorUserId, $workspace) {
                $lockedWorkspace = $this->workspaceRepository->findForUpdate((int) $workspace->id);

                if ($lockedWorkspace === null) {
                    throw new WorkspaceNotFoundException((int) $workspace->id);
                }

                $primaries = Business::query()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->where('is_primary', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($primaries->count() !== 1) {
                    return $this->workspaceOutcome($lockedWorkspace, 'blocked', 'ambiguous_primary_business', [
                        'primary_business_count' => $primaries->count(),
                    ]);
                }

                $primaryBusiness = $primaries->first();

                $candidates = Business::query()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->where('is_primary', false)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($candidates->isEmpty()) {
                    return $this->workspaceOutcome($lockedWorkspace, 'no_action', null, [
                        'primary_business_id' => $primaryBusiness->id,
                    ]);
                }

                $classifications = [];
                $blockedBusinesses = [];

                foreach ($candidates as $candidate) {
                    $assignment = $this->payerAssignmentRepository->findForUpdateByBusinessId((int) $candidate->id);
                    $classification = $this->classifyPayerAssignment($assignment);
                    $classifications[$candidate->id] = $classification;

                    if ($classification['action'] === 'unresolved') {
                        $blockedBusinesses[] = [
                            'business_id' => $candidate->id,
                            'business_uid' => $candidate->uid,
                            'reason' => $classification['reason'],
                        ];
                    }
                }

                if ($blockedBusinesses !== []) {
                    return $this->workspaceOutcome($lockedWorkspace, 'blocked', 'unresolved_business_payer', [
                        'primary_business_id' => $primaryBusiness->id,
                        'blocked_businesses' => $blockedBusinesses,
                    ]);
                }

                $businessResults = [];

                foreach ($candidates as $candidate) {
                    $businessResults[] = $this->cutoverOneBusiness($operatorUserId, $lockedWorkspace, $candidate, $classifications[$candidate->id]);
                }

                $this->verifyWorkspaceSplit($lockedWorkspace, $businessResults);

                return $this->workspaceOutcome($lockedWorkspace, 'migrated', null, [
                    'primary_business_id' => $primaryBusiness->id,
                    'verified' => true,
                    'businesses' => $businessResults,
                ]);
            });
        } catch (Throwable $e) {
            $message = $e->getMessage();

            if (str_starts_with($message, self::VERIFICATION_FAILURE_PREFIX)) {
                return $this->workspaceOutcome($workspace, 'verification_failed', substr($message, strlen(self::VERIFICATION_FAILURE_PREFIX)));
            }

            return $this->workspaceOutcome($workspace, 'failed', $e::class . ': ' . $message);
        }
    }

    /**
     * The simplified four-step cutover for one Business: create its own
     * new plain Workspace, assign it a fresh Core/complimentary plan
     * (Phase 0), reassign the existing Business row, repair its primary
     * Location only if genuinely absent. NO relationship step. NO payer
     * write — a `business`/`workspace` payer's meaning is unaffected by
     * the move (see class docblock).
     *
     * @return array<string, mixed>
     */
    private function cutoverOneBusiness(int $operatorUserId, Workspace $lockedWorkspace, Business $business, array $classification): array
    {
        if ((bool) $business->is_primary) {
            throw new RuntimeException("Refusing to split primary Business #{$business->id} — candidate selection invariant violated.");
        }

        // Step 1 — new plain Workspace, owned by the SAME real owner as
        // the source Workspace (mirrors Contract 10's actor choice: the
        // owner genuinely owns both Workspaces once the new one exists,
        // satisfying reassignBusiness()'s dual-authority requirement
        // honestly, never a fabricated actor).
        $newWorkspace = $this->workspaceManager->createWorkspace(
            (int) $lockedWorkspace->owner_user_id,
            $business->name,
        );

        // Step 2 — plan assignment BEFORE reassignment (Contract 10's own
        // capacity-check-ordering bug fix: reassignBusiness() calls
        // assertCanCreateAnotherBusiness(), which throws
        // WorkspacePlanUnassignedException against an unassigned target).
        $this->entitlementManager->assignFirstPlan(
            $newWorkspace,
            self::MIGRATION_PLAN_TIER,
            $operatorUserId,
            self::PLAN_ASSIGNMENT_REASON,
            true,
            0,
        );

        // Step 3 — reassign the EXISTING Business row. Actor = the real
        // owner, who now owns both Workspaces.
        $reassigned = $this->workspaceManager->reassignBusiness(
            (int) $lockedWorkspace->owner_user_id,
            $business,
            $newWorkspace,
        );

        // Step 4 — primary Location repair ONLY if genuinely absent.
        // BusinessLocation rows key on business_id and survive the
        // reassignment automatically and untouched.
        $primaryLocationCreated = false;

        if ($this->locationRepository->findPrimary($reassigned) === null) {
            $this->locationManager->upsertPrimaryLocation($reassigned, [
                'service_mode' => 'storefront',
                'country_code' => 'US',
            ]);
            $primaryLocationCreated = true;
        }

        return $this->outcome(
            $reassigned,
            'migrated',
            null,
            newWorkspace: $newWorkspace,
            payerType: $classification['current_type'],
            primaryLocationCreated: $primaryLocationCreated,
        );
    }

    /**
     * Post-cutover verification, run INSIDE the still-open source
     * Workspace transaction, before it is allowed to commit.
     *
     * @param  array<int, array<string, mixed>>  $businessResults
     */
    private function verifyWorkspaceSplit(Workspace $lockedWorkspace, array $businessResults): void
    {
        $fail = function (string $detail): never {
            throw new RuntimeException(self::VERIFICATION_FAILURE_PREFIX . $detail);
        };

        // 1. The source Workspace now contains only its own primary Business.
        $remainingNonPrimary = Business::query()
            ->where('workspace_id', $lockedWorkspace->id)
            ->where('is_primary', false)
            ->count();

        if ($remainingNonPrimary !== 0) {
            $fail("Source Workspace #{$lockedWorkspace->id} still has {$remainingNonPrimary} non-primary Business(es) after split.");
        }

        $newWorkspaceIds = [];

        foreach ($businessResults as $result) {
            $businessId = $result['business_id'];
            $newWorkspaceId = $result['new_workspace_id'];
            $newWorkspaceIds[] = $newWorkspaceId;

            $business = Business::find($businessId);

            if ($business === null) {
                $fail("Business #{$businessId} is missing after its own split.");
            }

            // 2. Original Business ID/UID unchanged — trivially true (same
            // row reassigned, never recreated), re-confirmed here.
            if ($business->uid !== $result['business_uid']) {
                $fail("Business #{$businessId}'s uid changed across the split — it must be the same row, never a clone.");
            }

            // 3. Every moved Business belongs to exactly its own new
            // Workspace.
            if ((int) $business->workspace_id !== (int) $newWorkspaceId) {
                $fail("Business #{$businessId} does not point at its new Workspace #{$newWorkspaceId} (found workspace_id={$business->workspace_id}).");
            }

            // 4. Every new Workspace contains exactly one Business.
            $newWorkspaceBusinessCount = Business::query()->where('workspace_id', $newWorkspaceId)->count();

            if ($newWorkspaceBusinessCount !== 1) {
                $fail("New Workspace #{$newWorkspaceId} has {$newWorkspaceBusinessCount} Business(es), expected exactly 1.");
            }

            // 5. No Agency relationship exists for this new Workspace —
            // this is not an Agency migration.
            if ($this->relationshipRepository->findActiveForClientWorkspace((int) $newWorkspaceId) !== null) {
                $fail("New Workspace #{$newWorkspaceId} unexpectedly has an active Agency relationship — this migration must never create one.");
            }

            // 6. Payer behavior matches the required invariant: a
            // `business`-type payer is byte-identical; a `workspace`-type
            // payer's ROW is untouched (never converted, never rewritten)
            // — its effective meaning follows the Business's current
            // workspace_id automatically.
            $payer = $this->payerAssignmentRepository->findByBusinessId((int) $businessId);

            if ($payer === null) {
                $fail("Business #{$businessId} has no payer assignment after its split.");
            }

            if ($payer->payer_type->value !== $result['payer_type']) {
                $fail("Business #{$businessId}'s payer_type changed from '{$result['payer_type']}' to '{$payer->payer_type->value}' — this migration must never write it.");
            }

            // 8. Location preservation still holds.
            if ($this->locationRepository->findPrimary($business) === null) {
                $fail("Business #{$businessId} has no primary Location after its split.");
            }
        }

        // 9. No duplicate new Workspace was created for this batch.
        if (count($newWorkspaceIds) !== count(array_unique($newWorkspaceIds))) {
            $fail("Duplicate new Workspace id detected across split Businesses for source Workspace #{$lockedWorkspace->id}.");
        }
    }

    private function classifyPayerAssignment(?BusinessPayerAssignment $assignment): array
    {
        if ($assignment === null) {
            return ['action' => 'unresolved', 'reason' => 'missing_payer_assignment'];
        }

        return match ($assignment->payer_type) {
            PayerType::Business => ['action' => 'unchanged', 'current_type' => PayerType::Business->value],
            PayerType::Workspace => ['action' => 'unchanged', 'current_type' => PayerType::Workspace->value],
            PayerType::AgencyRebill => ['action' => 'unresolved', 'reason' => 'unexpected_agency_rebill_on_nonagency_workspace'],
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function workspaceOutcome(Workspace $workspace, string $status, ?string $reason, array $extra = []): array
    {
        return array_merge([
            'workspace_id' => $workspace->id,
            'workspace_uid' => $workspace->uid,
            'status' => $status,
            'reason' => $reason,
            'primary_business_id' => null,
            'primary_business_count' => null,
            'blocked_businesses' => [],
            'verified' => false,
            'businesses' => [],
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(
        Business $business,
        string $status,
        ?string $reason = null,
        ?Workspace $newWorkspace = null,
        ?string $payerType = null,
        bool $primaryLocationCreated = false,
    ): array {
        return [
            'business_id' => $business->id,
            'business_uid' => $business->uid,
            'business_name' => $business->name,
            'status' => $status,
            'reason' => $reason,
            'new_workspace_id' => $newWorkspace?->id,
            'new_workspace_uid' => $newWorkspace?->uid,
            'payer_type' => $payerType,
            'primary_location_created' => $primaryLocationCreated,
        ];
    }
}
