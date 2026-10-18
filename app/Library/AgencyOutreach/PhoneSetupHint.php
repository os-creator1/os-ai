<?php

namespace App\Library\AgencyOutreach;

use App\Models\Workspace;

/**
 * Whether Outreach should offer the "Set up phone" shortcut: Outreach (and
 * Conversations/Automations) send from the agency's chosen sender, so it is needed
 * until that sender can actually send — the agency's own Telnyx account when it has
 * chosen one (AgencyByoSender), otherwise its MotionGrove number (the same
 * MessagingReadinessReader the Phone & Messaging page reads), so the two can never
 * disagree. Read-only: no provider call.
 */
final class PhoneSetupHint
{
    public function __construct(private readonly MessagingReadinessReader $readiness)
    {
    }

    public function needed(Workspace $workspace): bool
    {
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

        if ($business === null) {
            return true;
        }

        $byo = AgencyByoSender::selectedChannel($business);

        if ($byo !== null) {
            return ! AgencyByoSender::sendReadiness($byo)['ok'];
        }

        return ($this->readiness->situation($business)['state'] ?? null) !== 'ready';
    }
}
