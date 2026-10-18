<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17B §1/§6 — a template's label. Both types create a
 * `proposal`-kind document; "contract" is a label, never a DocumentKind.
 */
enum DocumentTemplateType: string
{
    case Proposal = 'proposal';
    case Contract = 'contract';
}
