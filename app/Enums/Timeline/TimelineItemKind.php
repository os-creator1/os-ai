<?php

namespace App\Enums\Timeline;

/**
 * What a contact activity timeline row IS, which decides how much room it gets.
 *
 * A message is human communication and dominates the timeline as a bubble. An
 * activity is something that happened around that communication — an
 * automation outcome, an opt-out, a contact being added — and renders as a
 * compact card. A future domain picks the kind that matches its event: an
 * email is a Message, a form submission or a payment is an Activity.
 */
enum TimelineItemKind: string
{
    case Message = 'message';
    case Activity = 'activity';
}
