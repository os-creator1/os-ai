<?php

namespace App\Library\NicheBlueprint;

use App\Enums\Business\BusinessIndustry;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\Entitlement\EntitlementManager;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\BusinessKnowledgeProfile;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contract 20 §6.3/§6.4/§7.1/§7.2/§7.4, Sub-slice C — the installation engine.
 *
 * INITIAL PROVISIONING ONLY. This class is reached from exactly two events
 * (§9.1: `BusinessCreated` and the FIRST `WorkspacePlanAssigned`) and from the
 * operator recovery command. It is deliberately NOT reached from
 * `WorkspacePlanChanged`, from a plan upgrade or downgrade, from publishing a
 * new Blueprint version, or from a `PlatformFeature` flipping to `Available` —
 * each of those may make a component ELIGIBLE for the owner's later explicit
 * add (§8.1), and none of them installs anything. Even if this class were
 * called on one of those occasions, §7.2's re-read short-circuit means a
 * component already recorded `installed`, `skipped_unentitled` or
 * `skipped_unavailable` is skipped entirely — an automated run never reverses
 * a skip, so an upgrade could not install through it regardless.
 *
 * ONE ENTITLEMENT AUTHORITY. `EntitlementManager::decide()` decides every
 * component, without exception: `required_feature_key` is NOT NULL and was
 * validated known and Business-scoped at publish (§6.2), so there is no
 * "ungated component" branch to write. This class NEVER calls
 * `PlatformFeatureRegistry` itself — `decide()` already applies the
 * availability floor before any plan read (§3.6), and a second check here
 * would be exactly the driftable duplicate authority the entitlement domain's
 * own docblocks warn against. The availability distinction this slice needs is
 * carried by the decision's own `reason` (§6.3's table), not by a second read.
 *
 * ONE TRANSACTION PER COMPONENT, NEVER ONE FOR THE RUN (§7.2). A ten-component
 * install whose seventh component fails must keep the first six; one enclosing
 * transaction would discard them and leave a Business with a Blueprint it was
 * told it received. The UNIQUE `(business_id, blueprint_id, component_key)`
 * key makes the resulting partial state safe to resume (§7.4).
 *
 * SYSTEM INSTALLS HAVE NO ACTOR (§6.4). `installed_by_user_id` is written
 * NULL, following the null-actor precedent. `decide()` requires an
 * `int $actorUserId` by signature, so the Workspace owner's real id is passed
 * — §3.6 proves that argument is decision-neutral (no precedence step reads
 * it), so this is a signature requirement satisfied honestly rather than a
 * fabricated system-actor id.
 */
class NicheBlueprintInstaller
{
    /** `business_blueprint_component_installations.error_code` is string(64). */
    private const MAX_ERROR_CODE = 64;

    /**
     * What one component's processing did. Deliberately richer than the four
     * persisted states, because two outcomes are precisely the ones that
     * persist NOTHING: a component another run already decided, and a
     * component whose entitlement decision could not be made at all.
     */
    private const OUTCOME_INSTALLED = 'installed';

    private const OUTCOME_SKIPPED_UNENTITLED = 'skipped_unentitled';

    private const OUTCOME_SKIPPED_UNAVAILABLE = 'skipped_unavailable';

    private const OUTCOME_FAILED = 'failed';

    private const OUTCOME_ALREADY_DECIDED = 'already_decided';

