<?php

namespace App\Console\Commands;

use App\Enums\Business\BusinessIndustry;
use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Contract 20 §5.5/§12.D — seeds and publishes the platform's Photo Booth
 * Blueprint v1: the ONE component this sub-slice ships, the default CRM
 * sales pipeline in §9's exact stage sequence.
 *
 * IDEMPOTENT, SAFE TO RUN REPEATEDLY — INCLUDING AFTER AN INTERRUPTED RUN.
 * If `photo_booth` already has a published version, this is a no-op success
 * (§5.2's authoring-content immutability: it never edits an issued version
 * and never publishes a v2). If a prior run crashed between creating the
 * draft and publishing it, this run RESUMES that same draft rather than
 * failing on `DraftVersionAlreadyExistsException` — but only when the
 * draft's own content is provably OUR partial seed and nothing an operator
 * might have authored:
 *
 *   - an empty draft (nothing added yet) → the expected component is added,
 *     then the draft is published;
 *   - a draft holding EXACTLY the expected `photo_booth_default_pipeline`
 *     component, byte-for-byte → published as-is, without adding a
 *     duplicate;
 *   - anything else (an extra component, a different component_key, a
 *     matching key with a different type/feature/payload, or version
 *     history proving this draft would not become v1) is NOT resumed. The
 *     command fails closed with an explanation and touches nothing — no
 *     mutation, no deletion, no publish — because that shape can only come
 *     from real operator authoring, which this seed has no authority over.
 *
 * ALL WRITES GO THROUGH NicheBlueprintPublisher — createBlueprint(),
 * createDraftVersion(), addDraftComponent(), publishVersion(). This command
 * never writes a NicheBlueprintVersion/NicheBlueprintComponent row directly;
 * recovery only ever READS the existing draft to decide whether resuming it
 * is safe.
 *
 * RESOLVES VIA `broad_industry`, NOT `vertical_key`. `business_verticals`
 * ships with no seeded rows, so a Blueprint bound to a `vertical_key` could
 * never resolve for any Business (§7.1 step 1) until an operator seeds one.
 * `broad_industry = 'photo_booth_service'` (§7.1 step 2) matches
 * `BusinessIndustry::PhotoBoothService` and every fixture/default Business
 * industry value already in use, so this is the resolvable shape §5.5 asks
 * for today.
 *
 * Per `CLAUDE.md`'s route-3 rules this command is never run against anything
 * but a `TestDatabaseSafety`-approved database during development.
 */
class SeedPhotoBoothBlueprintCommand extends Command
{
    private const BLUEPRINT_KEY = 'photo_booth';

    private const COMPONENT_KEY = 'photo_booth_default_pipeline';

    private const REQUIRED_FEATURE_KEY = 'crm';

    protected $signature = 'blueprint:seed-photo-booth
        {--actor= : Platform administrator user id to publish as; defaults to the first admin user found}';

    protected $description = 'Create and publish the Photo Booth niche Blueprint v1 (Contract 20 §5.5), if not already published.';

    public function handle(NicheBlueprintPublisher $publisher): int
    {
        $blueprint = NicheBlueprint::query()->where('key', self::BLUEPRINT_KEY)->first();

        if ($blueprint !== null && $this->hasPublishedVersion($blueprint)) {
            $this->info('The photo_booth Blueprint already has a published version; nothing to do.');

            return self::SUCCESS;
        }

        $actorUserId = $this->resolveActorUserId();

        if ($blueprint === null) {
            $blueprint = $publisher->createBlueprint(
                $actorUserId,
                self::BLUEPRINT_KEY,
                'Photo Booth',
                null,
                BusinessIndustry::PhotoBoothService->value,
            );
        }

        $draft = $this->resumeOrCreateDraft($publisher, $blueprint, $actorUserId);

        if ($draft === null) {
            return self::FAILURE;
        }

        $components = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->get();

        if ($components->isEmpty()) {
            $publisher->addDraftComponent(
                $actorUserId,
                $draft,
                self::COMPONENT_KEY,
                CrmPipelineComponentAdapter::TYPE,
                self::REQUIRED_FEATURE_KEY,
                $this->componentPayload(),
            );
        } elseif (! $this->draftHasExactlyTheExpectedComponent($components)) {
            $this->error(
                'The photo_booth Blueprint draft v'.$draft->version_number.' does not contain exactly the '
                .'expected "'.self::COMPONENT_KEY.'" seed component (unexpected key, type, feature, payload, '
                .'or an extra component). This looks like operator-authored draft content, not an interrupted '
                .'seed run, so it is left untouched. Resolve manually — fix or remove the conflicting '
                .'component(s) through the Blueprint authoring path, or delete the draft — then re-run this '
                .'command.'
            );

            return self::FAILURE;
        }

        $published = $publisher->publishVersion($actorUserId, $draft);

        $this->info("Published photo_booth Blueprint version {$published->version_number}.");

        return self::SUCCESS;
    }

