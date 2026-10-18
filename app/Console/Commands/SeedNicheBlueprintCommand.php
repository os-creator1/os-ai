<?php

namespace App\Console\Commands;

use App\Library\NicheBlueprint\Niches\KidsCeramicsBlueprint;
use App\Library\NicheBlueprint\Niches\TutoringBlueprint;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Library\NicheBlueprint\Workspace\BlueprintWorkspaceService;
use App\Models\BusinessVertical;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Creates and publishes one of the education/kids niche Blueprints through the
 * ONE existing authoring seam (NicheBlueprintPublisher + the Workspace draft
 * service) — no second niche framework.
 *
 * VERTICAL-BOUND, never a new industry enum case. Each niche is a row of the operator-controlled
 * `business_verticals` catalog (Knowledge Profile contract §6: the BusinessIndustry set is locked), and the
 * Blueprint is bound to it by `vertical_key`; a Business receives the Blueprint when its Knowledge Profile's
 * vertical is that key. This command ensures the catalog row exists (created active, an existing row's name and
 * activity are left as the operator set them).
 *
 * Idempotent: a published version that already carries every seeded component
 * key does nothing; an existing draft is resumed, never replaced; components a
 * newer seed added are appended to a copy of the published version, so a
 * Business that installed an older version keeps its snapshot.
 *
 * Configuration only. No customer data and no money value is created.
 */
class SeedNicheBlueprintCommand extends Command
{
    /** niche => [definition class, display name, broad industry] */
    private const NICHES = [
        TutoringBlueprint::KEY => [TutoringBlueprint::class, TutoringBlueprint::NAME, TutoringBlueprint::BROAD_INDUSTRY],
        KidsCeramicsBlueprint::KEY => [KidsCeramicsBlueprint::class, KidsCeramicsBlueprint::NAME, KidsCeramicsBlueprint::BROAD_INDUSTRY],
    ];

    protected $signature = 'blueprint:seed-niche
        {niche : tutoring_exam_prep or kids_ceramics}
        {--actor= : Platform administrator user id to publish as; defaults to the first admin user found}
        {--draft-only : Leave the result as a draft instead of publishing}';

    protected $description = 'Create and publish the Tutoring & Exam Preparation or Kids Ceramics niche Blueprint (vertical-bound).';

    public function handle(NicheBlueprintPublisher $publisher, BlueprintWorkspaceService $workspace): int
    {
        $niche = (string) $this->argument('niche');

        if (! isset(self::NICHES[$niche])) {
            $this->error('Unknown niche. Use one of: ' . implode(', ', array_keys(self::NICHES)) . '.');

            return self::FAILURE;
        }

        [$definition, $displayName, $industry] = self::NICHES[$niche];

        $actor = $this->option('actor') !== null ? (int) $this->option('actor') : (int) User::query()->where('is_admin', true)->value('id');

        if ($actor < 1) {
            throw new RuntimeException('No platform administrator exists to publish as. Pass --actor=<user id>.');
        }

        BusinessVertical::query()->firstOrCreate(
            ['key' => $niche],
            ['display_name' => $displayName, 'broad_industry' => $industry, 'is_active' => true],
        );

        $blueprint = NicheBlueprint::query()->where('key', $niche)->first()
            ?? $publisher->createBlueprint($actor, $niche, $displayName, $niche, $industry);

        $hadDraft = $workspace->existingDraft($blueprint) !== null;
        $draft = $workspace->draftFor($blueprint, $actor);
        $existing = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->pluck('component_key')->all();
        $added = 0;

        foreach ($definition::components() as $i => $component) {
            if (in_array($component['key'], $existing, true)) {
                continue;
            }

            $publisher->addDraftComponent(
                $actor,
                $draft,
                $component['key'],
                $component['type'],
                $component['feature'],
                $component['payload'],
                $definition::positionFor($component['type'], $i + 1),
            );
            $added++;
        }

        if ($this->option('draft-only')) {
            $this->info("Draft v{$draft->version_number} of {$niche} holds {$added} new components.");

            return self::SUCCESS;
        }

        if ($added === 0 && ! $hadDraft && $draft->version_number > 1) {
            $publisher->deleteDraftVersion($actor, $draft);
            $this->info("Nothing to do: the published {$niche} version already carries every seed component.");

            return self::SUCCESS;
        }

        $published = $publisher->publishVersion($actor, $draft);
        $this->info("Published {$niche} Blueprint version {$published->version_number} ({$added} components added).");

        return self::SUCCESS;
    }
}
