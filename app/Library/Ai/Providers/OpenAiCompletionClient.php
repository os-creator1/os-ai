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
    /**
     * Independent-review correction round 4 (item 1) — an explicit,
     * bounded provider/network timeout, comfortably shorter than
     * WebsiteGenerationCoordinator::LEASE_SECONDS (300). Without this,
     * the underlying HTTP client's own default (effectively unbounded
     * for a hung connection) could let a genuinely active call run past
     * the lease's own expiry, which would otherwise let a recovering
     * worker reclaim a lease a still-running provider call is legitimately
     * using.
     */
    private const PROVIDER_TIMEOUT_SECONDS = 60;

    /**
     * The HTTP timeout actually used: the request's own bound when it has one, never more than the provider ceiling.
     * Public and pure so the behaviour is tested directly rather than by reading this file's source.
     */
    public static function effectiveTimeoutSeconds(?int $requested): int
    {
        return $requested !== null && $requested > 0 ? min($requested, self::PROVIDER_TIMEOUT_SECONDS) : self::PROVIDER_TIMEOUT_SECONDS;
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        if (! config('services.openai.active') || empty(config('services.openai.api_key'))) {
            return AiCompletionResult::failure();
        }

        try {
            $client = OpenAI::factory()
                ->withApiKey(config('services.openai.api_key'))
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => self::effectiveTimeoutSeconds($request->timeoutSeconds), 'connect_timeout' => 10]))
                ->make();

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
                // Correction 3 — the provider answered, read the prompt and
                // billed for it; the content is simply unusable. That usage
                // is real and must be committed, not written off as free.
                return AiCompletionResult::failure(
                    providerModel: $result->model,
                    inputTokens: $result->usage?->promptTokens ?? 0,
                    outputTokens: $result->usage?->completionTokens ?? 0,
                    cachedInputTokens: $result->usage?->promptTokensDetails?->cachedTokens ?? 0,
                    finishReason: $result->choices[0]->finishReason ?? null,
                );
            }

            return AiCompletionResult::success(
                content: $content,
                providerModel: $result->model,
                inputTokens: $result->usage?->promptTokens ?? 0,
                outputTokens: $result->usage?->completionTokens ?? 0,
                cachedInputTokens: $result->usage?->promptTokensDetails?->cachedTokens ?? 0,
                finishReason: $result->choices[0]->finishReason,
            );
        } catch (Throwable $e) {
            // Customer-facing behaviour is unchanged (a generic failure). Only a SAFE, allow-listed summary is kept for the
            // gateway's diagnostic log: never the exception message, request, response body or headers.
            return AiCompletionResult::failure(
                errorClass: $e::class,
                httpStatus: self::safeHttpStatus($e),
                errorCode: self::safeProviderCode($e),
            );
        }
    }

    private static function safeHttpStatus(Throwable $e): ?int
    {
        $status = null;

        if ($e instanceof \OpenAI\Exceptions\ErrorException) {
            $status = $e->getStatusCode();
        } elseif ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse() !== null) {
            $status = $e->getResponse()->getStatusCode();
        }

        return is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
    }

    /** The provider's own short error code/type (for example `invalid_api_key`), accepted only in a strict identifier shape. */
    private static function safeProviderCode(Throwable $e): ?string
    {
        if (! $e instanceof \OpenAI\Exceptions\ErrorException) {
            return null;
        }

        $code = $e->getErrorCode() ?? $e->getErrorType();

        return is_string($code) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $code) === 1 ? $code : null;
    }
}
