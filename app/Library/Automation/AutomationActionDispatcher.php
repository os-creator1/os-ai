<?php

namespace App\Library\Automation;

use App\Enums\Automation\AutomationActionType;
use App\Library\Automation\Actions\SendMessageAction;
use App\Library\Automation\Actions\UpdateContactFieldAction;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\Business;
use App\Models\Contacts;

/**
 * B4 Business Automations — the bounded action dispatcher (contract §8):
 * a `match` on the code-backed action enum to exactly one handler. Not a
 * plugin registry, not a generic workflow abstraction; adding an action
 * means adding an enum case and a case here.
 *
 * Every handler receives already-eligibility-verified state and performs
 * its own final, action-specific re-checks (channel/sender/field) before
 * doing anything with an external side effect.
 */
class AutomationActionDispatcher
{
    public function __construct(
        private readonly SendMessageAction $sendMessage,
        private readonly UpdateContactFieldAction $updateContactField,
    ) {
    }

    public function dispatch(AutomationExecution $execution, Automation $automation, Business $business, Contacts $contact): AutomationActionResult
    {
        return match ($automation->action_type) {
            AutomationActionType::SendMessage => $this->sendMessage->run($execution, $automation, $business, $contact),
            AutomationActionType::UpdateContactField => $this->updateContactField->run($execution, $automation, $business, $contact),
            default => AutomationActionResult::skipped('unknown_action_type'),
        };
    }
}
