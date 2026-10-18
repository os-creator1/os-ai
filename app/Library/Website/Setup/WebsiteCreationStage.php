<?php

namespace App\Library\Website\Setup;

/**
 * Where a Business is in the ONE Website creation journey. Derived
 * entirely from existing rows by WebsiteCreationStateResolver — there is
 * no stored "stage" column and no second state machine.
 */
enum WebsiteCreationStage: string
{
    /** Nothing generated and no setup session: show the "Create my website" landing. */
    case NotStarted = 'not_started';

    /** A questionnaire session is open: resume it at its exact current question. */
    case InProgress = 'in_progress';

    /** Every answer is in (or setup finished) but no website was ever generated: go to the generate / try-again screen. */
    case ReadyToGenerate = 'ready_to_generate';

    /** Real generated (or published) pages exist: Studio is the right place. */
    case Generated = 'generated';
}
