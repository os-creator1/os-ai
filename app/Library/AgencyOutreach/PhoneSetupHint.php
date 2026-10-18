<?php

namespace App\Library\AgencyOutreach;

use App\Models\Workspace;

/**
 * Whether Outreach should offer the "Set up phone" shortcut: Outreach (and
 * Conversations/Automations) send through the agency's own Business number, so
 * it is needed until MessagingReadinessReader reports that number ready. A
 * connected BYO Telnyx channel does not satisfy it today — the Phone & Messaging
 * page states those channels currently power AI Prospecting only. Reads the same
 * reader as that page, so the two can never disagree. Read-only: no provider call.
 */
final class PhoneSetupHint
{
    public function __construct(private readonly MessagingReadinessReader $readiness)
    {
    }

    public function needed(Workspace $workspace): bool
    {
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

        return $business === null || ($this->readiness->situation($business)['state'] ?? null) !== 'ready';
    }
}
