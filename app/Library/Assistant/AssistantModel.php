<?php

namespace App\Library\Assistant;

use App\Library\Ai\AiGateway;
use App\Library\Ai\AiRequest;
use App\Library\Ai\AiResult;
use App\Library\Ai\AiUsageLedgerManager;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiUsageCategory;

/**
 * The assistant's ONLY way to a model: AiGateway::complete(). Budget gates, reservation, pricing, provider call,
 * settlement and the ledger are all the gateway's — nothing here calls a provider, estimates a cost or touches a
 * period row.
 *
 * Each call gets its own idempotency key, derived from the customer message it serves plus how many keys that
 * message's family has already used, so a retried request can never collide with — or double-charge — an earlier
 * attempt (a reservation that was made is settled once; a new attempt is a new, separately reserved call).
 */
final class AssistantModel
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiUsageLedgerManager $ledger,
    ) {
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function complete(
        AssistantContext $context,
        string $familyPrefix,
        AiUsageCategory $category,
        AiModelRoute $route,
        array $messages,
        int $maxOutputTokens,
        bool $json,
    ): AiResult {
        $attempt = $this->ledger->idempotencyFamily($familyPrefix)['attempts'] + 1;

        return $this->gateway->complete(AiRequest::forWorkspace(
            workspace: $context->workspace,
            business: $context->business,
            category: $category,
            // Interactive lane: the customer asked, and the call counts against the interactive share.
            lane: AiLane::Interactive,
            route: $route,
            messages: $messages,
            maxOutputTokens: $maxOutputTokens,
            idempotencyKey: $familyPrefix . $attempt,
            actorUserId: (int) $context->user->id,
            jsonMode: $json,
        ));
    }
}
