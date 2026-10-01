<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Tag;

/**
 * Automations V2 — the Remove a tag action. See TagActionNodeExecutor.
 *
 * An archived tag can still be taken OFF a contact (archiving never touches
 * memberships), so it is accepted here.
 */
class RemoveTagNodeExecutor extends TagActionNodeExecutor
{
    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::RemoveTag;
    }

    protected function acceptsArchivedTag(): bool
    {
        return true;
    }

    protected function apply(Business $business, Contacts $contact, Tag $tag, ?string $origin): string
    {
        $membershipId = $this->tags->detachMembership($business, $contact, $tag, $origin);

        return $membershipId === null
            ? 'Contact did not have the tag "' . $tag->name . '"'
            : 'Removed the tag "' . $tag->name . '"';
    }
}
