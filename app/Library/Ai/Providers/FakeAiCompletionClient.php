<?php

namespace App\Library\Ai\Providers;

use App\Library\Ai\AiCompletionRequest;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;

/**
 * Test double for AiCompletionClient. Mirrors the established
 * Fake{Something}Client pattern elsewhere in this repository
 * (FakeAgencyProspectingAiClient, FakeMessagingAdapter): records every
 * request it was asked to complete, and returns a queued or default
 * response — it never reaches a real network endpoint
 * (Http::preventStrayRequests() is irrelevant here since this class
 * makes no HTTP call at all).
 */
final class FakeAiCompletionClient implements AiCompletionClient
{
    /** @var AiCompletionRequest[] */
    private array $requests = [];

    /** @var AiCompletionResult[] */
    private array $queue = [];

    private ?AiCompletionResult $default = null;

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $this->requests[] = $request;

        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        return $this->default ?? AiCompletionResult::success(
            content: '{"statements":[]}',
            providerModel: $request->model,
            inputTokens: 100,
            outputTokens: 20,
        );
    }

    public function queueResult(AiCompletionResult $result): void
    {
        $this->queue[] = $result;
    }

    public function setDefaultResult(AiCompletionResult $result): void
    {
        $this->default = $result;
    }

    /**
     * @return AiCompletionRequest[]
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }
}
