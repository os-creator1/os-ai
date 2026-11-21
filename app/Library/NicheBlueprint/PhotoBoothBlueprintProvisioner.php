<?php

namespace App\Library\NicheBlueprint;

use App\Enums\Business\BusinessIndustry;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\NicheBlueprint;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;

/**
 * Assesses and repairs ONE Business's Photo Booth Blueprint provisioning, through the
 * existing idempotent engine (NicheBlueprintInstaller) and nothing else.
 *
 * WHY IT EXISTS. Seeding a Blueprint (`blueprint:seed-photo-booth*`) only publishes the
 * niche definition; it installs nothing for any Business. Installation runs only from
 * `BusinessCreated` and the first `WorkspacePlanAssigned`, and resolves the Blueprint from
 * the Business's industry AT THAT MOMENT. An Agency-provisioned client Business is created
 * with the placeholder industry `other`, so both triggers resolved no Blueprint and wrote
 * nothing; the client's real industry arrives later (activateClientBusiness / profile edit)
 * with `BusinessUpdated`, which had no installation trigger. Businesses that predate the
 * Blueprint's publication are in the same state. Such a Business therefore holds zero
 * installation records and the Website module (preferred design, lead forms, guided-
 * generation hints) sees no Blueprint.
 *
 * SAFETY, BY CONSTRUCTION:
 *  - eligible only when the industry is exactly `photo_booth_service`, the engine's own
 *    resolution (§7.1) lands on the `photo_booth` Blueprint, and the Business holds ZERO
 *    installation records. A Business holding any record (valid, skipped or failed) is
 *    never touched — it is pinned to what it was provisioned with (§8.1); failed
 *    components remain `blueprint:install-missing`'s job.
 *  - writes only installation records and the Blueprint's own adapter-owned rows through
 *    the installer. It never reads or writes a Website, questionnaire answer, page,
 *    revision or customization.
 */
class PhotoBoothBlueprintProvisioner
{
    public const BLUEPRINT_KEY = 'photo_booth';

    public const ELIGIBLE = 'eligible_missing_blueprint';

    public const ALREADY_PROVISIONED = 'already_provisioned';

    public const NOT_PHOTO_BOOTH = 'not_photo_booth';

    public const RESOLVES_ELSEWHERE = 'resolves_to_other_blueprint';

    public const BLOCKED_NO_PUBLISHED_BLUEPRINT = 'blocked_no_published_photo_booth_blueprint';

    public const BLOCKED_WORKSPACE = 'blocked_workspace_unresolved';

    public const BLOCKED_PLAN_UNASSIGNED = 'blocked_workspace_plan_unassigned';

    public const BLOCKED_ENGINE_ABORT = 'blocked_engine_abort';

    public const INSTALLED = 'installed';

    public function __construct(
        private readonly NicheBlueprintInstaller $installer,
        private readonly WorkspacePlanAssignmentRepository $planAssignments,
    ) {
    }

    /**
     * Read-only. Never writes.
     *
     * @return array{status: string, detail: string}
     */
    public function assess(Business $business): array
    {
        $industry = $business->industry;
        $industryValue = $industry instanceof BusinessIndustry ? $industry->value : (is_string($industry) ? $industry : null);

        if ($industryValue !== BusinessIndustry::PhotoBoothService->value) {
            return $this->result(self::NOT_PHOTO_BOOTH, 'industry='.($industryValue ?? 'null'));
        }

        $recordCount = BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->count();

        if ($recordCount > 0) {
            return $this->result(self::ALREADY_PROVISIONED, "installation records={$recordCount}; left untouched");
        }

        $resolution = $this->installer->resolveBlueprint($business);

        if (! $resolution instanceof NicheBlueprint) {
            return $this->result(self::BLOCKED_NO_PUBLISHED_BLUEPRINT, 'no active Blueprint resolves ('.$resolution->abortReason.'); run blueprint:seed-photo-booth first');
        }

        if ($resolution->key !== self::BLUEPRINT_KEY) {
            return $this->result(self::RESOLVES_ELSEWHERE, "resolves to '{$resolution->key}'; left untouched");
        }

        if (! $resolution->versions()->where('state', \App\Enums\NicheBlueprint\NicheBlueprintVersionState::Published->value)->exists()) {
            return $this->result(self::BLOCKED_NO_PUBLISHED_BLUEPRINT, 'photo_booth has no published version');
        }

        $workspace = $business->workspace()->first();

        if (! $workspace instanceof Workspace || (int) ($workspace->owner_user_id ?? 0) < 1) {
            return $this->result(self::BLOCKED_WORKSPACE, 'workspace or workspace owner unresolved');
        }

        if ($this->planAssignments->findByWorkspaceId((int) $workspace->id) === null) {
            return $this->result(self::BLOCKED_PLAN_UNASSIGNED, 'no plan assignment yet; the first plan assignment will install it automatically');
        }

        return $this->result(self::ELIGIBLE, 'would install the photo_booth Blueprint');
    }

    /**
     * Assesses, and installs only an ELIGIBLE Business. Idempotent: a second call finds
     * records and reports ALREADY_PROVISIONED.
     *
     * @return array{status: string, detail: string}
     */
    public function repair(Business $business): array
    {
        $assessment = $this->assess($business);

        if ($assessment['status'] !== self::ELIGIBLE) {
            return $assessment;
        }

        $run = $this->installer->installForBusiness($business);

        if ($run->wasAborted()) {
            return $this->result(self::BLOCKED_ENGINE_ABORT, (string) $run->abortReason);
        }

        return $this->result(
            self::INSTALLED,
            "installed={$run->installed} skipped_unentitled={$run->skippedUnentitled} skipped_unavailable={$run->skippedUnavailable} failed={$run->failed}",
        );
    }

    /** @return array{status: string, detail: string} */
    private function result(string $status, string $detail): array
    {
        return ['status' => $status, 'detail' => $detail];
    }
}
