<?php

declare(strict_types=1);

namespace App\Library\Workspace\Migration;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Business\BusinessLocationManager;
use App\Library\Entitlement\EntitlementManager;
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
use RuntimeException;
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
 * §10's blanket "all [reused events] with the real migration operator as
 * actor" cannot be satisfied for WorkspaceCreated/BusinessReassignedToWorkspace/
 * WorkspaceMembershipBusinessUnassigned without either weakening
 * WorkspaceManager's dual-authority check or fabricating an Agency-owner
 * action the owner did not take — mechanically confirmed by reading
 * createWorkspace()/reassignBusiness(): each takes exactly one actor-shaped
 * parameter, used both as the authority-check subject AND as the actor
 * recorded on every event/transition row it writes, with no separate
 * "audit actor" parameter. §10 has been corrected accordingly (see the
 * doc's own §10 note) — the genuine, current-main-verified actor split is:
 * Workspace creation/reassignment record the real, standing-authority
 * Agency owner (never fabricated); the Contract 01 relationship alone
 * records the real platform migration operator, via createForMigration().
 *
 * ATOMICITY / CONCURRENCY (§7, corrected). The Agency Workspace is the
 * true atomic/batch unit, matching the Roadmap's own "SERIAL ONLY, one
 * Agency at a time, verification between batches" framing: ONE outer
 * transaction per Agency Workspace. The Agency Workspace row is locked
 * FIRST (`findForUpdate`); its primary Business and every non-primary
 * candidate Business are then re-read and row-locked under that same
 * transaction; every candidate's payer assignment is locked and classified
 * BEFORE any mutation — if any candidate is unresolved, the whole Agency
 * performs ZERO writes and reports 'blocked'. Only once every candidate is
 * known-good does the six-step cutover run sequentially for each one,
 * still inside the same transaction, followed by an in-transaction
 * post-cutover verification pass (§8.4) that THROWS (rolling back the
 * entire Agency's mutations, siblings included) if any invariant fails.
 * A second concurrent attempt at the SAME Agency blocks on the Agency row
 * lock, then — once it acquires the lock — re-reads candidates fresh and
 * finds none remaining (having lost the race) or only a genuine remainder
 * (a distinct Agency Workspace, or a truly interrupted prior attempt that
 * never committed), never a duplicate of anything the winner committed.
 *
 * RESUMABILITY (§7). No bespoke tracking column: a migrated Business's
 * `workspace_id` is simply no longer the original Agency Workspace's id,
 * so it silently drops out of the very query that selects candidates for
 * that Agency's transaction on the next invocation — confirmed sufficient
 * by §3's full entity inventory (no table needs its own migration-tracking
 * column). Because the whole Agency is one transaction, there is no
 * "half-migrated Agency" residue from a failed attempt to resume from: a
 * failed attempt commits nothing, so a rerun reprocesses the Agency from
 * its authoritative state, which is exactly where a genuinely
 * already-partly-migrated Agency (whatever its history) is found and its
 * true remainder is what gets migrated.
 */
final class AgencyBusinessMigrationV1
{
    private const MIGRATION_PLAN_TIER = WorkspacePlanTier::Core;

    private const PLAN_ASSIGNMENT_REASON = 'Implementation Contract 10 — Agency data migration.';

    /**
     * Marks a verification-failure RuntimeException distinctly from any
     * other failure, so the outer catch can report 'verification_failed'
     * (a decisive, always-blocking outcome per §8.4) rather than the
     * generic 'failed'.
     */
    private const VERIFICATION_FAILURE_PREFIX = 'CONTRACT10_VERIFICATION_FAILED: ';

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
     * methods at all, and predicts the exact same whole-Agency blocking
     * decision executeAgency() would make.
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
     * @return Collection<int, Workspace>
     */
    private function candidateAgencyWorkspaces(?array $agencyWorkspaceIds): Collection
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

        return Workspace::query()
            ->whereIn('id', $candidateIds->values())
            ->orderBy('id')
            ->get();
    }

    /**
     * Read-only report for one Agency (§8.1/§8.2) — predicts exactly what
     * executeAgency() would do: if ANY candidate Business is unresolved,
     * the whole Agency is reported 'blocked' (never "ready with one bad
     * apple"), since the corrected execution boundary would refuse to
     * write anything for this Agency at all.
     *
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
                'business_count' => Business::query()->where('workspace_id', $agency->id)->count(),
                'candidate_business_count' => null,
                'primary_location_missing_count' => null,
                'businesses' => [],
            ];
        }

        $candidates = Business::query()
            ->where('workspace_id', $agency->id)
            ->where('is_primary', false)
            ->orderBy('id')
            ->get();

        $businessReports = $candidates->map(fn (Business $business) => $this->reportForBusiness($business))->values()->all();

        $blockedBusinesses = collect($businessReports)->where('blocking', true)->values()->all();
        $primaryLocationMissingCount = collect($businessReports)->where('primary_location_present', false)->count();

        $status = $blockedBusinesses !== []
            ? 'blocked'
            : ($candidates->isEmpty() ? 'no_action' : 'ready');

        return [
            'agency_workspace_id' => $agency->id,
            'agency_workspace_uid' => $agency->uid,
            'status' => $status,
            'reason' => $blockedBusinesses !== [] ? 'unresolved_business_payer' : null,
            'primary_business_id' => $primaries->first()->id,
            'business_count' => $candidates->count() + 1,
            'candidate_business_count' => $candidates->count(),
            'primary_location_missing_count' => $primaryLocationMissingCount,
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
     * that is not one of the three named cases is 'unresolved'.
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
     * The corrected per-Agency atomic execution (§7/§8, corrected). ONE
     * outer transaction: lock the Agency Workspace, re-read and lock its
     * primary and every candidate Business plus their payer assignments,
     * pre-validate the ENTIRE batch before any mutation, then run the
     * six-step cutover sequentially for every candidate, then verify
     * before allowing the transaction to commit. Any failure anywhere —
     * pre-validation, cutover, or verification — leaves this Agency with
     * ZERO durable changes from this attempt.
     *
     * @return array<string, mixed>
     */
    private function executeAgency(int $operatorUserId, Workspace $agency): array
    {
        try {
            return DB::transaction(function () use ($operatorUserId, $agency) {
                $lockedAgency = $this->workspaceRepository->findForUpdate((int) $agency->id);

                if ($lockedAgency === null) {
                    throw new WorkspaceNotFoundException((int) $agency->id);
                }

                $primaries = Business::query()
                    ->where('workspace_id', $lockedAgency->id)
                    ->where('is_primary', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($primaries->count() !== 1) {
                    return $this->agencyOutcome($lockedAgency, 'blocked', 'ambiguous_primary_business', [
                        'primary_business_count' => $primaries->count(),
                    ]);
                }

                $primaryBusiness = $primaries->first();

                // Re-selected fresh, under lock, every invocation — this is
                // what makes resumability and concurrency-safety work: a
                // sibling Business a prior attempt already committed is
                // simply no longer in this result set at all.
                $candidates = Business::query()
                    ->where('workspace_id', $lockedAgency->id)
                    ->where('is_primary', false)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($candidates->isEmpty()) {
                    return $this->agencyOutcome($lockedAgency, 'no_action', null, [
                        'primary_business_id' => $primaryBusiness->id,
                    ]);
                }

                // Pre-validate the WHOLE batch before any mutation (Finding
                // 2): lock and classify every candidate's payer assignment
                // first. One unresolved candidate blocks the entire Agency
                // — never migrate the "good" siblings around a bad one.
                $lockedPayerAssignments = [];
                $classifications = [];
                $blockedBusinesses = [];

                foreach ($candidates as $candidate) {
                    $assignment = $this->payerAssignmentRepository->findForUpdateByBusinessId((int) $candidate->id);
                    $classification = $this->classifyPayerAssignment($assignment);

                    $lockedPayerAssignments[$candidate->id] = $assignment;
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
                    return $this->agencyOutcome($lockedAgency, 'blocked', 'unresolved_business_payer', [
                        'primary_business_id' => $primaryBusiness->id,
                        'business_count' => $candidates->count() + 1,
                        'candidate_business_count' => $candidates->count(),
                        'blocked_businesses' => $blockedBusinesses,
                    ]);
                }

                // Every candidate is known-good — execute the six-step
                // cutover for each, sequentially, still inside this one
                // Agency transaction.
                $businessResults = [];

                foreach ($candidates as $candidate) {
                    $businessResults[] = $this->cutoverOneBusiness(
                        $operatorUserId,
                        $lockedAgency,
                        $candidate,
                        $lockedPayerAssignments[$candidate->id],
                        $classifications[$candidate->id],
                    );
                }

                // Post-cutover verification (§8.4) — MUST pass before this
                // transaction is allowed to commit. A failure here throws,
                // rolling back every mutation this Agency's attempt made,
                // siblings included.
                $this->verifyAgencyCutover($lockedAgency, $businessResults);

                return $this->agencyOutcome($lockedAgency, 'migrated', null, [
                    'primary_business_id' => $primaryBusiness->id,
                    'business_count' => $candidates->count() + 1,
                    'candidate_business_count' => $candidates->count(),
                    'verified' => true,
                    'businesses' => $businessResults,
                ]);
            });
        } catch (Throwable $e) {
            $message = $e->getMessage();

            if (str_starts_with($message, self::VERIFICATION_FAILURE_PREFIX)) {
                return $this->agencyOutcome($agency, 'verification_failed', substr($message, strlen(self::VERIFICATION_FAILURE_PREFIX)));
            }

            return $this->agencyOutcome($agency, 'failed', $e::class . ': ' . $message);
        }
    }

    /**
     * One Business's six-step cutover (§4). Deliberately has NO try/catch
     * of its own — any Throwable propagates out of executeAgency()'s outer
     * DB::transaction() closure, rolling back the ENTIRE Agency attempt,
     * including any sibling Business already cutover earlier in this same
     * loop. That is the whole point of the corrected per-Agency boundary.
     *
     * @return array<string, mixed>
     */
    private function cutoverOneBusiness(
        int $operatorUserId,
        Workspace $lockedAgency,
        Business $business,
        ?BusinessPayerAssignment $lockedPayerAssignment,
        array $classification,
    ): array {
        if ((bool) $business->is_primary) {
            // Defense in depth: the candidate query already excludes
            // primaries, so this should be unreachable. Reaching it means a
            // real invariant violation — throw (failing the whole Agency)
            // rather than silently skip and report false success.
            throw new RuntimeException("Refusing to migrate primary Business #{$business->id} — candidate selection invariant violated.");
        }

        // Step 1 — Contract 07's own createWorkspace(), unchanged. Owner =
        // the SAME real owner as the Agency Workspace (see class docblock:
        // no separate real client identity exists for a legacy
        // multi-Business Agency Workspace).
        $clientWorkspace = $this->workspaceManager->createWorkspace(
            (int) $lockedAgency->owner_user_id,
            $business->name,
        );

        // Step 2 — plan assignment BEFORE reassignment (bug fix 1, §4): a
        // bare createWorkspace() has no plan, and reassignBusiness()'s own
        // capacity check would otherwise throw WorkspacePlanUnassignedException
        // every time.
        $this->entitlementManager->assignFirstPlan(
            $clientWorkspace,
            self::MIGRATION_PLAN_TIER,
            $operatorUserId,
            self::PLAN_ASSIGNMENT_REASON,
            true,
            0,
        );

        // Step 3 — reassign the EXISTING Business row. Actor = the real
        // owner, who now owns both Workspaces (never the operator, who
        // owns neither) — see class docblock.
        $reassigned = $this->workspaceManager->reassignBusiness(
            (int) $lockedAgency->owner_user_id,
            $business,
            $clientWorkspace,
        );

        // Step 4 — the Contract 01 relationship, immediately after the
        // move (bug fix 2, §4): the payer decision (step 6) needs this
        // relationship's id to exist. Actor = the real human operator (§6)
        // — never a fabricated Agency action.
        $relationship = $this->relationshipManager->createForMigration(
            $operatorUserId,
            $lockedAgency,
            $clientWorkspace,
        );

        // Step 5 — primary Location repair ONLY if genuinely absent (§3).
        // BusinessLocation rows key on business_id and survived the
        // reassignment automatically and untouched; this never runs for a
        // Business that already has one.
        $primaryLocationCreated = false;

        if ($this->locationRepository->findPrimary($reassigned) === null) {
            $this->locationManager->upsertPrimaryLocation($reassigned, [
                'service_mode' => 'storefront',
                'country_code' => 'US',
            ]);
            $primaryLocationCreated = true;
        }

        // Step 6 — the payer matrix (§5), only now that the relationship
        // exists. 'business' rows are untouched (self-pay, business_id FK
        // unaffected by the Workspace move); 'workspace' rows convert to a
        // pre-consent agency_rebill row — the timestamp/actor consent
        // columns are deliberately NEVER written here, so no charge can
        // proceed until the real Agency owner consents via Contract 09's
        // own flow.
        if ($classification['action'] === 'convert_to_pending_agency_rebill') {
            $this->payerAssignmentRepository->update($lockedPayerAssignment, [
                'payer_type' => PayerType::AgencyRebill,
                'managing_agency_relationship_id' => $relationship->id,
            ]);
        }

        return $this->outcome(
            $reassigned,
            'migrated',
            null,
            clientWorkspace: $clientWorkspace,
            relationship: $relationship,
            payerAction: $classification['action'],
            primaryLocationCreated: $primaryLocationCreated,
        );
    }

    /**
     * §8.4's post-cutover verification, run INSIDE the still-open Agency
     * transaction, before it is allowed to commit. Any violation throws a
     * RuntimeException tagged with VERIFICATION_FAILURE_PREFIX, which
     * executeAgency()'s outer catch turns into a 'verification_failed'
     * outcome after the whole transaction has rolled back — never a
     * warning printed after bad state was already committed.
     *
     * @param  array<int, array<string, mixed>>  $businessResults
     */
    private function verifyAgencyCutover(Workspace $lockedAgency, array $businessResults): void
    {
        $fail = function (string $detail): never {
            throw new RuntimeException(self::VERIFICATION_FAILURE_PREFIX . $detail);
        };

        // 1. The Agency Workspace now contains only its own primary
        // Business — no remaining candidate client Businesses.
        $remainingNonPrimary = Business::query()
            ->where('workspace_id', $lockedAgency->id)
            ->where('is_primary', false)
            ->count();

        if ($remainingNonPrimary !== 0) {
            $fail("Agency Workspace #{$lockedAgency->id} still has {$remainingNonPrimary} non-primary Business(es) after cutover.");
        }

        $clientWorkspaceIds = [];

        foreach ($businessResults as $result) {
            $businessId = $result['business_id'];
            $clientWorkspaceId = $result['client_workspace_id'];
            $clientWorkspaceIds[] = $clientWorkspaceId;

            // 2. Business ID preserved — trivially true (same row was
            // reassigned, never recreated), re-confirmed by successfully
            // finding it below under its original id.
            $business = Business::find($businessId);

            if ($business === null) {
                $fail("Business #{$businessId} is missing after its own migration.");
            }

            // 3. Every migrated Business points at its expected new
            // Client Workspace.
            if ((int) $business->workspace_id !== (int) $clientWorkspaceId) {
                $fail("Business #{$businessId} does not point at its migrated Client Workspace #{$clientWorkspaceId} (found workspace_id=" . $business->workspace_id . ').');
            }

            // 4. Exactly one correct, active Agency->Client relationship.
            $relationship = $this->relationshipRepository->findActiveForClientWorkspace((int) $clientWorkspaceId);

            if ($relationship === null || (int) $relationship->agency_workspace_id !== (int) $lockedAgency->id) {
                $fail("Client Workspace #{$clientWorkspaceId} does not have exactly one active relationship to Agency Workspace #{$lockedAgency->id}.");
            }

            // 5/6/7. Payer state: business unchanged, or workspace
            // correctly converted to a NEVER-fabricated-consent
            // agency_rebill — and never left as a stale workspace payer.
            $payer = $this->payerAssignmentRepository->findByBusinessId((int) $businessId);

            if ($payer === null) {
                $fail("Business #{$businessId} has no payer assignment after migration.");
            }

            if ($result['payer_action'] === 'unchanged' && $payer->payer_type !== PayerType::Business) {
                $fail("Business #{$businessId}'s payer_type should remain 'business' but is '{$payer->payer_type->value}'.");
            }

            if ($result['payer_action'] === 'convert_to_pending_agency_rebill') {
                if ($payer->payer_type !== PayerType::AgencyRebill) {
                    $fail("Business #{$businessId}'s legacy workspace payer was not converted to agency_rebill.");
                }

                if ((int) $payer->managing_agency_relationship_id !== (int) $relationship->id) {
                    $fail("Business #{$businessId}'s agency_rebill payer is not linked to its own migration relationship.");
                }

                if ($payer->agency_rebill_consented_at !== null || $payer->agency_rebill_consented_by_user_id !== null) {
                    $fail("Business #{$businessId}'s agency_rebill consent was fabricated — it must remain NULL until the real Agency owner consents.");
                }
            }

            if ($payer->payer_type === PayerType::Workspace) {
                $fail("Business #{$businessId} was left with a stale 'workspace' payer_type after leaving its Agency Workspace.");
            }

            // 8. Location preservation still holds — a primary Location
            // exists (either the original, untouched, or the one this
            // migration itself repaired).
            if ($this->locationRepository->findPrimary($business) === null) {
                $fail("Business #{$businessId} has no primary Location after migration.");
            }
        }

        // 9. No duplicate Client Workspace/relationship was created for
        // this Agency's batch.
        if (count($clientWorkspaceIds) !== count(array_unique($clientWorkspaceIds))) {
            $fail("Duplicate Client Workspace id detected across migrated Businesses for Agency #{$lockedAgency->id}.");
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function agencyOutcome(Workspace $agency, string $status, ?string $reason, array $extra = []): array
    {
        return array_merge([
            'agency_workspace_id' => $agency->id,
            'agency_workspace_uid' => $agency->uid,
            'status' => $status,
            'reason' => $reason,
            'primary_business_id' => null,
            'primary_business_count' => null,
            'business_count' => null,
            'candidate_business_count' => null,
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
        ?Workspace $clientWorkspace = null,
        ?AgencyClientWorkspaceRelationship $relationship = null,
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
