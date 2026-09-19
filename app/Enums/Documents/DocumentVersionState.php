<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.3 — version lifecycle metadata. Commercial
 * content is immutable once issued (§5.3.1); state may make exactly one
 * authorized transition afterwards, issued -> superseded.
 */
enum DocumentVersionState: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Superseded = 'superseded';
}
