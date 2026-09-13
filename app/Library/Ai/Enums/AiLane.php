<?php

namespace App\Library\Ai\Enums;

/**
 * Contract §10.4 (L-13) — the protected product/system share vs. the
 * capped interactive share of the same Workspace budget.
 */
enum AiLane: string
{
    case Product = 'product';
    case Interactive = 'interactive';
}
