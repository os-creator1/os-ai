<?php

namespace App\Library\Documents\Templates;

use App\Enums\NicheBlueprint\NicheBlueprintVersionState;
use App\Library\NicheBlueprint\Adapters\DocumentTemplateComponentAdapter;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\DocumentTemplate;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 17B §6b — "Assign to niches" for a platform template.
 *
 * NOT A SECOND NICHE CONFIGURATION SYSTEM. An assignment IS a
 * `document_template` component (`{template_uid}`) on a niche blueprint
 * version, and every write goes through NicheBlueprintPublisher — this class
 * never touches a `niche_blueprint_*` table itself (it only reads them).
 *
 * TWO STEPS, ON PURPOSE (published versions are immutable, contract 20 §5.2):
 *
 *   1. SAVE  — `apply()` records the Platform Owner's choice on the blueprint's
 *      single DRAFT version, creating that draft from the currently published
 *      version (its components are copied across, so publishing it later
 *      keeps everything that is live today) when none exists. Nothing is
 *      recommended to a Business yet: Recommended reads the PUBLISHED version
 *      only.
 *   2. PUBLISH — `publishDraft()` is a separate, explicit Platform Owner action
 *      (one per blueprint, with a confirmation in the UI) that publishes that
 *      draft through the publisher's fail-closed gate. A draft the owner
 *      authored on the Niche Blueprints screen is published whole (the UI says
 *      so), because a blueprint has exactly one draft and one published
 *      version.
 *
 * Disabling a template needs no blueprint change: Recommended filters on the
 * template's own status, so it disappears at once.
 */
final class PlatformTemplateNicheAssignments
{
    public function __construct(private readonly NicheBlueprintPublisher $publisher)
    {
    }

    /** The component key a template uses on a version (unique per template, at most 64 chars). */
    public static function componentKey(DocumentTemplate $template): string
    {
        return 'document_template_' . $template->uid;
    }

