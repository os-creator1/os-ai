<?php

namespace App\Library\NicheBlueprint\Workspace;

use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentDefinition;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\Business;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Blueprint V2 — the domain service behind the Blueprint Workspace screens.
 *
 * Every write goes through NicheBlueprintPublisher (the existing authoring
 * authority: admin check, blueprint lock, draft-only guard, publish gates).
 * This class adds only what the Workspace needs on top: component identity
 * generation, surface grouping and positions, and the overview numbers.
 *
 * COMPONENT IDENTITY. A new component gets a generated, immutable
 * `component_key` (`<type>_<random>`), never derived from a name or label, so
 * renaming "Welcome follow-up" in the Workspace cannot make a later version
 * look like a different component to the Businesses that installed it.
 * Updating a component keeps its key.
 */
class BlueprintWorkspaceService
{
    public function __construct(
        private readonly NicheBlueprintPublisher $publisher,
        private readonly BlueprintComponentAdapterRegistry $adapters,
    ) {}

    /** @return array<string, BlueprintComponentDefinition> component_type => definition, in registration order */
    public function definitions(): array
    {
        $out = [];

        foreach ($this->adapters->registeredComponentTypes() as $type) {
            $adapter = $this->adapters->find($type);

            if ($adapter instanceof BlueprintComponentDefinition) {
                $out[$type] = $adapter;
            }
        }

        return $out;
    }

    /** @return array<string, BlueprintComponentDefinition> the types authored on one surface */
    public function definitionsForSurface(string $surface): array
    {
        return array_filter($this->definitions(), fn (BlueprintComponentDefinition $d) => $d->surface() === $surface);
    }

    public function definitionFor(string $type): BlueprintComponentDefinition
    {
        return $this->definitions()[$type] ?? throw new InvalidArgumentException("Component type [{$type}] cannot be authored in the Workspace.");
    }

    public function existingDraft(NicheBlueprint $blueprint): ?NicheBlueprintVersion
    {
        return NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Draft->value)
            ->first();
    }

    public function published(NicheBlueprint $blueprint): ?NicheBlueprintVersion
    {
        return NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Published->value)
            ->first();
    }

    /** The draft, created as a copy of the published version when there is none yet. */
    public function draftFor(NicheBlueprint $blueprint, int $actorUserId): NicheBlueprintVersion
    {
        return $this->existingDraft($blueprint)
            ?? $this->publisher->createDraftFromPublished($actorUserId, $blueprint);
    }

    /**
     * @return array<string, list<array{component: NicheBlueprintComponent, definition: BlueprintComponentDefinition, summary: string}>>
     */
    public function bySurface(NicheBlueprintVersion $version): array
    {
        $out = array_fill_keys(BlueprintSurfaces::keys(), []);
        $definitions = $this->definitions();

        foreach ($version->components()->get() as $component) {
            $definition = $definitions[$component->component_type] ?? null;

            if ($definition === null) {
                continue;
            }

            $out[$definition->surface()][] = [
                'component' => $component,
                'definition' => $definition,
                'summary' => $definition->summary(is_array($component->payload) ? $component->payload : []),
            ];
        }

        return $out;
    }

    /**
     * Creates (no $component) or updates one component from submitted form
     * input. Input that cannot form a valid descriptor is refused with an
     * InvalidArgumentException before anything is written.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveComponent(
        int $actorUserId,
        NicheBlueprintVersion $version,
        string $type,
        ?NicheBlueprintComponent $component,
        array $input,
    ): NicheBlueprintComponent {
        $definition = $this->definitionFor($type);
        $payload = $definition->payloadFromInput($input);
        BlueprintDataBoundary::assertClean($payload);

        // Same gate the publisher applies — a Workspace save is refused where the
        // descriptor is defined, not at publish time.
        $this->adapters->adapterFor($type)->validateDescriptor($payload);

        if ($component !== null) {
            if ($component->component_type !== $type) {
                throw new InvalidArgumentException('A component cannot change its type.');
            }

            return $this->publisher->updateDraftComponent($actorUserId, $component, ['payload' => $payload]);
        }

        $ordinal = (int) NicheBlueprintComponent::query()->where('blueprint_version_id', $version->id)->count() + 1;

        return $this->publisher->addDraftComponent(
            $actorUserId,
            $version,
            Str::limit($type, 50, '').'_'.Str::lower(Str::random(8)),
            $type,
            $definition->featureKey(),
            $payload,
            BlueprintSurfaces::positionFor($definition->surface(), $ordinal),
        );
    }

    public function removeComponent(int $actorUserId, NicheBlueprintComponent $component): void
    {
        $this->publisher->removeDraftComponent($actorUserId, $component);
    }

    public function saveDraftNotes(int $actorUserId, NicheBlueprintVersion $version, ?string $notes): NicheBlueprintVersion
    {
        return $this->publisher->updateDraftNotes($actorUserId, $version, $notes !== null && trim($notes) !== '' ? trim($notes) : null);
    }

    /**
     * The niche page's configuration status. Reads the draft when there is one
     * (what the owner is about to publish), else the published version.
     *
     * @return array{source: string, version: ?NicheBlueprintVersion, surfaces: array<string, array{label: string, count: int, lines: list<string>}>}
     */
    public function configurationStatus(NicheBlueprint $blueprint): array
    {
        $draft = $this->existingDraft($blueprint);
        $version = $draft ?? $this->published($blueprint);
        $surfaces = [];

        foreach (BlueprintSurfaces::ALL as $key => $meta) {
            $surfaces[$key] = ['label' => $meta['label'], 'count' => 0, 'lines' => []];
        }

        if ($version !== null) {
            foreach ($this->bySurface($version) as $surface => $rows) {
                $surfaces[$surface]['count'] = count($rows);
                $surfaces[$surface]['lines'] = array_map(fn (array $row) => $row['summary'], $rows);
            }
        }

        return [
            'source' => $draft !== null ? 'draft' : ($version !== null ? 'published' : 'none'),
            'version' => $version,
            'surfaces' => $surfaces,
        ];
    }

    /** Distinct Businesses holding at least one installed component of this Blueprint. */
    public function businessesUsing(NicheBlueprint $blueprint): int
    {
        return (int) BusinessBlueprintComponentInstallation::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', BlueprintComponentInstallationState::Installed->value)
            ->distinct()
            ->count('business_id');
    }

    /**
     * Per-Business provisioning rows for the niche page.
     *
     * @return list<array{business: Business, version: int, updates: int}>
     */
    public function businessRows(NicheBlueprint $blueprint, BlueprintUpdateDetector $detector, int $limit = 25): array
    {
        $ids = BusinessBlueprintComponentInstallation::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', BlueprintComponentInstallationState::Installed->value)
            ->distinct()->orderBy('business_id')->limit($limit)->pluck('business_id');

        $rows = [];

        foreach (Business::query()->whereIn('id', $ids)->orderBy('id')->get() as $business) {
            $report = $detector->forBusiness($business);
            $rows[] = ['business' => $business, 'version' => (int) $report['provisioned_version'], 'updates' => $report['updates_available']];
        }

        return $rows;
    }
}
