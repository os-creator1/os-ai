<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Library\Crm\TagManager;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\Business;
use InvalidArgumentException;

/**
 * Blueprint V2 — a set of CRM tags. Delegates to the canonical
 * `TagManager::createTag`; a tag the Business already has (same normalized
 * name) is adopted, never duplicated and never touched.
 */
final class TagSetComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent
{
    use InteractsWithBlueprintPayload;

    public const TYPE = 'crm_tag_set';

    public function __construct(private readonly TagManager $tags) {}

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $this->stringList($payload, 'tags', 50, 191);
    }

    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        $names = $this->stringList($payload, 'tags', 50, 191);

        $existing = $this->tags->tagsForBusiness($business, true)->keyBy(fn ($tag) => (string) $tag->normalized_name);
        $firstId = null;

        foreach ($names as $name) {
            $normalized = mb_strtolower(trim($name));
            $tag = $existing->get($normalized) ?? $this->tags->createTag($business, $name);
            $firstId ??= (int) $tag->id;
        }

        return new InstalledComponentReference('tag', (int) $firstId);
    }

    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string
    {
        $live = $this->tags->tagsForBusiness($business, false)->pluck('normalized_name')->all();
        $mine = array_values(array_filter(
            array_map(fn (string $n) => mb_strtolower(trim($n)), $this->stringList($payload, 'tags', 50, 191)),
            fn (string $n) => in_array($n, $live, true),
        ));
        sort($mine);

        return BlueprintChecksum::of($mine);
    }

    public function surface(): string
    {
        return 'crm';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Copy;
    }

    public function featureKey(): string
    {
        return 'crm';
    }

    public function typeLabel(): string
    {
        return 'Tag set';
    }

    public function summary(array $payload): string
    {
        $tags = $payload['tags'] ?? [];

        return count($tags).' tags: '.implode(', ', array_slice($tags, 0, 4)).(count($tags) > 4 ? '…' : '');
    }

    public function formFields(): array
    {
        return [
            ['name' => 'tags', 'label' => 'Tags', 'type' => 'lines', 'required' => true, 'help' => 'One tag per line.'],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $tags = $this->linesOf($input['tags'] ?? '');

        if ($tags === []) {
            throw new InvalidArgumentException('Add at least one tag.');
        }

        return ['tags' => $tags];
    }

    public function inputFromPayload(array $payload): array
    {
        return ['tags' => implode("\n", $payload['tags'] ?? [])];
    }
}
