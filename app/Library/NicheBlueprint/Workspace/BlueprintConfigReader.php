<?php

namespace App\Library\NicheBlueprint\Workspace;

use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentDefinition;
use App\Library\NicheBlueprint\Adapters\DocumentTemplateComponentAdapter;
use App\Library\NicheBlueprint\Adapters\SeoStrategyComponentAdapter;
use App\Library\NicheBlueprint\Adapters\WebsiteConfigComponentAdapter;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;

/**
 * Blueprint V2 — the ONE read seam through which other modules (Website, SEO,
 * Documents) consume Blueprint configuration for a Business.
 *
 * Copy components are read from the version the Business was provisioned from
 * (immutable, so a later publish can never change what the Business sees);
 * Live components are read from the latest published version. A Business with
 * no installed record for a component gets null — it never falls back to
 * "whatever the niche has", because that would be a silent push.
 *
 * Read-only: nothing here writes, sends or calls a provider.
 */
class BlueprintConfigReader
{
    public function __construct(private readonly BlueprintComponentAdapterRegistry $adapters) {}

    /** The Website module's seam: preferred template, page strategy, navigation, prompts. */
    public function websiteConfig(Business $business): ?array
    {
        return $this->first($business, WebsiteConfigComponentAdapter::TYPE);
    }

    /** The SEO module's seam: keyword-intent patterns, FAQ topics, schema strategy, internal links. */
    public function seoStrategy(Business $business): ?array
    {
        return $this->first($business, SeoStrategyComponentAdapter::TYPE);
    }

    /** Payment-term DEFAULTS carried by the Business's recommended document templates (guidance only). */
    public function paymentTermDefaults(Business $business): array
    {
        $out = [];

        foreach ($this->all($business, DocumentTemplateComponentAdapter::TYPE) as $key => $payload) {
            if (! empty($payload['payment_terms'])) {
                $out[$key] = $payload['payment_terms'];
            }
        }

        return $out;
    }

    private function first(Business $business, string $type): ?array
    {
        $all = $this->all($business, $type);

        return $all === [] ? null : reset($all);
    }

    /**
     * @return array<string, array<string, mixed>> component_key => payload
     */
    public function all(Business $business, string $type): array
    {
        $adapter = $this->adapters->find($type);
        $policy = $adapter instanceof BlueprintComponentDefinition ? $adapter->updatePolicy() : BlueprintUpdatePolicy::Copy;

        $records = BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->where('component_type', $type)
            ->where('state', BlueprintComponentInstallationState::Installed->value)
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($records as $record) {
            $versionNumber = $policy === BlueprintUpdatePolicy::Live
                ? NicheBlueprintVersion::query()
                    ->where('blueprint_id', $record->blueprint_id)
                    ->where('state', NicheBlueprintVersionState::Published->value)
                    ->value('version_number')
                : $record->installed_from_version;

            if ($versionNumber === null) {
                continue;
            }

            $component = NicheBlueprintComponent::query()
                ->join('niche_blueprint_versions as v', 'v.id', '=', 'niche_blueprint_components.blueprint_version_id')
                ->where('niche_blueprint_components.blueprint_id', $record->blueprint_id)
                ->where('v.version_number', $versionNumber)
                ->where('niche_blueprint_components.component_key', $record->component_key)
                ->first(['niche_blueprint_components.*']);

            if ($component !== null && is_array($component->payload)) {
                $out[(string) $record->component_key] = $component->payload;
            }
        }

        return $out;
    }
}
