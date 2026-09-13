<?php

namespace App\Library\Ai\Contracts;

use App\Library\Ai\AiCompletionRequest;
use App\Library\Ai\AiCompletionResult;

/**
 * Contract §13 — the provider seam. This interface is the ONLY thing
 * outside `app/Library/Ai/Providers/**` that is allowed to know a
 * completion happens; it names no provider and no model. Adding another
 * provider means one new adapter under `Providers/**` plus a config
 * entry — no domain change.
 */
interface AiCompletionClient
{
    public function complete(AiCompletionRequest $request): AiCompletionResult;
}
