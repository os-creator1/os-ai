<?php

namespace App\Library\Documents\Templates;

use App\Enums\Documents\DocumentTemplateStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\Entitlement\EntitlementManager;
use App\Library\NicheBlueprint\Adapters\DocumentTemplateComponentAdapter;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\Business;
use App\Models\DocumentTemplate;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Implementation Contract 17B §6 / §6b — the ONLY door to platform-owned
 * templates.
 *
 * A Business never lists, reads or uses a platform template directly: a
 * platform template is reachable only if it is in this collection.
 * DocumentTemplateAccess consults it for every read / use decision, so a forged
 * or merely-guessed platform uid fails closed.
 *
 * "Recommended" =
 *   the Business's niche blueprint (the SAME resolution the installer uses —
 *   NicheBlueprintInstaller::resolveBlueprint(): knowledge-profile vertical,
 *   then the single broad-industry blueprint; an active blueprint only)
 *   -> its PUBLISHED version's `document_template` components (a draft or a
 *      superseded version recommends nothing)
 *   -> the platform templates (business_id NULL) those components reference
 *      that are `active` right now (a disabled template disappears at once,
 *      whatever the blueprint version says)
 *   -> only when the Business is entitled to PaymentsContracts.
 *
 * Nothing is copied and nothing is installed by this lookup; it is read-only.
 * Cost: resolution (1-3 reads) + components (1) + templates (1) + entitlement
 * (the per-request-cached snapshot) — independent of how many templates there
 * are. An unrelated niche, a Business with no blueprint and a blueprint with no
 * published version all yield an empty collection: there is no global dump.
 */
class RecommendedPlatformTemplates
{
    public function __construct(
        private readonly NicheBlueprintInstaller $installer,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * @return Collection<int, DocumentTemplate>
     */
    public function forBusiness(Business $business): Collection
    {
        $blueprint = $this->installer->resolveBlueprint($business);

        if (! $blueprint instanceof NicheBlueprint) {
            return collect();
        }

        $uids = NicheBlueprintComponent::query()
            ->whereIn('blueprint_version_id', function ($query) use ($blueprint): void {
                $query->select('id')
                    ->from('niche_blueprint_versions')
                    ->where('blueprint_id', $blueprint->id)
                    ->where('state', NicheBlueprintVersionState::Published->value);
            })
            ->where('component_type', DocumentTemplateComponentAdapter::TYPE)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['payload'])
            ->map(fn (NicheBlueprintComponent $component) => is_array($component->payload) ? ($component->payload['template_uid'] ?? null) : null)
            ->filter(fn ($uid) => is_string($uid) && $uid !== '')
            ->unique()
            ->values();

        if ($uids->isEmpty()) {
            return collect();
        }

        $templates = DocumentTemplate::query()
            ->whereNull('business_id')
            ->where('status', DocumentTemplateStatus::Active->value)
            ->whereIn('uid', $uids->all())
            ->get()
            ->keyBy('uid');

        if ($templates->isEmpty() || ! $this->entitled($business)) {
            return collect();
        }

        // Keep the blueprint's own component order.
        return $uids->map(fn (string $uid) => $templates->get($uid))->filter()->values();
    }

    private function entitled(Business $business): bool
    {
        $workspace = $business->workspace()->first();

        if (! $workspace instanceof Workspace) {
            return false;
        }

        // The actor id is a signature requirement of decide(); no precedence
        // step reads it (the installer relies on the same fact).
        return $this->entitlements
            ->decide($workspace, $business, PlatformFeature::PaymentsContracts->value, (int) ($workspace->owner_user_id ?? 0))
            ->allowed;
    }
}
