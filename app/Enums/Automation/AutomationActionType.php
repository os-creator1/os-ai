<?php

namespace App\Enums\Automation;

/**
 * B4 Business Automations — the closed, code-backed set of v1 actions
 * (contract §7). Exactly one action runs per execution; identity is
 * always one of these values, never a stored class/callable name.
 */
enum AutomationActionType: string
{
    /**
     * SMS/MMS through the exact B1 Business Outreach send core
     * (CampaignRepository::checkQuickSendValidation() + quickSend()) on a
     * Business-assigned active channel. Never a provider integration of
     * its own, never the legacy User-only Automation::send() path.
     */
    case SendMessage = 'send_message';

    /**
     * Sets one Business-scoped custom-field value on the trigger Contact.
     * DB-only; takes the same durable claim path as a provider send.
     */
    case UpdateContactField = 'update_contact_field';

    public function label(): string
    {
        return match ($this) {
            self::SendMessage => 'Send message',
            self::UpdateContactField => 'Update contact field',
        };
    }
}
