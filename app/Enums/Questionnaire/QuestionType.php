<?php

namespace App\Enums\Questionnaire;

/**
 * Website Builder redesign — the bounded set of question types a
 * QuestionnaireVersion's `definition` JSON may use. Deliberately separate
 * from App\Enums\Business\QuestionInputType, which is closed to Website
 * Guided Generation contract §6.2's `question_packs` table and its five
 * simple types only — this enum serves the richer wizard step shapes the
 * Photobooth definition needs (repeatable structured entries, photo
 * uploads, a package's "real price or contact for pricing" choice).
 */
enum QuestionType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Tel = 'tel';
    case Email = 'email';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Boolean = 'boolean';

    /** One or more structured sub-entries (booth types, packages, backdrops). */
    case RepeatableGroup = 'repeatable_group';

    /** One or more image uploads. */
    case PhotoUpload = 'photo_upload';

    /**
     * A flat, ordered list of short strings, one per row (service areas,
     * package features). Replaces comma-separated text: one entry per row,
     * never parsed apart by a delimiter.
     */
    case StringList = 'string_list';

    /**
     * The owner's choice among the Business's CANONICAL Packages & Products
     * (existing ones to include, edits, new ones created in the catalog).
     * The answer stores only catalog uids — never a copy of a name or price.
     */
    case CatalogSelection = 'catalog_selection';

    // Independent-review correction round 2 — `price_or_quote` was
    // removed: it had no real Blade renderer, no
    // WebsiteWizardController::valueFromRequest() parser, no
    // QuestionnaireAnswerValidator branch, and no distinct application
    // path (a package's own price-or-quote choice is already handled by
    // the `catalog_item` repeatable-group shape's own price_minor/
    // currency_code fields — see QuestionnaireDefinitionValidator's
    // input-type/target-module compatibility check, which now enforces
    // that every accepted case here has all four before a definition can
    // ever be published).
}
