<?php

namespace App\Library\Ai\Enums;

/**
 * Contract §5.7a A — the authorization/budget scope discriminator every
 * `AiRequest` carries. `Workspace` is today's only scope: a real tenant
 * budgets and is entitled through `AiBudgetPolicyResolver::resolveFor()`.
 * `Platform` is the new §5.7a scope: no Workspace, no Business, bounded by
 * `AiBudgetPolicyResolver::resolveForPlatform()`'s finite, config-derived
 * cap instead of any customer plan.
 */
enum AiScope: string
{
    case Workspace = 'workspace';
    case Platform = 'platform';
}
