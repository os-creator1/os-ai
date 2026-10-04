<?php

namespace App\Library\NicheBlueprint\Workspace;

use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentDefinition;
use App\Library\NicheBlueprint\Adapters\FingerprintsInstalledComponent;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;

/**
 * Blueprint V2 — "is there a newer niche version for this Business?" — and
 * NOTHING ELSE. It reads, compares and reports. It never installs, replaces or
 * deletes: a Copy component whose Blueprint descriptor changed is reported as
 * `update_available`, with `owner_modified` saying whether replacing it would
 * overwrite the owner's own customisation. Applying an update is always a
 * deliberate, separate act (not part of this lane).
 *
 * Components are matched ONLY by (blueprint_id, component_key) — the stable
 * identity — never by a mutable name or label.
 *
 * Statuses:
 *   current          the latest published descriptor is the one installed
 *   update_available a newer descriptor exists (Copy components)
 *   live             Live component: reads the latest version, nothing to apply
 *   removed_upstream the latest version no longer carries this component
 *   unknown          provisioned before V2 provenance hashes existed
 */
class BlueprintUpdateDetector
{
    public const CURRENT = 'current';

    public const UPDATE_AVAILABLE = 'update_available';

    public const LIVE = 'live';

    public const REMOVED_UPSTREAM = 'removed_upstream';

    public const UNKNOWN = 'unknown';

    public function __construct(private readonly BlueprintComponentAdapterRegistry $adapters) {}

    /**
     * @return array{
     *   provisioned_version: ?int,
     *   components: list<array<string, mixed>>,
     *   new_components: list<array<string, mixed>>,
     *   updates_available: int
     * }
     */
    public function forBusiness(Business $business): array
    {
        $records = BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->where('state', BlueprintComponentInstallationState::Installed->value)
            ->orderBy('id')
            ->get();

        $latestByBlueprint = [];
        $rows = [];

        foreach ($records as $record) {
            $blueprintId = (int) $record->blueprint_id;
            $latestByBlueprint[$blueprintId] ??= $this->latestComponents($blueprintId);
            $latest = $latestByBlueprint[$blueprintId];
            $upstream = $latest[(string) $record->component_key] ?? null;

            $adapter = $this->adapters->find((string) $record->component_type);
            $policy = $adapter instanceof BlueprintComponentDefinition ? $adapter->updatePolicy() : BlueprintUpdatePolicy::Copy;

            $status = match (true) {
                $upstream === null => self::REMOVED_UPSTREAM,
                $policy === BlueprintUpdatePolicy::Live => self::LIVE,
                $record->source_checksum === null => self::UNKNOWN,
                $record->source_checksum !== BlueprintChecksum::of($upstream->payload) => self::UPDATE_AVAILABLE,
                default => self::CURRENT,
            };

            $rows[] = [
                'blueprint_id' => $blueprintId,
                'component_key' => (string) $record->component_key,
                'component_type' => (string) $record->component_type,
                'policy' => $policy->value,
                'installed_from_version' => (int) $record->installed_from_version,
                'status' => $status,
                'owner_modified' => $policy === BlueprintUpdatePolicy::Copy ? $this->ownerModified($business, $record, $adapter, $upstream) : null,
                'summary' => $adapter instanceof BlueprintComponentDefinition && $upstream !== null ? $adapter->summary($upstream->payload ?? []) : null,
            ];
        }

        $newComponents = [];
        $installedKeys = collect($rows)->map(fn ($r) => $r['blueprint_id'].'|'.$r['component_key'])->all();

        foreach ($latestByBlueprint as $blueprintId => $latest) {
            foreach ($latest as $key => $component) {
                if (! in_array($blueprintId.'|'.$key, $installedKeys, true)
                    && ! $this->hasAnyRecord($business, $blueprintId, (string) $key)) {
                    $newComponents[] = ['blueprint_id' => $blueprintId, 'component_key' => (string) $key, 'component_type' => (string) $component->component_type];
                }
            }
        }

        return [
            'provisioned_version' => $records->isEmpty() ? null : (int) $records->min('installed_from_version'),
            'components' => $rows,
            'new_components' => $newComponents,
            'updates_available' => collect($rows)->where('status', self::UPDATE_AVAILABLE)->count(),
        ];
    }

    private function hasAnyRecord(Business $business, int $blueprintId, string $key): bool
    {
        return BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)->where('blueprint_id', $blueprintId)->where('component_key', $key)->exists();
    }

    /** @return array<string, NicheBlueprintComponent> keyed by component_key */
    private function latestComponents(int $blueprintId): array
    {
        $version = NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprintId)
            ->where('state', NicheBlueprintVersionState::Published->value)
            ->first();

        if ($version === null) {
            return [];
        }

        return NicheBlueprintComponent::query()->where('blueprint_version_id', $version->id)->get()->keyBy('component_key')->all();
    }

    private function ownerModified(Business $business, BusinessBlueprintComponentInstallation $record, mixed $adapter, ?NicheBlueprintComponent $upstream): ?bool
    {
        if (! $adapter instanceof FingerprintsInstalledComponent || $record->installed_fingerprint === null || $record->installed_record_id === null) {
            return null;
        }

        // The fingerprint is computed against the descriptor that was installed,
        // not the newer one: it answers "did the owner change what we gave them".
        $installedPayload = NicheBlueprintComponent::query()
            ->join('niche_blueprint_versions as v', 'v.id', '=', 'niche_blueprint_components.blueprint_version_id')
            ->where('niche_blueprint_components.blueprint_id', $record->blueprint_id)
            ->where('v.version_number', $record->installed_from_version)
            ->where('niche_blueprint_components.component_key', $record->component_key)
            ->first(['niche_blueprint_components.*']);

        $now = $adapter->fingerprint(
            $business,
            new InstalledComponentReference((string) $record->installed_record_type, (int) $record->installed_record_id),
            is_array($installedPayload?->payload) ? $installedPayload->payload : [],
        );

        // null = the copy was deleted/archived, which is itself an owner change.
        return $now !== $record->installed_fingerprint;
    }
}
