<?php

namespace App\Enums\Catalog;

/**
 * Implementation Contract 16 §5.1 — a catalog item is either in active use
 * or archived, mirroring `App\Enums\Business\BusinessLocationLifecycleState`
 * exactly. Archiving is a state change, never a delete: the row and its
 * history stay fully readable. Deliberately not `SoftDeletes`, for the same
 * reason as its precedent.
 */
enum CatalogItemLifecycleState: string
{
    case Active = 'active';
    case Archived = 'archived';
}
