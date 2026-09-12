<?php

namespace App\Enums\Business;

/**
 * Customer Experience Slice 1A (contract §7.3a) — a physical location is
 * either in active use or archived. Capacity counts ACTIVE locations only,
 * so archiving frees a slot; an archived row is never deleted, and its
 * history (Google binding, analytics, website references) is kept.
 *
 * Deliberately not SoftDeletes: a soft-deleted row would vanish from
 * default queries and relationships, whereas an archived location must stay
 * fully readable.
 */
enum BusinessLocationLifecycleState: string
{
    case Active = 'active';
    case Archived = 'archived';
}