    /**
     * Finds a draft this run may safely continue, or creates a fresh one.
     *
     * VERSION DISCIPLINE. This command seeds Photo Booth v1 and nothing
     * else. A draft is only ever resumed or created here when the
     * Blueprint's version history proves it would become v1 — an empty
     * version history, or a single existing draft already numbered 1. Any
     * other shape (a superseded version already exists, or the existing
     * draft is not v1) means real version history exists that this seed did
     * not create, so it fails closed rather than risk constructing a v2.
     *
     * Returns null on a fail-closed refusal; the caller returns
     * self::FAILURE without having written anything.
     */
    private function resumeOrCreateDraft(NicheBlueprintPublisher $publisher, NicheBlueprint $blueprint, int $actorUserId): ?NicheBlueprintVersion
    {
        $versions = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->get();
        $draft = $versions->first(fn (NicheBlueprintVersion $version) => $version->state === NicheBlueprintVersionState::Draft);
        $otherVersionCount = $versions->count() - ($draft !== null ? 1 : 0);

        if ($draft === null) {
            if ($otherVersionCount > 0) {
                $this->error(
                    'The photo_booth Blueprint already has version history (published and/or superseded) with no '
                    .'current draft. Creating a new draft here would not become v1, and this command only seeds '
                    .'v1. This looks like operator-authored state, not an interrupted seed run, so nothing was '
                    .'created. Resolve manually.'
                );

                return null;
            }

            return $publisher->createDraftVersion($actorUserId, $blueprint);
        }

        if ((int) $draft->version_number !== 1 || $otherVersionCount > 0) {
            $this->error(
                'The photo_booth Blueprint has an existing draft that is not version 1 (or other version history '
                .'exists alongside it), so publishing it would not produce the expected Photo Booth v1. This '
                .'looks like operator-authored state, not an interrupted seed run, so the draft is left '
                .'untouched. Resolve manually.'
            );

            return null;
        }

        return $draft;
    }

    /**
     * @param  Collection<int, NicheBlueprintComponent>  $components
     */
    private function draftHasExactlyTheExpectedComponent(Collection $components): bool
    {
        if ($components->count() !== 1) {
            return false;
        }

        $component = $components->first();

        return $component->component_key === self::COMPONENT_KEY
            && $component->component_type === CrmPipelineComponentAdapter::TYPE
            && $component->required_feature_key === self::REQUIRED_FEATURE_KEY
            && is_array($component->payload)
            && $component->payload == $this->componentPayload();
    }

    private function hasPublishedVersion(NicheBlueprint $blueprint): bool
    {
        return NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Published->value)
            ->exists();
    }

    private function resolveActorUserId(): int
    {
        $option = $this->option('actor');

        if ($option !== null) {
            return (int) $option;
        }

        $adminId = User::query()->where('is_admin', true)->value('id');

        if ($adminId === null) {
            throw new RuntimeException(
                'No platform administrator user exists to publish the photo_booth Blueprint as. Pass --actor=<user id>.'
            );
        }

        return (int) $adminId;
    }

    /**
     * Contract 20 §5.5's exact component descriptor: Blueprint §9's default
     * pipeline, "New Lead" over the canonical `new_inquiry` semantic key.
     *
     * @return array<string, mixed>
     */
    private function componentPayload(): array
    {
        return [
            'template_key' => self::BLUEPRINT_KEY,
            'template_version' => 1,
            'pipeline_key' => 'sales',
            'name' => 'Sales pipeline',
            'stages' => [
                ['name' => 'New Lead', 'semantic_key' => 'new_inquiry'],
                ['name' => 'Auto Follow-Up', 'semantic_key' => 'auto_follow_up'],
                ['name' => 'In Contact', 'semantic_key' => 'in_contact'],
                ['name' => 'Proposal Sent', 'semantic_key' => 'proposal_sent'],
                ['name' => 'Invoice Sent', 'semantic_key' => 'invoice_sent'],
                ['name' => 'Questionnaire Sent', 'semantic_key' => 'questionnaire_sent'],
                ['name' => 'Questionnaire Submitted', 'semantic_key' => 'questionnaire_submitted'],
                ['name' => 'Done', 'semantic_key' => 'done'],
            ],
        ];
    }
}
