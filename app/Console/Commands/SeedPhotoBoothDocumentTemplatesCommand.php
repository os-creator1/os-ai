<?php

namespace App\Console\Commands;

use App\Enums\Documents\DocumentTemplateStatus;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Exceptions\NicheBlueprint\BlueprintAuthoringException;
use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\Templates\PhotoBoothPlatformTemplates;
use App\Library\Documents\Templates\PlatformTemplateNicheAssignments;
use App\Library\NicheBlueprint\Adapters\DocumentTemplateComponentAdapter;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\DocumentTemplate;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Implementation Contract 17B §6b - seeds the four Photo Booth platform
 * templates and assigns them to the `photo_booth` niche blueprint.
 *
 * IDEMPOTENT AND RESUMABLE, like `blueprint:seed-photo-booth`:
 *
 *   1. TEMPLATES. Each of the four is created ONCE, keyed by its immutable
 *      `seed_key` (status `active`, `business_id` NULL). An existing row is left
 *      exactly as it is - so a rename, an edit or a "Disable" by the Platform
 *      Owner is never undone by re-running the command.
 *   2. ASSIGNMENT. The published `photo_booth` version is read; any of the four
 *      it does not yet carry are added to the blueprint's draft and that draft
 *      is published, producing ONE new version holding the existing components
 *      (the CRM pipeline) plus the four `document_template` components. When all
 *      four are already in the published version nothing is written. An
 *      interrupted run resumes its own draft; a draft that holds anything this
 *      seed did not put there (an operator's work) is NOT touched and the command
 *      fails closed.
 *
 * Every blueprint write goes through NicheBlueprintPublisher (via
 * PlatformTemplateNicheAssignments). `blueprint:seed-photo-booth` is not changed
 * and must have published v1 first.
 */
class SeedPhotoBoothDocumentTemplatesCommand extends Command
{
    protected $signature = 'documents:seed-photo-booth-templates
        {--actor= : Platform administrator user id to publish as; defaults to the first admin user found}';

    protected $description = 'Create the four Photo Booth platform proposal/contract templates and assign them to the photo_booth niche blueprint (17B §6b). Idempotent.';

    public function handle(PlatformTemplateNicheAssignments $assignments, NicheBlueprintPublisher $publisher): int
    {
        $blueprint = NicheBlueprint::query()->where('key', PhotoBoothPlatformTemplates::BLUEPRINT_KEY)->first();
        $published = $blueprint === null ? null : NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Published->value)
            ->first();

        if ($blueprint === null || $published === null) {
            $this->error('The photo_booth blueprint has no published version. Run "php artisan blueprint:seed-photo-booth" first.');

            return self::FAILURE;
        }

        try {
            $definitions = PhotoBoothPlatformTemplates::normalized();
        } catch (\Throwable $e) {
            $this->error('The shipped Photo Booth templates failed validation: '.$e->getMessage());

            return self::FAILURE;
        }

        $actor = $this->resolveActor();

        // 1. Templates (create-only).
        $created = 0;
        $templates = [];

        foreach ($definitions as $definition) {
            $template = DocumentTemplate::query()->where('seed_key', $definition['seed_key'])->first();

            if ($template !== null && $template->business_id !== null) {
                $this->error('A Business-owned template carries the platform seed key "'.$definition['seed_key'].'"; refusing to continue.');

                return self::FAILURE;
            }

            if ($template === null) {
                $template = new DocumentTemplate([
                    'business_id' => null,
                    'template_type' => $definition['type'],
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'blocks' => $definition['blocks'],
                    'schema_version' => BlockSchema::SCHEMA_VERSION,
                    'created_by_user_id' => $actor->id,
                ]);
                $template->forceFill(['seed_key' => $definition['seed_key'], 'status' => DocumentTemplateStatus::Active->value]);
                $template->save();
                $created++;
            }

            $templates[] = $template;
        }

        $this->info($created === 0 ? 'The four Photo Booth templates already exist.' : "Created {$created} Photo Booth template(s).");

        // 2. Assignment.
        $uids = collect($templates)->pluck('uid')->all();
        $live = $this->templateUids($published);
        $missing = array_values(array_diff($uids, $live));

        if ($missing === []) {
            $this->info('All four templates are already in the published photo_booth blueprint version; nothing to publish.');

            return self::SUCCESS;
        }

        $draft = NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Draft->value)
            ->first();

        if ($draft !== null && ! $this->draftIsOurs($draft, $published, $uids)) {
            $this->error(
                'The photo_booth blueprint has a draft (v'.$draft->version_number.') that holds changes this seed did not make. '
                .'It looks like operator-authored work, so nothing was changed. Publish or discard that draft on the Niche Blueprints '
                .'screen, then re-run this command.'
            );

            return self::FAILURE;
        }

        try {
            if ($draft !== null) {
                $this->completeDraftCopy($publisher, $actor, $draft, $published);
            }

            foreach ($templates as $template) {
                if (in_array($template->uid, $missing, true)) {
                    $assignments->assign($actor, $template, $blueprint);
                }
            }

            $version = $assignments->publishDraft($actor, $blueprint);
        } catch (BlueprintAuthoringException $e) {
            $this->error('Could not publish the photo_booth blueprint: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Published photo_booth blueprint version '.$version->version_number.' with '.count($uids).' document templates.');

        return self::SUCCESS;
    }

    /**
     * A run interrupted while ensureDraft() was copying the published components leaves a partial copy; finish it so
     * publishing the draft never drops a component that is live today.
     */
    private function completeDraftCopy(NicheBlueprintPublisher $publisher, User $actor, NicheBlueprintVersion $draft, NicheBlueprintVersion $published): void
    {
        $have = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->pluck('component_key')->all();

        NicheBlueprintComponent::query()
            ->where('blueprint_version_id', $published->id)
            ->orderBy('position')->orderBy('id')
            ->get()
            ->reject(fn (NicheBlueprintComponent $component) => in_array($component->component_key, $have, true))
            ->each(fn (NicheBlueprintComponent $component) => $publisher->addDraftComponent(
                (int) $actor->id,
                $draft,
                (string) $component->component_key,
                (string) $component->component_type,
                (string) $component->required_feature_key,
                is_array($component->payload) ? $component->payload : [],
                (int) $component->position,
            ));
    }

    /** @return list<string> the platform template uids a version carries */
    private function templateUids(NicheBlueprintVersion $version): array
    {
        return NicheBlueprintComponent::query()
            ->where('blueprint_version_id', $version->id)
            ->where('component_type', DocumentTemplateComponentAdapter::TYPE)
            ->get(['payload'])
            ->map(fn (NicheBlueprintComponent $component) => $component->payload['template_uid'] ?? null)
            ->filter(fn ($uid) => is_string($uid))
            ->values()
            ->all();
    }

    /**
     * A draft is "ours" (safe to resume) only when every component is either
     * identical to a published one (the copy ensureDraft makes) or one of the four
     * seed templates' own components.
     *
     * @param  list<string>  $seedUids
     */
    private function draftIsOurs(NicheBlueprintVersion $draft, NicheBlueprintVersion $published, array $seedUids): bool
    {
        $signature = fn (NicheBlueprintComponent $c) => json_encode([$c->component_key, $c->component_type, $c->required_feature_key, $c->payload]);

        $publishedSignatures = NicheBlueprintComponent::query()->where('blueprint_version_id', $published->id)->get()
            ->map($signature)->all();

        /** @var Collection<int, NicheBlueprintComponent> $components */
        $components = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->get();

        foreach ($components as $component) {
            $isCopy = in_array($signature($component), $publishedSignatures, true);
            $isSeed = $component->component_type === DocumentTemplateComponentAdapter::TYPE
                && in_array($component->payload['template_uid'] ?? null, $seedUids, true);

            if (! $isCopy && ! $isSeed) {
                return false;
            }
        }

        return true;
    }

    private function resolveActor(): User
    {
        $option = $this->option('actor');
        $user = $option !== null
            ? User::query()->whereKey((int) $option)->first()
            : User::query()->where('is_admin', true)->orderBy('id')->first();

        if ($user === null) {
            throw new RuntimeException('No platform administrator user exists to publish as. Pass --actor=<user id>.');
        }

        return $user;
    }
}
