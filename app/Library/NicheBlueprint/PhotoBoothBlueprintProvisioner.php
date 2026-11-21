<?php

namespace App\Library\NicheBlueprint;

use App\Enums\Business\BusinessIndustry;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessVertical;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\TemplateOnlyComponent;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
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

    /** Required components remain undecided, failed or never attempted. Never reported as installed. */
    public const BLOCKED_INCOMPLETE = 'blocked_incomplete_installation';

    /** Not a Photo Booth Business by industry; eligible ONLY because an operator explicitly selected it. */
    public const ELIGIBLE_ASSIGNMENT = 'eligible_explicit_assignment';

    /** Its Knowledge Profile already names a different niche vertical; never overwritten. */
    public const BLOCKED_OTHER_VERTICAL = 'blocked_other_niche_vertical';

    /** The catalog already reserves this niche key but cannot be used for a Photo Booth assignment. */
    public const BLOCKED_NICHE_CATALOG = 'blocked_niche_catalog_unavailable';

    public const INSTALLED = 'installed';

    /** The niche key a Business is assigned to; names the vertical and the questionnaire/design niche. */
    public const NICHE_KEY = 'photo_booth_service';

    /** The one recovery path for an incomplete run; it retries only undecided and failed components. */
    private const RECOVERY_COMMAND = 'php artisan blueprint:install-missing --business=';

    public function __construct(
        private readonly NicheBlueprintInstaller $installer,
        private readonly WorkspacePlanAssignmentRepository $planAssignments,
        private readonly BlueprintComponentAdapterRegistry $adapters,
        private readonly BusinessKnowledgeProfileManager $profiles,
    ) {
    }

    /**
     * Read-only. Never writes.
     *
     * @return array{status: string, detail: string}
     */
    public function assess(Business $business, bool $assign = false): array
    {
        $industry = $business->industry;
        $industryValue = $industry instanceof BusinessIndustry ? $industry->value : (is_string($industry) ? $industry : null);
        $profileVertical = BusinessKnowledgeProfile::query()->where('business_id', $business->id)->value('vertical_key');
        $isPhotoBooth = $industryValue === BusinessIndustry::PhotoBoothService->value || $profileVertical === self::NICHE_KEY;

        if (! $isPhotoBooth && ! $assign) {
            return $this->result(self::NOT_PHOTO_BOOTH, 'industry='.($industryValue ?? 'null').'; to assign Photo Booth to this one Business use --assign-photo-booth with --business');
        }

        $recordCount = BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->count();

        if ($recordCount > 0) {
            // Some records are NOT proof of a complete installation: a run that stopped part-way leaves
            // records for the components it reached. Surface that instead of calling it provisioned.
            $gap = $this->incompleteness($business);

            if ($gap !== null) {
                return $this->result(self::BLOCKED_INCOMPLETE, $gap.'; '.$this->recoveryInstruction($business));
            }

            return $this->result(self::ALREADY_PROVISIONED, "installation records={$recordCount}; left untouched");
        }

        $explicit = ! $isPhotoBooth;

        if ($explicit && is_string($profileVertical) && trim($profileVertical) !== '') {
            return $this->result(self::BLOCKED_OTHER_VERTICAL, "knowledge profile vertical_key='{$profileVertical}'; never overwritten");
        }

        if ($explicit) {
            // firstOrCreate() cannot reactivate or repair an existing catalog row.
            // Check BEFORE any write: the profile manager rejects an inactive key.
            $catalog = BusinessVertical::query()->where('key', self::NICHE_KEY)->first();
            if ($catalog !== null && (! $catalog->is_active || $catalog->broad_industry !== self::NICHE_KEY)) {
                return $this->result(self::BLOCKED_NICHE_CATALOG, 'Photo Booth niche catalog row is inactive or belongs to another industry; resolve the catalog before assigning');
            }
        }

        // An explicit assignment has no resolvable niche yet (that is what the assignment creates), so the
        // target is the one active, vertical-free photo_booth Blueprint for exactly this niche.
        $resolution = $explicit ? $this->assignableBlueprint() : $this->installer->resolveBlueprint($business);

        if (! $resolution instanceof NicheBlueprint) {
            // Explicit assignment resolves directly by the Photo Booth Blueprint and may find no match.
            // Do not dereference a null resolution; fail closed without writing the vertical or profile.
            $reason = $resolution === null ? 'no matching active Photo Booth Blueprint' : (string) $resolution->abortReason;

            return $this->result(self::BLOCKED_NO_PUBLISHED_BLUEPRINT, 'no active Blueprint resolves ('.$reason.'); run blueprint:seed-photo-booth first');
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

        return $explicit
            ? $this->result(self::ELIGIBLE_ASSIGNMENT, "would assign niche '".self::NICHE_KEY."' (Business.industry '".($industryValue ?? 'null')."' is not changed) and install the photo_booth Blueprint")
            : $this->result(self::ELIGIBLE, 'would install the photo_booth Blueprint');
    }

    private function assignableBlueprint(): ?NicheBlueprint
    {
        $matches = NicheBlueprint::query()
            ->active()
            ->where('key', self::BLUEPRINT_KEY)
            ->where('broad_industry', self::NICHE_KEY)
            ->whereNull('vertical_key')
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Assesses, and installs only an ELIGIBLE Business. Idempotent: a second call finds
     * records and reports ALREADY_PROVISIONED.
     *
     * @return array{status: string, detail: string}
     */
    public function repair(Business $business, bool $assign = false): array
    {
        $assessment = $this->assess($business, $assign);

        if (! in_array($assessment['status'], [self::ELIGIBLE, self::ELIGIBLE_ASSIGNMENT], true)) {
            return $assessment;
        }

        $assigned = false;

        if ($assessment['status'] === self::ELIGIBLE_ASSIGNMENT) {
            $this->assignNiche($business);
            $assigned = true;
        }

        $run = $this->installer->installForBusiness($business);

        if ($run->wasAborted()) {
            return $this->result(self::BLOCKED_ENGINE_ABORT, ($assigned ? 'niche assigned; ' : '').(string) $run->abortReason);
        }

        // An aborted run wrote nothing. A run that finished its loop can STILL be incomplete: an undecided
        // component persists no record at all, and a failed one is recorded `failed`. Neither may be called
        // installed, and neither is retried by this command (that is install-missing's job).
        if ($run->undecided > 0 || $run->failed > 0) {
            return $this->result(
                self::BLOCKED_INCOMPLETE,
                ($assigned ? 'niche assigned; ' : '')."installed={$run->installed} undecided={$run->undecided} failed={$run->failed}; ".$this->recoveryInstruction($business),
            );
        }

        return $this->result(
            self::INSTALLED,
            ($assigned ? 'niche assigned; ' : '')."installed={$run->installed} skipped_unentitled={$run->skippedUnentitled} skipped_unavailable={$run->skippedUnavailable} failed={$run->failed}",
        );
    }

    /**
     * The ONLY write besides the installer: ensures the niche's catalog row exists and names it in the
     * Business's Knowledge Profile through the profile's one authorised write path, UNVERIFIED so the
     * customer-confirmed question-pack resolution is unaffected. `Business.industry` is never touched.
     */
    private function assignNiche(Business $business): void
    {
        BusinessVertical::query()->firstOrCreate(
            ['key' => self::NICHE_KEY],
            ['display_name' => BusinessIndustry::PhotoBoothService->label(), 'broad_industry' => self::NICHE_KEY, 'is_active' => true],
        );

        $ownerUserId = (int) ($business->workspace()->value('owner_user_id') ?? 0);

        $this->profiles->updateFields($business, ['vertical_key' => self::NICHE_KEY], 'manual_edit', $ownerUserId, false);
    }

    private function recoveryInstruction(Business $business): string
    {
        return 'run `'.self::RECOVERY_COMMAND.$business->id.'` to retry the remaining components';
    }

    /**
     * Describes what a Business's own pinned Blueprint version still lacks, or null when complete.
     * Template-only components never have records and are not counted.
     */
    private function incompleteness(Business $business): ?string
    {
        $records = BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->get();
        $blueprintIds = $records->pluck('blueprint_id')->unique();

        if ($blueprintIds->count() !== 1) {
            return null; // another Blueprint or an ambiguous identity is not this command's concern
        }

        $blueprint = NicheBlueprint::query()->whereKey((int) $blueprintIds->first())->first();

        if ($blueprint === null || $blueprint->key !== self::BLUEPRINT_KEY) {
            return null;
        }

        $version = NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('version_number', (int) $records->max('installed_from_version'))
            ->first();

        if ($version === null) {
            return null;
        }

        $recorded = $records->pluck('component_key')->all();
        $failed = $records->filter(fn ($r) => $r->state === BlueprintComponentInstallationState::Failed)->count();
        $missing = 0;

        foreach (NicheBlueprintComponent::query()->where('blueprint_version_id', $version->id)->get() as $component) {
            try {
                if ($this->adapters->adapterFor((string) $component->component_type) instanceof TemplateOnlyComponent) {
                    continue;
                }
            } catch (\Throwable) {
                // An unregistered type is counted like any other component without a record.
            }

            if (! in_array((string) $component->component_key, $recorded, true)) {
                $missing++;
            }
        }

        return ($failed + $missing) > 0 ? "incomplete: undecided_or_unattempted={$missing} failed={$failed}" : null;
    }

    /** @return array{status: string, detail: string} */
    private function result(string $status, string $detail): array
    {
        return ['status' => $status, 'detail' => $detail];
    }
}
