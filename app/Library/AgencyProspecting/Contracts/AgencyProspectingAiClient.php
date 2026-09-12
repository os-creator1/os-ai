<?php

namespace App\Library\AgencyProspecting\Contracts;

use App\Models\Workspace;

/**
 * Runtime pass — the small, prospecting-scoped AI client seam the audit
 * found no reusable generic abstraction for. Deliberately narrow: one
 * method, raw chat messages in, raw assistant text out. All structure/
 * validation of that text lives in AgencyProspectAiDecision — never here.
 *
 * AI Gateway Contract §10.1/§10.3a (slice AI-1) — every implementation
 * now routes through App\Library\Ai\AiGateway, attributed to the calling
 * Workspace (agency prospecting has no single Business, so it is
 * checked against the Workspace cap only). $workspace and $actorUserId
 * were added here so that attribution — the whole point of the gateway
 * — is possible; the raw-messages-in/raw-text-out shape is otherwise
 * unchanged.
 */
interface AgencyProspectingAiClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return string|null the raw assistant content, or null if the client
     *                      is not configured/active, the request failed, or
     *                      the AI budget gateway refused it — callers must
     *                      treat null as "fail closed: send nothing, advance
     *                      nothing", never fabricate a reply.
     */
    public function complete(array $messages, Workspace $workspace, ?int $actorUserId = null): ?string;
}