    private const OUTCOME_UNDECIDED = 'undecided';

    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly BlueprintComponentAdapterRegistry $adapters,
        private readonly WorkspacePlanAssignmentRepository $planAssignments,
    ) {
    }

    /**
     * The one idempotent entry point both §9.1 triggers and §9.2's command use.
     *
     * Re-running is always safe and is the documented recovery path: it retries
     * components with no record and components recorded `failed`, and touches
     * nothing else.
     */
    public function installForBusiness(Business $business): BlueprintInstallationRunResult
    {
        $workspace = $business->workspace()->first();

        if (! $workspace instanceof Workspace) {
            return BlueprintInstallationRunResult::aborted(BlueprintInstallationRunResult::ABORT_WORKSPACE_UNRESOLVED);
        }

        $ownerUserId = (int) ($workspace->owner_user_id ?? 0);

        if ($ownerUserId < 1) {
            return BlueprintInstallationRunResult::aborted(BlueprintInstallationRunResult::ABORT_WORKSPACE_OWNER_UNRESOLVED);
        }

        // §9.1's precondition, and the reason it is a precondition rather than
        // a per-component outcome: a skip record is never revisited by a later
        // automated run (§7.2), so recording every component as
        // `skipped_unentitled` because a plan landed a moment late would
        // permanently deny this Business its own initial installation. No plan
        // assignment is a precondition failure, not an entitlement decision —
        // so nothing at all is written, and the `WorkspacePlanAssigned`
        // trigger retries the whole run the moment the first assignment
        // arrives.
        if ($this->planAssignments->findByWorkspaceId((int) $workspace->id) === null) {
            return BlueprintInstallationRunResult::aborted(BlueprintInstallationRunResult::ABORT_WORKSPACE_PLAN_UNASSIGNED);
        }

        // §8.1/§13.4 — A BUSINESS IS PINNED TO WHAT IT WAS PROVISIONED WITH.
        //
        // Resolution (§7.1) answers "which Blueprint does a NEW Business
        // belong to", and it reads live, mutable Business state. Re-asking it
        // for an already-provisioned Business would be a silent-install
        // engine in two distinct ways, both of which fire on the ordinary
        // recovery re-run §9.2 tells operators to perform:
        //
        //  - a LATER PUBLISHED VERSION adds a component this Business has
        //    never seen; it has no record, so nothing would short-circuit it
        //    and the platform's own publish would install into a live
        //    Business (§13.4 requires it to be SURFACED, not installed);
        //  - RESOLUTION DRIFTS (a knowledge profile gains a `vertical_key`
        //    after the broad-industry fallback already provisioned it), and a
        //    whole second Blueprint, having no records of its own, installs
        //    wholesale.
        //
        // So an already-provisioned Business is served entirely from its own
        // recorded identity: the Blueprint it holds records for, at the
        // version those records cite. That is what makes a re-run a genuine
        // RESUME of the installation it already has rather than an upgrade to
        // whatever the platform published since — "COPY, NEVER LINK" applied
        // to the run itself.
        $prior = $this->priorProvisioningOf($business);

        if ($prior === null) {
            $resolution = $this->resolveBlueprint($business);

            if ($resolution instanceof BlueprintInstallationRunResult) {
                return $resolution;
            }

            $version = $this->publishedVersionOf($resolution);

            if ($version === null) {
                return BlueprintInstallationRunResult::aborted(
                    BlueprintInstallationRunResult::ABORT_NO_PUBLISHED_VERSION,
                    (int) $resolution->id,
                );
            }

            return $this->runComponents($business, $workspace, $ownerUserId, $resolution, $version);
        }

        ['blueprintId' => $pinnedBlueprintId, 'versionNumber' => $pinnedVersionNumber, 'ambiguous' => $ambiguous] = $prior;

        if ($ambiguous) {
            return BlueprintInstallationRunResult::aborted(
                BlueprintInstallationRunResult::ABORT_BLUEPRINT_IDENTITY_AMBIGUOUS,
                $pinnedBlueprintId,
            );
        }

        $blueprint = NicheBlueprint::query()->whereKey($pinnedBlueprintId)->first();

        if ($blueprint === null) {
            return BlueprintInstallationRunResult::aborted(
                BlueprintInstallationRunResult::ABORT_BLUEPRINT_IDENTITY_AMBIGUOUS,
                $pinnedBlueprintId,
            );
        }

        // Deliberately NOT filtered by `is_active`: deactivating a Blueprint
        // stops it resolving for NEW Businesses (§7.1), and must not strand an
        // already-provisioned Business's `failed` component (§7.4) with no
        // recovery path.
        $version = NicheBlueprintVersion::query()
            ->where('blueprint_id', $pinnedBlueprintId)
            ->where('version_number', $pinnedVersionNumber)
            ->first();

        if ($version === null) {
            return BlueprintInstallationRunResult::aborted(
                BlueprintInstallationRunResult::ABORT_PROVISIONED_VERSION_MISSING,
                $pinnedBlueprintId,
            );
        }

        return $this->runComponents($business, $workspace, $ownerUserId, $blueprint, $version);
    }

    /**
     * The Blueprint identity and version this Business was already
     * provisioned with, or null when it has never been provisioned at all.
     *
     * A Business holding records for more than one Blueprint has an ambiguous
     * identity — impossible to produce through this class, but possible in
     * legacy or hand-edited data — and is reported as such so the run fails
     * closed rather than picking one.
     *
     * @return array{blueprintId: int, versionNumber: int, ambiguous: bool}|null
     */
    private function priorProvisioningOf(Business $business): ?array
    {
        $records = BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->get(['blueprint_id', 'installed_from_version']);

        if ($records->isEmpty()) {
            return null;
        }

        $blueprintIds = $records->pluck('blueprint_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values();

        return [
            'blueprintId' => (int) $blueprintIds->first(),
            'versionNumber' => (int) $records->max('installed_from_version'),
            'ambiguous' => $blueprintIds->count() > 1,
        ];
    }

    /**
     * §7.1 — read-only resolution, before any write. First match wins.
     *
     * 1. the Business's `business_knowledge_profiles.vertical_key`;
     * 2. the broad-industry fallback (`vertical_key IS NULL`) — and if more
     *    than one matches, resolution FAILS CLOSED and installs nothing rather
     *    than picking one. `broad_industry` is deliberately not UNIQUE (a
     *    vertical-bound Blueprint and a broad fallback may legitimately share
     *    an industry), so this is a real, reachable state, not a theoretical
     *    one;
     * 3. no match at all — no installation, no records, and not an error.
     *
     * @return NicheBlueprint|BlueprintInstallationRunResult the Blueprint, or an aborted result
     */
    public function resolveBlueprint(Business $business): NicheBlueprint|BlueprintInstallationRunResult
    {
        $verticalKey = BusinessKnowledgeProfile::query()
            ->where('business_id', $business->id)
            ->value('vertical_key');

        if (is_string($verticalKey) && trim($verticalKey) !== '') {
            $byVertical = NicheBlueprint::query()
                ->active()
                ->where('vertical_key', $verticalKey)
                ->first();

            if ($byVertical !== null) {
                return $byVertical;
            }
        }

        $industry = $business->industry;
        $industryValue = $industry instanceof BusinessIndustry
            ? $industry->value
            : (is_string($industry) && trim($industry) !== '' ? $industry : null);

        if ($industryValue === null) {
            return BlueprintInstallationRunResult::aborted(BlueprintInstallationRunResult::ABORT_NO_BLUEPRINT);
        }

        $broadMatches = NicheBlueprint::query()
            ->active()
            ->where('broad_industry', $industryValue)
            ->whereNull('vertical_key')
            ->orderBy('id')
            ->get();

        if ($broadMatches->count() > 1) {
            return BlueprintInstallationRunResult::aborted(BlueprintInstallationRunResult::ABORT_AMBIGUOUS_BROAD_INDUSTRY);
        }

        $broad = $broadMatches->first();

        return $broad ?? BlueprintInstallationRunResult::aborted(BlueprintInstallationRunResult::ABORT_NO_BLUEPRINT);
    }

    /**
     * The Blueprint's currently `published` version, or null when it has never
     * been published. At most one can exist — the `published_guard` generated
     * column's UNIQUE index enforces that at the database layer (§5.2).
     */
    private function publishedVersionOf(NicheBlueprint $blueprint): ?NicheBlueprintVersion
    {
        return NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Published->value)
            ->first();
    }

    private function runComponents(
        Business $business,
        Workspace $workspace,
        int $ownerUserId,
        NicheBlueprint $blueprint,
        NicheBlueprintVersion $version,
    ): BlueprintInstallationRunResult {
        $counts = [
            self::OUTCOME_INSTALLED => 0,
            self::OUTCOME_SKIPPED_UNENTITLED => 0,
            self::OUTCOME_SKIPPED_UNAVAILABLE => 0,
            self::OUTCOME_FAILED => 0,
            self::OUTCOME_ALREADY_DECIDED => 0,
            self::OUTCOME_UNDECIDED => 0,
        ];

        // Ordered by `position` then `id` on the relation itself (§5.3).
        foreach ($version->components as $component) {
            $outcome = $this->processComponent($business, $workspace, $ownerUserId, $blueprint, $version, $component);

            $counts[$outcome]++;
        }

        return new BlueprintInstallationRunResult(
            blueprintId: (int) $blueprint->id,
            versionNumber: (int) $version->version_number,
            installed: $counts[self::OUTCOME_INSTALLED],
            skippedUnentitled: $counts[self::OUTCOME_SKIPPED_UNENTITLED],
            skippedUnavailable: $counts[self::OUTCOME_SKIPPED_UNAVAILABLE],
            failed: $counts[self::OUTCOME_FAILED],
            alreadyDecided: $counts[self::OUTCOME_ALREADY_DECIDED],
            undecided: $counts[self::OUTCOME_UNDECIDED],
        );
    }

    /**
     * §7.2's inner loop for exactly one component, in its own transaction.
     *
     * Order is load-bearing and matches §7.2 step for step: durable identities
     * are resolved BEFORE the transaction opens (nothing inside it depends on
     * a model this method was handed), the Business row is locked with the
     * exact `lockForUpdate()` call `BusinessTemplateApplier::applyPipelines()`
     * uses, the installation record is re-read INSIDE that lock, and only an
     * absent record or a `failed` one proceeds.
     *
     * @return string one of the OUTCOME_* constants
     */
    private function processComponent(
        Business $business,
        Workspace $workspace,
        int $ownerUserId,
        NicheBlueprint $blueprint,
        NicheBlueprintVersion $version,
        NicheBlueprintComponent $component,
    ): string {
        // Step 1 — durable identities, resolved once, before any lock.
        $businessId = (int) $business->id;
        $blueprintId = (int) $blueprint->id;
        $componentKey = (string) $component->component_key;
        $componentType = (string) $component->component_type;
        $featureKey = (string) $component->required_feature_key;
        $versionNumber = (int) $version->version_number;
        $payload = is_array($component->payload) ? $component->payload : [];

        try {
            return DB::transaction(function () use (
                $business,
                $workspace,
                $ownerUserId,
                $businessId,
                $blueprintId,
                $componentKey,
                $componentType,
                $featureKey,
                $versionNumber,
                $payload,
            ): string {
                // Step 2 — the exact line applyPipelines() uses, serialising
                // two concurrent runs for one Business against each other.
                //
                // The result is CHECKED, not discarded: a primary-key read
                // that matches no row takes no record lock at all, so if the
                // Business has been deleted since the job loaded it, two
                // concurrent runs would both sail past this line unserialised
                // and both reach an adapter. An absent Business is a hard stop.
                $locked = Business::query()->whereKey($businessId)->lockForUpdate()->first();

                if ($locked === null) {
                    return self::OUTCOME_UNDECIDED;
                }

                // Step 3 — re-read INSIDE the lock. A run that lost the race
                // sees the winner's committed record here and stops.
                $record = $this->findRecord($businessId, $blueprintId, $componentKey);

                if ($record !== null && $record->state !== BlueprintComponentInstallationState::Failed) {
                    // `installed`, `skipped_unentitled` and `skipped_unavailable`
                    // all stop an AUTOMATED run: reversing a skip is the
                    // owner's explicit action alone (§7.3), never this path's.
                    return self::OUTCOME_ALREADY_DECIDED;
                }

                // Step 4 — the one entitlement authority, asked for every
                // component without exception (§6.3).
                //
                // A THROW HERE IS NOT A COMPONENT FAILURE. If the decision
                // could not be MADE (a lock-wait timeout, a transient database
                // error), nothing is recorded: persisting it as `failed` would
                // hand a later run a retryable row whose retry re-decides
                // entitlement at a moment the owner never chose — so a plan
                // upgrade plus an ordinary recovery re-run would install into
                // an established Business. Persisting it as a skip would be
                // worse still, freezing it permanently. This is exactly §9.1's
                // own reasoning for the no-plan precondition: an unmade
                // decision is not a decision, and is not recorded as one.
                try {
                    $decision = $this->entitlements->decide($workspace, $business, $featureKey, $ownerUserId);
                } catch (Throwable $e) {
                    Log::warning('Niche Blueprint entitlement decision could not be made; nothing recorded.', [
                        'business_id' => $businessId,
                        'blueprint_id' => $blueprintId,
                        'component_key' => $componentKey,
                        'exception' => class_basename($e),
                    ]);

                    return self::OUTCOME_UNDECIDED;
                }

                if (! $decision->allowed) {
                    $state = $decision->reason === 'platform_feature_unavailable'
                        ? BlueprintComponentInstallationState::SkippedUnavailable
                        : BlueprintComponentInstallationState::SkippedUnentitled;

                    $this->writeRecord(
                        record: $record,
                        businessId: $businessId,
                        blueprintId: $blueprintId,
                        componentKey: $componentKey,
                        componentType: $componentType,
                        featureKey: $featureKey,
                        versionNumber: $versionNumber,
                        state: $state,
                        decisionReason: $decision->reason,
                    );

                    return $state === BlueprintComponentInstallationState::SkippedUnavailable
                        ? self::OUTCOME_SKIPPED_UNAVAILABLE
                        : self::OUTCOME_SKIPPED_UNENTITLED;
                }

                // Step 5 — the adapter runs only for an ALLOWED decision, with
                // the Business row already locked, and writes rows the Business
                // owns outright through the target module's own seam. There is
                // no privileged write path here: what it creates stays subject
                // to `decide()` on every later read, exactly like any other row
                // of its type (§6.3).
                $reference = $this->adapters->adapterFor($componentType)->install($business, $payload, null);

                $this->writeRecord(
                    record: $record,
                    businessId: $businessId,
                    blueprintId: $blueprintId,
                    componentKey: $componentKey,
                    componentType: $componentType,
                    featureKey: $featureKey,
                    versionNumber: $versionNumber,
                    state: BlueprintComponentInstallationState::Installed,
                    decisionReason: $decision->reason,
                    installedRecordType: $reference->recordType,
                    installedRecordId: $reference->recordId,
                );

                return self::OUTCOME_INSTALLED;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Defense in depth, not the mechanism: the Business row lock above
            // already serialises concurrent runs, so a losing run sees the
            // committed record at step 3 and never reaches the write.
            //
            // THE SWALLOW IS SCOPED TO THE FACT IT CLAIMS. Laravel maps EVERY
            // MySQL 1062 to this exception with no table discrimination, and
            // the adapter's own Business-owned writes happen inside this try —
            // so treating any duplicate key as "another runner decided this"
            // would silently erase a genuine adapter failure, leaving no
            // `failed` row, no `error_code`, and a component that no re-run
            // ever retries. So the claim is verified: only if a record now
            // genuinely exists was this someone else's decision. Otherwise it
            // is an adapter failure and takes the ordinary §7.4 path below.
            if ($this->findRecord($businessId, $blueprintId, $componentKey) !== null) {
                return self::OUTCOME_ALREADY_DECIDED;
            }

            return $this->recordFailure(
                businessId: $businessId,
                blueprintId: $blueprintId,
                componentKey: $componentKey,
                componentType: $componentType,
                featureKey: $featureKey,
                versionNumber: $versionNumber,
                throwable: $e,
            );
        } catch (Throwable $e) {
            // §7.4 — the adapter's own transaction has already rolled back, so
            // nothing it partially wrote survives. The failure is then made
            // durable in a SEPARATE transaction, and the run continues to the
            // next component: one broken component never blocks the other
            // nine.
            return $this->recordFailure(
                businessId: $businessId,
                blueprintId: $blueprintId,
                componentKey: $componentKey,
                componentType: $componentType,
                featureKey: $featureKey,
                versionNumber: $versionNumber,
                throwable: $e,
            );
        }
    }

    private function findRecord(int $businessId, int $blueprintId, string $componentKey): ?BusinessBlueprintComponentInstallation
    {
        return BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $businessId)
            ->where('blueprint_id', $blueprintId)
            ->where('component_key', $componentKey)
            ->first();
    }

    /**
     * The canonical write path for an installation record's OUTCOME fields.
     *
     * Every one of `state`, `decision_reason`, `installed_record_type`,
     * `installed_record_id`, `error_code`, `installed_at` and
     * `installed_by_user_id` is deliberately absent from the model's
     * `$fillable` (§5.4) precisely so this is the only place they are set —
     * a mass-assignable route to them would be a route around this state
     * machine, and the UNIQUE key would then make a forged `installed` row
     * permanent because an automated run never revisits one.
     *
     * Every column is written explicitly on every call, including back to
     * NULL: a `failed` record that later installs must not keep its stale
     * `error_code`, and a record that later skips must not keep a stale
     * `installed_record_*` pair.
     */
    private function writeRecord(
        ?BusinessBlueprintComponentInstallation $record,
        int $businessId,
        int $blueprintId,
        string $componentKey,
        string $componentType,
        string $featureKey,
        int $versionNumber,
        BlueprintComponentInstallationState $state,
        ?string $decisionReason = null,
        ?string $installedRecordType = null,
        ?int $installedRecordId = null,
        ?string $errorCode = null,
    ): BusinessBlueprintComponentInstallation {
        $record ??= new BusinessBlueprintComponentInstallation();

        $record->forceFill([
            'business_id' => $businessId,
            'blueprint_id' => $blueprintId,
            'component_key' => $componentKey,
            'component_type' => $componentType,
            'installed_from_version' => $versionNumber,
            'state' => $state->value,
            'required_feature_key' => $featureKey,
            'decision_reason' => $decisionReason,
            'installed_record_type' => $installedRecordType,
            'installed_record_id' => $installedRecordId,
            'error_code' => $errorCode,
            'installed_at' => $state === BlueprintComponentInstallationState::Installed ? now() : null,
            // §6.4 — a system install has no actor, and says so rather than
            // fabricating one.
            'installed_by_user_id' => null,
        ])->save();

        return $record;
    }

    /**
     * §7.4 — the `failed` record, written in its own transaction so the
     * failure is durable even though the work was not.
     *
     * Takes the same Business lock and re-reads under it, because a concurrent
     * runner may have committed a real decision for this component while this
     * one's adapter was throwing: a failure must never clobber another run's
     * `installed` row. Its own failure is swallowed and logged — being unable
     * to record a failure must not abort the remaining components.
     *
     * RETURNS WHAT IT ACTUALLY WROTE, rather than letting the caller assume.
     * When it declines (another run already decided the component) or cannot
     * write at all, reporting `failed` would tell an operator to investigate a
     * `failed` row that does not exist — and would make the recovery command
     * exit non-zero with nothing to show for it.
     *
     * @return string one of the OUTCOME_* constants
     */
    private function recordFailure(
        int $businessId,
        int $blueprintId,
        string $componentKey,
        string $componentType,
        string $featureKey,
        int $versionNumber,
        Throwable $throwable,
    ): string {
        try {
            return DB::transaction(function () use (
                $businessId,
                $blueprintId,
                $componentKey,
                $componentType,
                $featureKey,
                $versionNumber,
                $throwable,
            ): string {
                $locked = Business::query()->whereKey($businessId)->lockForUpdate()->first();

                if ($locked === null) {
                    return self::OUTCOME_UNDECIDED;
                }

                $record = $this->findRecord($businessId, $blueprintId, $componentKey);

                if ($record !== null && $record->state !== BlueprintComponentInstallationState::Failed) {
                    return self::OUTCOME_ALREADY_DECIDED;
                }

                $this->writeRecord(
                    record: $record,
                    businessId: $businessId,
                    blueprintId: $blueprintId,
                    componentKey: $componentKey,
                    componentType: $componentType,
                    featureKey: $featureKey,
                    versionNumber: $versionNumber,
                    state: BlueprintComponentInstallationState::Failed,
                    errorCode: $this->errorCodeFor($throwable),
                );

                return self::OUTCOME_FAILED;
            });
        } catch (Throwable $recordingFailure) {
            Log::warning('Niche Blueprint component failure could not be recorded.', [
                'business_id' => $businessId,
                'blueprint_id' => $blueprintId,
                'component_key' => $componentKey,
                'exception' => class_basename($recordingFailure),
            ]);

            return self::OUTCOME_UNDECIDED;
        }
    }

    /**
     * Bounded, non-sensitive provenance: the exception's class name alone,
     * never its message, which can carry SQL, paths or customer data.
     *
     * SANITISED, NOT MERELY TRUNCATED. PHP names an anonymous class
     * `RuntimeException@anonymous\0/abs/path/To/File.php:41$0`, and
     * `class_basename()` on that yields `File.php:41$0` — a source path
     * fragment, plus an embedded NUL byte, written straight into a provenance
     * column that must never carry a path. So anything that is not a bare PHP
     * class-name shape collapses to one fixed, safe constant.
     */
    private function errorCodeFor(Throwable $throwable): string
    {
        $code = class_basename($throwable);

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $code) !== 1) {
            return 'UnnamedThrowable';
        }

        return mb_substr($code, 0, self::MAX_ERROR_CODE);
    }
}
