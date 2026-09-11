<?php

namespace App\Library\Dashboard;

use App\Enums\Dashboard\AttentionSeverity;
use App\Enums\Dashboard\AttentionType;

/**
 * Customer Experience Slice 4 §5.1 — one thing that needs attention, in the
 * locked shape: a code-defined type, a severity rendered as a word, the scope
 * it belongs to, one plain sentence and a real remediation link the actor can
 * reach. An item with no reachable fix is never built (see
 * BusinessHomePresenter::attention()).
 */
final class AttentionItem
{
    public function __construct(
        public readonly AttentionType $type,
        public readonly AttentionSeverity $severity,
        public readonly string $scope,
        public readonly string $text,
        public readonly string $actionLabel,
        public readonly string $url,
    ) {
    }
}
