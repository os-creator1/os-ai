<?php

namespace App\Enums\Questionnaire;

/**
 * Website Builder redesign — Niche Builder foundation. Mirrors
 * App\Enums\Automation\Workflow\WorkflowVersionState exactly: at most one
 * `draft` and at most one `published` version may exist per
 * QuestionnaireDefinition, enforced by the STORED generated guard columns
 * on `questionnaire_versions` (draft_guard/published_guard). A version
 * becomes immutable the moment it leaves draft — a QuestionnaireResponse
 * pinned to a published version keeps reading exactly the question tree
 * it started with, even after a newer version is published.
 */
enum QuestionnaireVersionState: string
{
    /** Editable. Carries the question-tree document a future authoring UI would autosave. */
    case Draft = 'draft';

    /** Live. Immutable. New sessions start here. */
    case Published = 'published';

    /** Replaced by a newer publish. Immutable, retained while any response is pinned to it. */
    case Superseded = 'superseded';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