    /**
     * One row per niche blueprint, for the assignment screen.
     *
     * @return Collection<int, array{blueprint: NicheBlueprint, published: ?NicheBlueprintVersion, draft: ?NicheBlueprintVersion, live: bool, desired: bool, pending: bool, draft_components: int}>
     */
    public function overview(DocumentTemplate $template): Collection
    {
        $blueprints = NicheBlueprint::query()
            ->with(['versions' => fn ($query) => $query->whereIn('state', [
                NicheBlueprintVersionState::Draft->value,
                NicheBlueprintVersionState::Published->value,
            ])->withCount('components')])
            ->orderBy('display_name')
            ->get();

        $versionIds = $blueprints->flatMap(fn (NicheBlueprint $blueprint) => $blueprint->versions->pluck('id'))->all();

        // Which versions already carry THIS template (matched on the payload uid, not the key).
        $carrying = $versionIds === []
            ? collect()
            : NicheBlueprintComponent::query()
                ->whereIn('blueprint_version_id', $versionIds)
                ->where('component_type', DocumentTemplateComponentAdapter::TYPE)
                ->get(['blueprint_version_id', 'payload'])
                ->filter(fn (NicheBlueprintComponent $component) => ($component->payload['template_uid'] ?? null) === $template->uid)
                ->pluck('blueprint_version_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

        return $blueprints->map(function (NicheBlueprint $blueprint) use ($carrying): array {
            $published = $blueprint->versions->first(fn ($v) => $v->state === NicheBlueprintVersionState::Published);
            $draft = $blueprint->versions->first(fn ($v) => $v->state === NicheBlueprintVersionState::Draft);
            $live = $published !== null && $carrying->has((int) $published->id);
            $desired = $draft !== null ? $carrying->has((int) $draft->id) : $live;

            return [
                'blueprint' => $blueprint,
                'published' => $published,
                'draft' => $draft,
                'live' => $live,
                'desired' => $desired,
                'pending' => $draft !== null && $desired !== $live,
                'draft_components' => $draft !== null ? (int) $draft->components_count : 0,
            ];
        })->values();
    }

    /**
     * For the platform template list: which niches each template is LIVE in
     * (published versions only), keyed by template uid.
     *
     * @return array<string, array<int, string>> template uid => niche display names
     */
    public function liveAssignments(): array
    {
        $map = [];

        NicheBlueprintComponent::query()
            ->where('component_type', DocumentTemplateComponentAdapter::TYPE)
            ->whereIn('blueprint_version_id', function ($query): void {
                $query->select('id')->from('niche_blueprint_versions')->where('state', NicheBlueprintVersionState::Published->value);
            })
            ->with('blueprint:id,display_name')
            ->get()
            ->each(function (NicheBlueprintComponent $component) use (&$map): void {
                $uid = $component->payload['template_uid'] ?? null;

                if (is_string($uid) && $component->blueprint !== null) {
                    $map[$uid][] = (string) $component->blueprint->display_name;
                }
            });

        return array_map(fn (array $names) => array_values(array_unique($names)), $map);
    }

    /**
     * Record the chosen niches on each blueprint's draft (step 1). `$blueprintUids`
     * is the COMPLETE desired set: checked niches are assigned, unchecked ones
     * that currently carry the template are unassigned.
     *
     * @param  array<int, string>  $blueprintUids
     * @return array{assigned: int, unassigned: int}
     *
     * @throws ModelNotFoundException a uid that is not a niche blueprint (nothing is changed)
     */
    public function apply(User $actor, DocumentTemplate $template, array $blueprintUids): array
    {
        $this->assertPlatformTemplate($template);

        $blueprintUids = array_values(array_unique($blueprintUids));
        $chosen = NicheBlueprint::query()->whereIn('uid', $blueprintUids)->pluck('uid')->all();

        if (count($blueprintUids) !== count($chosen)) {
            throw (new ModelNotFoundException())->setModel(NicheBlueprint::class);
        }

        $assigned = 0;
        $unassigned = 0;

        foreach ($this->overview($template) as $row) {
            $want = in_array($row['blueprint']->uid, $chosen, true);

            if ($want && ! $row['desired']) {
                $this->assign($actor, $template, $row['blueprint']);
                $assigned++;
            } elseif (! $want && $row['desired']) {
                $this->unassign($actor, $template, $row['blueprint']);
                $unassigned++;
            }
        }

        return ['assigned' => $assigned, 'unassigned' => $unassigned];
    }

    public function assign(User $actor, DocumentTemplate $template, NicheBlueprint $blueprint): void
    {
        $this->assertPlatformTemplate($template);

        DB::transaction(function () use ($actor, $template, $blueprint): void {
            $draft = $this->ensureDraft($actor, $blueprint);

            if ($this->draftComponentsFor($draft, $template)->isNotEmpty()) {
                return; // already in the draft: idempotent
            }

            $this->publisher->addDraftComponent(
                (int) $actor->id,
                $draft,
                self::componentKey($template),
                DocumentTemplateComponentAdapter::TYPE,
                DocumentTemplateComponentAdapter::FEATURE_KEY,
                ['template_uid' => (string) $template->uid, 'label' => (string) $template->name],
            );
        });
    }

    public function unassign(User $actor, DocumentTemplate $template, NicheBlueprint $blueprint): void
    {
        $this->assertPlatformTemplate($template);

        DB::transaction(function () use ($actor, $template, $blueprint): void {
            $draft = $this->ensureDraft($actor, $blueprint);

            foreach ($this->draftComponentsFor($draft, $template) as $component) {
                $this->publisher->removeDraftComponent((int) $actor->id, $component);
            }
        });
    }

    /**
     * Step 2: publish the blueprint's draft through the publisher's gate (the
     * adapter validates every `document_template` component's payload there).
     *
     * @throws ModelNotFoundException the blueprint has no draft
     * @throws \App\Exceptions\NicheBlueprint\BlueprintAuthoringException
     */
    public function publishDraft(User $actor, NicheBlueprint $blueprint): NicheBlueprintVersion
    {
        $draft = $this->draftOf($blueprint) ?? throw (new ModelNotFoundException())->setModel(NicheBlueprintVersion::class);

        return $this->publisher->publishVersion((int) $actor->id, $draft);
    }

    /**
     * The blueprint's draft, creating it from the published version when absent
     * so that publishing it later does not drop what is live today.
     */
    public function ensureDraft(User $actor, NicheBlueprint $blueprint): NicheBlueprintVersion
    {
        $existing = $this->draftOf($blueprint);

        if ($existing !== null) {
            return $existing;
        }

        $published = NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Published->value)
            ->first();

        $draft = $this->publisher->createDraftVersion((int) $actor->id, $blueprint, 'Proposal / contract template assignment');

        if ($published !== null) {
            $components = NicheBlueprintComponent::query()
                ->where('blueprint_version_id', $published->id)
                ->orderBy('position')->orderBy('id')
                ->get();

            foreach ($components as $component) {
                $this->publisher->addDraftComponent(
                    (int) $actor->id,
                    $draft,
                    (string) $component->component_key,
                    (string) $component->component_type,
                    (string) $component->required_feature_key,
                    is_array($component->payload) ? $component->payload : [],
                    (int) $component->position,
                );
            }
        }

        return $draft;
    }

    private function draftOf(NicheBlueprint $blueprint): ?NicheBlueprintVersion
    {
        return NicheBlueprintVersion::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('state', NicheBlueprintVersionState::Draft->value)
            ->first();
    }

    /** @return Collection<int, NicheBlueprintComponent> */
    private function draftComponentsFor(NicheBlueprintVersion $draft, DocumentTemplate $template): Collection
    {
        return NicheBlueprintComponent::query()
            ->where('blueprint_version_id', $draft->id)
            ->where('component_type', DocumentTemplateComponentAdapter::TYPE)
            ->get()
            ->filter(fn (NicheBlueprintComponent $component) => ($component->payload['template_uid'] ?? null) === $template->uid)
            ->values();
    }

    private function assertPlatformTemplate(DocumentTemplate $template): void
    {
        if (! $template->isPlatformOwned()) {
            throw (new ModelNotFoundException())->setModel(DocumentTemplate::class);
        }
    }
}
