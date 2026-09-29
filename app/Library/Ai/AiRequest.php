<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiLane;
use App\Library\Ai\Enums\AiModelRoute;
use App\Library\Ai\Enums\AiScope;
use App\Library\Ai\Enums\AiUsageCategory;
use App\Models\Business;
use App\Models\Workspace;
use InvalidArgumentException;

/**
 * Contract §10.1, §5.7a A — the one input shape every AI call in the
 * application builds. `business` is nullable for Workspace-level work
 * (agency prospecting has no single Business; the legacy campaign message
 * draft endpoint likewise carries no Business context, §10.3a). `workspace`
 * is nullable for the new Platform scope (§5.7a), where there is no tenant
 * at all.
 *
 * `scope` is appended after the pre-existing `jsonMode` parameter, last,
 * with a default — so all three production construction sites
 * (`CooInsightGenerator.php`, `WebsiteAiGenerationClient.php`,
 * `OpenAiAgencyProspectingClient.php`), which all use named arguments,
 * remain source-unchanged (§5.7a B).
 *
 * The constructor enforces the one invariant that keeps a Platform request
 * from ever carrying a fabricated tenant (R-19): `Workspace` scope requires
 * a real Workspace; `Platform` scope requires no Workspace, no Business and
 * a real actor id.
 */
final readonly class AiRequest
{
    public function __construct(
        public ?Workspace $workspace,
        public ?Business $business,
        public AiUsageCategory $category,
        public AiLane $lane,
        public AiModelRoute $route,
        public array $messages,
        public int $maxOutputTokens,
        public string $idempotencyKey,
        public ?int $actorUserId,
        public bool $jsonMode = false,
        public AiScope $scope = AiScope::Workspace,
    ) {
        $valid = match ($this->scope) {
            AiScope::Workspace => $this->workspace !== null,
            AiScope::Platform => $this->workspace === null && $this->business === null && $this->actorUserId !== null,
        };

        if (! $valid) {
            throw new InvalidArgumentException(
                "AiRequest scope {$this->scope->value} is inconsistent with its workspace/business/actorUserId.",
            );
        }
    }

    /**
     * Call-site clarity for the existing, only scope. Equivalent to the raw
     * constructor with `scope: AiScope::Workspace`.
     */
    public static function forWorkspace(
        Workspace $workspace,
        ?Business $business,
        AiUsageCategory $category,
        AiLane $lane,
        AiModelRoute $route,
        array $messages,
        int $maxOutputTokens,
        string $idempotencyKey,
        ?int $actorUserId,
        bool $jsonMode = false,
    ): self {
        return new self(
            workspace: $workspace,
            business: $business,
            category: $category,
            lane: $lane,
            route: $route,
            messages: $messages,
            maxOutputTokens: $maxOutputTokens,
            idempotencyKey: $idempotencyKey,
            actorUserId: $actorUserId,
            jsonMode: $jsonMode,
            scope: AiScope::Workspace,
        );
    }

    /**
     * Contract §5.7a A/C — Platform scope carries no Workspace and no
     * Business, ever (R-19); `actorUserId` is required and is attribution,
     * not authentication (R-28) — the caller must have already derived it
     * from the authenticated server principal, never from client input.
     */
    public static function forPlatform(
        int $actorUserId,
        AiUsageCategory $category,
        AiLane $lane,
        AiModelRoute $route,
        array $messages,
        int $maxOutputTokens,
        string $idempotencyKey,
        bool $jsonMode = false,
    ): self {
        return new self(
            workspace: null,
            business: null,
            category: $category,
            lane: $lane,
            route: $route,
            messages: $messages,
            maxOutputTokens: $maxOutputTokens,
            idempotencyKey: $idempotencyKey,
            actorUserId: $actorUserId,
            jsonMode: $jsonMode,
            scope: AiScope::Platform,
        );
    }
}
