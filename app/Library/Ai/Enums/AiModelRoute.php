<?php

namespace App\Library\Ai\Enums;

/**
 * Contract §13, D-4 — the three configurable routing classes. Domain code
 * asks for a route and never names a model; `config('ai.routes.*')` is
 * the only place a concrete provider/model appears (besides the
 * per-call `provider_model` provenance column).
 */
enum AiModelRoute: string
{
    case Routine = 'routine';
    case Reasoning = 'reasoning';
    case Compaction = 'compaction';

    /**
     * Independent-review correction round 2 — guided website generation's
     * own dedicated, bounded envelope (config('ai.routes.website_generation')):
     * one full-site JSON response genuinely does not fit `routine`'s
     * 800-output-token ceiling, sized for a single short draft/reply.
     * No downgrade path targets this route (AiModelRouter::
     * resolveAffordableRoute() only ever downgrades `reasoning`), and an
     * unaffordable request here is refused outright, exactly like
     * `routine`/`compaction`.
     */
    case WebsiteGeneration = 'website_generation';

    /**
     * Content Autopilot — the stronger route used ONLY for the article draft and meaningful rewrites. Small steps
     * (outline help, classification, the soft-finding judge) use `routine`. Like `website_generation`, no downgrade
     * path targets it: an unaffordable request is refused, never silently written by a weaker model.
     */
    case ContentWriter = 'content_writer';
}
