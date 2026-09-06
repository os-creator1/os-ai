<?php

namespace App\Library\AgencyProspecting\Contracts;

/**
 * Runtime pass — the small, prospecting-scoped AI client seam the audit
 * found no reusable generic abstraction for (the only existing AI call,
 * CampaignController::generateAIMessage(), is a bare inline
 * OpenAI::client() call with no service class). Deliberately narrow: one
 * method, raw chat messages in, raw assistant text out. All structure/
 * validation of that text lives in AgencyProspectAiDecision — never here.
 */
interface AgencyProspectingAiClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return string|null the raw assistant content, or null if the client
     *                      is not configured/active or the request failed —
     *                      callers must treat null as "fail closed: send
     *                      nothing, advance nothing", never fabricate a reply.
     */
    public function complete(array $messages): ?string;
}
