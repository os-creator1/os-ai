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

    /** A real price + currency, OR "contact for pricing" — never both unset in a way that's ambiguous. */
    case PriceOrQuote = 'price_or_quote';
}
