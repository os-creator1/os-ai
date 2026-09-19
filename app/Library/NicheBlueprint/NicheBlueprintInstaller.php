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
        $installed = 0;
        $skippedUnentitled = 0;
        $skippedUnavailable = 0;
        $failed = 0;
        $alreadyDecided = 0;

        // Ordered by `position` then `id` on the relation itself (§5.3).
        foreach ($version->components as $component) {
            $outcome = $this->processComponent($business, $workspace, $ownerUserId, $blueprint, $version, $component);

            match ($outcome) {
                BlueprintComponentInstallationState::Installed => $installed++,
                BlueprintComponentInstallationState::SkippedUnentitled => $skippedUnentitled++,
                BlueprintComponentInstallationState::SkippedUnavailable => $skippedUnavailable++,
                BlueprintComponentInstallationState::Failed => $failed++,
                default => $alreadyDecided++,
            };
        }

        return new BlueprintInstallationRunResult(
            blueprintId: (int) $blueprint->id,
            versionNumber: (int) $version->version_number,
            installed: $installed,
            skippedUnentitled: $skippedUnentitled,
            skippedUnavailable: $skippedUnavailable,
            failed: $failed,
            alreadyDecided: $alreadyDecided,
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
     * @return BlueprintComponentInstallationState|null the outcome, or null when a
     *                                                  prior decision already existed
     */
    private function processComponent(
        Business $business,
        Workspace $workspace,
        int $ownerUserId,
        NicheBlueprint $blueprint,
        NicheBlueprintVersion $version,
        NicheBlueprintComponent $component,
    ): ?BlueprintComponentInstallationState {
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
            ): ?BlueprintComponentInstallationState {
                // Step 2 — the exact line applyPipelines() uses, serialising
                // two concurrent runs for one Business against each other.
                Business::query()->whereKey($businessId)->lockForUpdate()->first();

                // Step 3 — re-read INSIDE the lock. A run that lost the race
                // sees the winner's committed record here and stops.
                $record = $this->findRecord($businessId, $blueprintId, $componentKey);

                if ($record !== null && $record->state !== BlueprintComponentInstallationState::Failed) {
                    // `installed`, `skipped_unentitled` and `skipped_unavailable`
                    // all stop an AUTOMATED run: reversing a skip is the
                    // owner's explicit action alone (§7.3), never this path's.
                    return null;
                }

                // Step 4 — the one entitlement authority, asked for every
                // component without exception (§6.3).
                $decision = $this->entitlements->decide($workspace, $business, $featureKey, $ownerUserId);

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

                    return $state;
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

                return BlueprintComponentInstallationState::Installed;
            });
        } catch (UniqueConstraintViolationException) {
            // Defense in depth, not the mechanism: the Business row lock above
            // already serialises concurrent runs, so a losing run sees the
            // committed record at step 3 and never reaches the write. If the
            // UNIQUE key ever fires anyway, another runner decided this
            // component — which is the same outcome step 3 would have
            // produced, so it is treated identically rather than surfaced as a
            // failure or recorded as one (recording it would itself violate
            // the same key).
            return null;
        } catch (Throwable $e) {
            // §7.4 — the adapter's own transaction has already rolled back, so
            // nothing it partially wrote survives. The failure is then made
            // durable in a SEPARATE transaction, and the run continues to the
            // next component: one broken component never blocks the other
            // nine.
            $this->recordFailure(
                businessId: $businessId,
                blueprintId: $blueprintId,
                componentKey: $componentKey,
                componentType: $componentType,
                featureKey: $featureKey,
                versionNumber: $versionNumber,
                throwable: $e,
            );

            return BlueprintComponentInstallationState::Failed;
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
     */
    private function recordFailure(
        int $businessId,
        int $blueprintId,
        string $componentKey,
        string $componentType,
        string $featureKey,
        int $versionNumber,
        Throwable $throwable,
    ): void {
        try {
            DB::transaction(function () use (
                $businessId,
                $blueprintId,
                $componentKey,
                $componentType,
                $featureKey,
                $versionNumber,
                $throwable,
            ): void {
                Business::query()->whereKey($businessId)->lockForUpdate()->first();

                $record = $this->findRecord($businessId, $blueprintId, $componentKey);

                if ($record !== null && $record->state !== BlueprintComponentInstallationState::Failed) {
                    return;
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
            });
        } catch (Throwable $recordingFailure) {
            Log::warning('Niche Blueprint component failure could not be recorded.', [
                'business_id' => $businessId,
                'blueprint_id' => $blueprintId,
                'component_key' => $componentKey,
                'exception' => class_basename($recordingFailure),
            ]);
        }
    }

    /**
     * Bounded, non-sensitive provenance: the exception's class name alone,
     * never its message, which can carry SQL, paths or customer data. The
     * column is string(64), so a longer class name is truncated rather than
     * failing the write that records the failure.
     */
    private function errorCodeFor(Throwable $throwable): string
    {
        return mb_substr(class_basename($throwable), 0, self::MAX_ERROR_CODE);
    }
}
