<?php

namespace App\Console\Commands;

use App\Enums\Business\BusinessIndustry;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Library\NicheBlueprint\PhotoBoothBlueprintV2;
use App\Library\NicheBlueprint\Workspace\BlueprintWorkspaceService;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Publishes the realistic Photo Booth Blueprint v2 (see PhotoBoothBlueprintV2):
 * a copy of the published version plus every component it does not yet carry.
 * Idempotent — a Blueprint whose published version already carries every seed
 * component key does nothing, and an existing draft is resumed, never replaced.
 *
 * Run `blueprint:seed-photo-booth` and `documents:seed-photo-booth-templates`
 * first (v1 pipeline; platform proposal templates). Configuration only — no
 * customer data is created or copied.
 */
class SeedPhotoBoothBlueprintV2Command extends Command
{
    protected $signature = 'blueprint:seed-photo-booth-v2
        {--actor= : Platform administrator user id to publish as; defaults to the first admin user found}
        {--draft-only : Leave the result as a draft instead of publishing}';

    protected $description = 'Add the Photo Booth Blueprint v2 configuration (CRM, forms, automations, website, SEO, citations, calendar, documents, packages) and publish it.';

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

        if ($blueprint->broad_industry === null) {
            $publisher->updateBlueprintIdentity($actor, $blueprint, ['broad_industry' => BusinessIndustry::PhotoBoothService->value]);
        }

        $hadDraft = $workspace->existingDraft($blueprint) !== null;
        $draft = $workspace->draftFor($blueprint, $actor);
        $existing = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->pluck('component_key')->all();
        $added = 0;

        foreach (PhotoBoothBlueprintV2::components() as $i => $component) {
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
                PhotoBoothBlueprintV2::positionFor($component['type'], $i + 1),
            );
            $added++;
        }

        $added += $this->applyProposalPaymentTerms($publisher, $actor, $draft);

        if ($this->option('draft-only')) {
            $this->info("Draft v{$draft->version_number} holds the Photo Booth v2 configuration ({$added} added).");

            return self::SUCCESS;
        }

        if ($added === 0 && ! $hadDraft) {
            $publisher->deleteDraftVersion($actor, $draft);
            $this->info('Nothing to do: the published version already carries every Photo Booth v2 component.');

            return self::SUCCESS;
        }

        $published = $publisher->publishVersion($actor, $draft);
        $this->info("Published photo_booth Blueprint version {$published->version_number} ({$added} components added).");

        return self::SUCCESS;
    }
    private function applyProposalPaymentTerms(NicheBlueprintPublisher $publisher, int $actor, \App\Models\NicheBlueprintVersion $draft): int
    {
        $uid = \App\Models\DocumentTemplate::query()->where('seed_key', 'photo_booth_proposal')->whereNull('business_id')->value('uid');
        $changed = 0;

        foreach (NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->where('component_type', 'document_template')->get() as $component) {
            $payload = is_array($component->payload) ? $component->payload : [];

            if ($uid !== null && ($payload['template_uid'] ?? null) === (string) $uid && empty($payload['payment_terms'])) {
                $payload['payment_terms'] = PhotoBoothBlueprintV2::proposalPaymentTerms();
                $publisher->updateDraftComponent($actor, $component, ['payload' => $payload]);
                $changed++;
            }
        }

        return $changed;
    }
}
