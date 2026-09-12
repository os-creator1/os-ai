<?php

namespace App\Library\AgencyProspecting;

use App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient;
use App\Models\Workspace;

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

    /**
     * Correction 2 — an optional side effect invoked at the exact moment
     * a real model call would occur, letting a test simulate state
     * changing (e.g. another job advancing a member's stage) in the
     * window between the AI call and the responder's later claim
     * transaction, without any timing/sleep hack.
     *
     * @var (callable(): void)|null
     */
    public $beforeReturn = null;

    public array $receivedWorkspaceIds = [];

    public function complete(array $messages, Workspace $workspace, ?int $actorUserId = null): ?string
    {
        $this->receivedMessages[] = $messages;
        $this->receivedWorkspaceIds[] = $workspace->id;

        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)();
        }

        return $this->nextRawResponse;
    }
}
