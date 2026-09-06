<?php

namespace App\Library\AgencyProspecting;

use App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient;

/**
 * Runtime pass — the sole test double for AgencyProspectingAiClient.
 * Deterministic, in-memory, never calls a real model API. Bound only
 * inside the automated test suite via container override in each test's
 * own setUp() — never AppServiceProvider's default binding.
 */
class FakeAgencyProspectingAiClient implements AgencyProspectingAiClient
{
    public ?string $nextRawResponse = null;

    /** @var array<int, array<int, array{role: string, content: string}>> */
    public array $receivedMessages = [];

    public function complete(array $messages): ?string
    {
        $this->receivedMessages[] = $messages;

        return $this->nextRawResponse;
    }
}
