<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Library\Ai\AiGateway;
use App\Library\Ai\AiRequest;
use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Business;
use App\Models\Workspace;

/**
 * "Improve with AI" for the description proposal. One metered AiGateway request per explicit click (interactive lane,
 * hard-budgeted, entitlement-checked and refusal-aware inside the gateway), built from confirmed facts only. The result
 * is returned to the editable box; it is never saved here.
 */
final class DescriptionImprover
{
    public function __construct(private readonly AiGateway $gateway, private readonly DescriptionProposal $proposal)
    {
    }

    /**
     * @return array{ok: bool, text: ?string, message: ?string}
     */
    public function improve(Workspace $workspace, Business $business, int $actorUserId, string $current, string $requestId): array
    {
        $facts = $this->proposal->facts($business);
        $current = mb_substr(trim($current), 0, DescriptionProposal::MAX);

        $result = $this->gateway->complete(AiRequest::forWorkspace(
            workspace: $workspace,
            business: $business,
            category: AiUsageCategory::AskAssistant,
            lane: AiLane::Interactive,
            route: AiModelRoute::Routine,
            messages: [
                ['role' => 'system', 'content' => 'You write short, plain, friendly business profile descriptions (60-120 words) for a small business. Use ONLY the confirmed facts supplied. Never invent prices, awards, years of experience, reviews, venues, guarantees, certifications or any other claim. Reply with the description text only.'],
                ['role' => 'user', 'content' => json_encode(['confirmed_facts' => $facts, 'current_draft' => $current], JSON_UNESCAPED_UNICODE)],
            ],
            maxOutputTokens: 400,
            idempotencyKey: 'home_description_improve:' . (int) $business->id . ':' . hash('sha256', $requestId),
            actorUserId: $actorUserId,
        ));

        if (! $result->ok) {
            return ['ok' => false, 'text' => null, 'message' => $result->refusalReason !== null
                ? 'AI suggestions are not available for this business right now.'
                : 'We could not improve the description just now. Your text is unchanged.'];
        }

        $text = trim((string) $result->content);

        if (! DescriptionProposal::valid($text)) {
            return ['ok' => false, 'text' => null, 'message' => 'The suggestion was not usable, so your text is unchanged.'];
        }

        return ['ok' => true, 'text' => $text, 'message' => null];
    }
}
