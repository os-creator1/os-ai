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
}
