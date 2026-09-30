<?php

namespace App\Library\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireVersionState;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireVersion;
use Illuminate\Support\Facades\DB;

/**
 * Website Builder redesign — Niche Builder foundation. The draft-to-
 * published transition for a QuestionnaireDefinition, mirroring how this
 * codebase already publishes an AutomationWorkflowVersion: the guard
 * columns on `questionnaire_versions` (draft_guard/published_guard)
 * enforce "at most one draft, at most one published" per definition at
 * the database layer, so this class's own job is only the ORDER of
 * writes — flip the prior published version to superseded, then flip the
 * draft to published, inside one transaction, so a reader never observes
 * two simultaneously-published versions even momentarily.
 *
 * NO ADMIN UI CALLS THIS YET (Website Builder redesign scoping decision:
 * the general Niche Builder authoring UI is an explicitly deferred
 * follow-up). Today this is exercised only by the Photobooth seeder and
 * its own tests, proving the mechanism ahead of the UI that will use it.
 */
final class QuestionnaireVersionPublisher
{
    /**
     * Creates a new draft version carrying the given step/question tree.
     * Refuses when the definition already has a draft — the guard column
     * would refuse the insert anyway, but this gives a clear domain
     * message instead of a raw constraint-violation exception. Callers
     * that want to seed straight to a published version (the Photobooth
     * seeder) call publish() next.
     */
    public function createDraft(QuestionnaireDefinition $definition, array $steps): QuestionnaireVersion
    {
        return DB::transaction(function () use ($definition, $steps) {
            $existingDraft = $definition->versions()->where('state', QuestionnaireVersionState::Draft->value)->lockForUpdate()->first();

            if ($existingDraft !== null) {
                throw new \DomainException('This questionnaire already has a draft version — edit or publish it instead of creating another.');
            }

            $nextVersionNumber = (int) ($definition->versions()->max('version_number') ?? 0) + 1;

            return $definition->versions()->create([
                'version_number' => $nextVersionNumber,
                'state' => QuestionnaireVersionState::Draft,
                'definition' => ['steps' => $steps],
            ]);
        });
    }

    /**
     * Publishes a draft version: supersedes the definition's current
     * published version (if any) and promotes this draft, atomically.
     * Refuses a version that is not currently a draft — a published or
     * superseded version's `definition` must never be treated as still
     * editable.
     */
    public function publish(QuestionnaireVersion $draft, ?int $actorUserId = null): QuestionnaireVersion
    {
        return DB::transaction(function () use ($draft, $actorUserId) {
            $locked = QuestionnaireVersion::whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new \DomainException('Only a draft version can be published.');
            }

            $currentlyPublished = QuestionnaireVersion::where('questionnaire_definition_id', $locked->questionnaire_definition_id)
                ->where('state', QuestionnaireVersionState::Published->value)
                ->lockForUpdate()
                ->first();

            if ($currentlyPublished !== null) {
                $currentlyPublished->forceFill(['state' => QuestionnaireVersionState::Superseded])->save();
            }

            $locked->forceFill([
                'state' => QuestionnaireVersionState::Published,
                'definition_hash' => hash('sha256', json_encode($locked->definition)),
                'published_at' => now(),
                'published_by_user_id' => $actorUserId,
            ])->save();

            return $locked->refresh();
        });
    }
}
