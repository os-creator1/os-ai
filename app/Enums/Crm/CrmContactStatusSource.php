<?php

namespace App\Enums\Crm;

/**
 * Who last set a deal's contact status.
 *
 * Only `Manual` is written today. `Conversation` is reserved for the later
 * update from canonical inbound conversation activity, so that update can tell a
 * customer's own correction apart and never has to guess.
 */
enum CrmContactStatusSource: string
{
    case Manual = 'manual';
    case Conversation = 'conversation';
}
