<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Enums\Seo\SeoDirectoryImportance;
use App\Library\Seo\SeoCitationCatalogManager;
use App\Models\NicheBlueprint;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;
use InvalidArgumentException;

/**
 * Blueprint V2 — the niche's recommended citation directories. LIVE.
 *
 * The Business-facing read already exists and is live
 * (`GrowthCitationFactReader` reads `seo_niche_citation_recommendations` by the
 * Business's industry), so this component installs nothing per Business.
 * Instead, publishing a version SYNCS this component into that table through
 * the canonical `SeoCitationCatalogManager::syncRecommendation`: a missing row
 * is created, and an existing one is left as the Platform Owner set it (a
 * disabled recommendation stays disabled; importance / guidance change only
 * while they still match what the previous version declared). A directory the
 * previous version recommended and this one no longer lists is removed.
 * The sync key is the Blueprint's `broad_industry` (the same niche key the
 * reader uses), so a Blueprint without one cannot publish this component.
 */
final class CitationRecommendationsComponentAdapter extends ConfigOnlyBlueprintComponentAdapter implements PublishesLiveBlueprintComponent
{
    public const TYPE = 'citation_recommendations';

    public function __construct(private readonly SeoCitationCatalogManager $catalog) {}

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $this->parse($payload);
    }

    public function onPublished(NicheBlueprint $blueprint, array $payload, ?array $previousPayload, int $actorUserId): void
    {
        $nicheKey = (string) $blueprint->broad_industry;

        if ($nicheKey === '') {
            throw new InvalidArgumentException('Citation recommendations sync by niche, so the Blueprint needs a broad industry.');
        }

        $current = $this->parse($payload);
        $currentKeys = array_column($current, 'directory_key');
        $previous = $previousPayload === null ? [] : $this->parse($previousPayload);
        $previousByKey = array_column($previous, null, 'directory_key');

        foreach ($current as $rec) {
            $directory = $this->directory($rec['directory_key']);
            $before = $previousByKey[$rec['directory_key']] ?? null;

            // Creates a missing recommendation; never reverts an owner's edit or re-enables one they disabled.
            $this->catalog->syncRecommendation(
                $actorUserId,
                $nicheKey,
                (string) $directory->uid,
                $rec['importance'],
                $rec['guidance'],
                $before === null ? null : ['importance' => $before['importance'], 'guidance' => $before['guidance']],
            );
        }

        if ($previousPayload !== null) {
            foreach ($previous as $old) {
                if (in_array($old['directory_key'], $currentKeys, true)) {
                    continue;
                }

                $directory = SeoCitationDirectory::query()->where('key', $old['directory_key'])->whereNull('business_id')->first();
                $row = $directory === null ? null : SeoNicheCitationRecommendation::query()
                    ->where('niche_key', $nicheKey)->where('seo_citation_directory_id', $directory->id)->first();

                if ($row !== null) {
                    $this->catalog->removeRecommendation($actorUserId, (string) $row->uid);
                }
            }
        }
    }

    private function directory(string $key): SeoCitationDirectory
    {
        return SeoCitationDirectory::query()->where('key', $key)->whereNull('business_id')->first()
            ?? throw new InvalidArgumentException("Citation directory [{$key}] does not exist in the platform catalog.");
    }

    /** @return list<array{directory_key: string, importance: string, guidance: ?string}> */
    private function parse(array $payload): array
    {
        $recs = $payload['recommendations'] ?? null;

        if (! is_array($recs) || ! array_is_list($recs) || $recs === []) {
            throw new InvalidArgumentException('Add at least one citation recommendation.');
        }

        $out = [];
        $seen = [];

        foreach ($recs as $rec) {
            $rec = (array) $rec;
            $key = $this->requireString($rec, 'directory_key', 64);
            $importance = (string) ($rec['importance'] ?? 'recommended');

            if (SeoDirectoryImportance::tryFrom($importance) === null) {
                throw new InvalidArgumentException("Directory [{$key}]: importance must be essential, recommended or optional.");
            }

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Directory [{$key}] is listed twice.");
            }

            $seen[$key] = true;
            $this->directory($key);
            $out[] = ['directory_key' => $key, 'importance' => $importance, 'guidance' => $this->optionalString($rec, 'guidance', 500)];
        }

        return $out;
    }

    public function surface(): string
    {
        return 'citations';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Live;
    }

    public function featureKey(): string
    {
        return 'seo_module';
    }

    public function typeLabel(): string
    {
        return 'Citation recommendations';
    }

    public function summary(array $payload): string
    {
        return count($payload['recommendations'] ?? []).' recommendations';
    }

    public function formFields(): array
    {
        $directories = SeoCitationDirectory::query()->whereNull('business_id')->orderBy('sort_order')->pluck('name', 'key')->all();

        return [
            ['name' => 'recommendations', 'label' => 'Recommended directories', 'type' => 'lines', 'required' => true,
                'help' => 'One per line: directory key | essential/recommended/optional | guidance. Available keys: '.implode(', ', array_keys($directories))],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $recs = [];

        foreach ($this->linesOf($input['recommendations'] ?? '') as $line) {
            [$key, $importance, $guidance] = $this->pipeParts($line, 3);
            $rec = ['directory_key' => (string) $key, 'importance' => strtolower((string) ($importance ?: 'recommended'))];

            if ($guidance !== null && $guidance !== '') {
                $rec['guidance'] = $guidance;
            }

            $recs[] = $rec;
        }

        $payload = ['recommendations' => $recs];
        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        return ['recommendations' => implode("\n", array_map(
            fn ($r) => $r['directory_key'].' | '.$r['importance'].(! empty($r['guidance']) ? ' | '.$r['guidance'] : ''),
            $payload['recommendations'] ?? [],
        ))];
    }
}
