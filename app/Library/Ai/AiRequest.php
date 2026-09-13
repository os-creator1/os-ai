<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Business;
use App\Models\Workspace;

/**
 * Contract §10.1 — the one input shape every AI call in the application
 * builds. `business` is nullable for Workspace-level work (agency
 * prospecting has no single Business; the legacy campaign message
 * draft endpoint likewise carries no Business context, §10.3a).
 */
final readonly class AiRequest
{
    public function __construct(
        public Workspace $workspace,
        public ?Business $business,
        public AiUsageCategory $category,
        public AiLane $lane,
        public AiModelRoute $route,
        public array $messages,
        public int $maxOutputTokens,
        public string $idempotencyKey,
        public ?int $actorUserId,
        public bool $jsonMode = false,
    ) {
    }
}
