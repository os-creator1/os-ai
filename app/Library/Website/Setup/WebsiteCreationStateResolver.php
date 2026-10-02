<?php

namespace App\Library\Website\Setup;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Models\Business;
use App\Models\QuestionnaireResponse;
use App\Models\Website;

/**
 * The single authority on "has this Business's website actually been
 * created yet?" — used by every Website entry point (top-level Website
 * link, Pages screen, wizard start/template/review, Studio) so they can
 * never disagree.
 *
 * THE INVARIANT: a Website row is NOT a created website. The wizard
 * deliberately creates the shell row at the template step, long before a
 * single question is answered or any page generated, and a legacy/blank
 * shell can exist with none at all. A website is "generated" only when it
 * has real pages (or a published revision, which can only exist after real
 * pages did). Everything else is derived from rows that already exist:
 *
 *   - an `in_progress` QuestionnaireResponse (a fresh setup OR a reopened
 *     edit session) -> InProgress, resumed at its saved question; or, for
 *     a first-time setup whose every answer is in and which is sitting on
 *     its final question (the position the wizard leaves it in when it
 *     sends the owner to the review screen) -> ReadyToGenerate;
 *   - otherwise pages / a published revision -> Generated;
 *   - otherwise a `completed` response that never produced pages (e.g.
 *     every page was later deleted) -> ReadyToGenerate;
 *   - otherwise -> NotStarted.
 *
 * Active sessions are checked FIRST so a reopened "Edit setup answers"
 * session on an already-generated site still resumes the wizard, exactly
 * as before.
 */
final class WebsiteCreationStateResolver
{
    public function __construct(
        private readonly QuestionnaireResolver $questionnaireResolver,
        private readonly QuestionnaireStepResolver $stepResolver,
    ) {
    }

    public function resolve(Business $business): WebsiteCreationState
    {
        $website = Website::where('business_id', $business->id)->first();
        $definition = $this->questionnaireResolver->resolveForBusiness($business);

        $active = $definition !== null
            ? QuestionnaireResponse::where('business_id', $business->id)
                ->where('questionnaire_definition_id', $definition->id)
                ->where('status', QuestionnaireResponseStatus::InProgress->value)
                ->first()
            : null;

        if ($active !== null) {
            return new WebsiteCreationState(
                $this->atReviewPosition($active) ? WebsiteCreationStage::ReadyToGenerate : WebsiteCreationStage::InProgress,
                $website,
                $active,
            );
        }

        if ($website !== null && $this->hasGeneratedPages($website)) {
            return new WebsiteCreationState(WebsiteCreationStage::Generated, $website, null);
        }

        if ($website !== null && $definition !== null) {
            $completed = QuestionnaireResponse::where('business_id', $business->id)
                ->where('questionnaire_definition_id', $definition->id)
                ->where('status', QuestionnaireResponseStatus::Completed->value)
                ->latest('id')
                ->first();

            if ($completed !== null) {
                return new WebsiteCreationState(WebsiteCreationStage::ReadyToGenerate, $website, $completed);
            }
        }

        return new WebsiteCreationState(WebsiteCreationStage::NotStarted, $website, null);
    }

    public function hasGeneratedPages(Website $website): bool
    {
        return $website->published_revision_id !== null || $website->pages()->exists();
    }

    /**
     * A first-time session whose answers are complete and whose saved
     * position is its last visible question: the owner finished the
     * questions and was sent to the review screen. A session the owner
     * stepped BACK in (current step earlier than the end) is still being
     * edited and resumes at that exact question instead; an edit_mode
     * session always resumes at its question.
     */
    private function atReviewPosition(QuestionnaireResponse $response): bool
    {
        if ($response->edit_mode) {
            return false;
        }

        $steps = $response->version->steps();
        $answers = $response->answers ?? [];

        return $this->stepResolver->isComplete($steps, $answers)
            && $this->stepResolver->nextStepKey($steps, $answers, (string) $response->current_step_key) === null;
    }
}
