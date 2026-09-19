<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.1 — one versioned document with stages.
 * "Contract" is deliberately NOT a third kind: it is the signed state of a
 * proposal-kind document.
 */
enum DocumentKind: string
{
    case Proposal = 'proposal';
    case Invoice = 'invoice';
}
