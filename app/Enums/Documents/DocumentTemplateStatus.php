<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17B §6 — template lifecycle. Archive only; a
 * template is never hard-deleted.
 */
enum DocumentTemplateStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
