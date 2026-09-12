<?php

namespace App\Library\Ai\Providers;

use App\Library\Ai\AiCompletionRequest;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use OpenAI;
use Throwable;

/**
 * Unified Business Home and COO Decision Engine Contract §13. The real
 * provider adapter — this is the ONLY class outside a test double that
 * constructs an OpenAI client, following the exact fail-closed shape of
 * the three call sites it replaces (App\Library\AgencyProspecting's
 * former OpenAiAgencyProspectingClient, App\Library\Website's former
 * inline OpenAI::client() usage, and CampaignController::generateAIMessage()'s
 * former inline call). Reuses the one credential store,
 * config('services.openai.*') (§1.2 "AI credentials and toggle") — no new
 * config keys, no new package, no new credential store.
 *
 * Never throws for a provider-side failure: a missing/inactive API key,
 * or any provider exception, returns AiCompletionResult::failure().
 */
final class OpenAiCompletionClient implements AiCompletionClient
{
    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        if (! config('services.openai.active') || empty(config('services.openai.api_key'))) {
            return AiCompletionResult::failure();
        }

        try {
            $client = OpenAI::client(config('services.openai.api_key'));

            $payload = [
                'model' => $request->model,
                'messages' => $request->messages,
                'max_tokens' => $request->maxOutputTokens,
            ];

            if ($request->jsonMode) {
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $result = $client->chat()->create($payload);

            $content = trim($result->choices[0]->message->content ?? '');

            if ($content === '') {
                return AiCompletionResult::failure();
            }

            return AiCompletionResult::success(
                content: $content,
                providerModel: $result->model,
                inputTokens: $result->usage?->promptTokens ?? 0,
                outputTokens: $result->usage?->completionTokens ?? 0,
                cachedInputTokens: $result->usage?->promptTokensDetails?->cachedTokens ?? 0,
                finishReason: $result->choices[0]->finishReason,
            );
        } catch (Throwable) {
            return AiCompletionResult::failure();
        }
    }
}
