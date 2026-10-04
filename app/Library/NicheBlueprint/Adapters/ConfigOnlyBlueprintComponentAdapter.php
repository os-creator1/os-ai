<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\Business;

/**
 * Base for components whose "install" copies nothing into a Business-owned
 * table because the consuming module reads the configuration through
 * BlueprintConfigReader (Website blueprint config, SEO strategy). The
 * installation record is still written: it is the Business's provenance
 * ("this Business uses blueprint X, component Y, from version N") and what the
 * reader and the update detector key on.
 *
 * The reference points at the Business itself (the only guaranteed row), which
 * is provenance only.
 */
abstract class ConfigOnlyBlueprintComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent
{
    use InteractsWithBlueprintPayload;

    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        return new InstalledComponentReference('blueprint_config', (int) $business->id);
    }

    /** The owner cannot edit configuration that lives only in the Blueprint, so the copy is never "modified". */
    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string
    {
        return BlueprintChecksum::of($payload);
    }

    public function inputFromPayload(array $payload): array
    {
        return $payload;
    }
}
