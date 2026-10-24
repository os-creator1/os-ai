<?php

namespace App\Console\Commands;

use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Library\NicheBlueprint\PhotoBoothBlueprintV2;
use App\Library\NicheBlueprint\PhotoBoothBusinessPackV3;
use App\Library\NicheBlueprint\Workspace\BlueprintWorkspaceService;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Adds the Photo Booth Business Pack v3 components to the photo_booth Blueprint
 * and publishes it. Additive by component key: an existing key is never
 * rewritten, so re-running (or a later pack version) cannot overwrite what a
 * platform administrator edited in the Blueprint Workspace.
 */
class SeedPhotoBoothBusinessPackV3Command extends Command
{
    protected $signature = 'blueprint:seed-photo-booth-v3
        {--actor= : Platform administrator user id to publish as; defaults to the first admin user found}
        {--draft-only : Leave the result as a draft instead of publishing}
        {--consolidate : First drop, from the NEW DRAFT only, the earlier-version components Business Pack V3 supersedes (overlapping form, inquiry/booking/review automations, Sales pipeline, single-select Event fields)}';

    protected $description = 'Add the Photo Booth Business Pack v3 (Inquiries/Bookings pipelines, fields, tags, forms, 10+ draft workflows) and publish it.';

    public function handle(NicheBlueprintPublisher $publisher, BlueprintWorkspaceService $workspace): int
    {
        $blueprint = NicheBlueprint::query()->where('key', 'photo_booth')->first();

        if ($blueprint === null) {
            $this->error('The photo_booth Blueprint does not exist. Run blueprint:seed-photo-booth first.');

            return self::FAILURE;
        }

        $actor = $this->option('actor') !== null ? (int) $this->option('actor') : (int) User::query()->where('is_admin', true)->value('id');

        if ($actor < 1) {
            throw new RuntimeException('No platform administrator exists to publish as. Pass --actor=<user id>.');
        }

        $hadDraft = $workspace->existingDraft($blueprint) !== null;
        $draft = $workspace->draftFor($blueprint, $actor);
        $existing = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->pluck('component_key')->all();
        $added = 0;
        $removed = [];

        if ($this->option('consolidate')) {
            // Drafts only: removeDraftComponent refuses a published version, and the published rows are never touched.
            foreach (NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->whereIn('component_key', PhotoBoothBusinessPackV3::SUPERSEDED_EARLIER_KEYS)->get() as $superseded) {
                $publisher->removeDraftComponent($actor, $superseded);
                $removed[] = $superseded->component_key;
            }
            $existing = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->pluck('component_key')->all();
            if ($removed !== []) {
                $this->line('Dropped from the draft: '.implode(', ', $removed));
            }
        }

        // Pipelines precede the stage-triggered workflows that resolve their stages.
        foreach (PhotoBoothBusinessPackV3::components() as $i => $component) {
            if (in_array($component['key'], $existing, true)) {
                continue;
            }

            $publisher->addDraftComponent(
                $actor, $draft, $component['key'], $component['type'], $component['feature'], $component['payload'],
                PhotoBoothBlueprintV2::positionFor($component['type'], $i + 1),
            );
            $added++;
        }

        if ($this->option('draft-only')) {
            $this->info("Draft v{$draft->version_number} holds Business Pack ".PhotoBoothBusinessPackV3::VERSION." ({$added} added).");

            return self::SUCCESS;
        }

        if ($added === 0 && $removed === [] && ! $hadDraft) {
            $publisher->deleteDraftVersion($actor, $draft);
            $this->info('Nothing to do: the published version already carries every Business Pack v3 component.');

            return self::SUCCESS;
        }

        $published = $publisher->publishVersion($actor, $draft);
        $this->info("Published photo_booth Blueprint version {$published->version_number} (Business Pack ".PhotoBoothBusinessPackV3::VERSION.", {$added} components added).");

        return self::SUCCESS;
    }
}
