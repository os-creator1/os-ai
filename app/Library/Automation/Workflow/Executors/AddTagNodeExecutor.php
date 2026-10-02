<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Tag;

/**
 * Automations V2 — the Add a tag action. See TagActionNodeExecutor.
 *
 * An archived tag cannot be attached to a new contact (TagManager's own rule), so
 * a step that names one is skipped rather than failing the journey.
 */
class AddTagNodeExecutor extends TagActionNodeExecutor
{
    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::AddTag;
    }

    protected function acceptsArchivedTag(): bool
    {
        return false;
    }

    protected function apply(Business $business, Contacts $contact, Tag $tag, ?string $origin): string
    {
        $membership = $this->tags->attachTag($business, $contact, $tag, $origin);

        return $membership === null
            ? 'Contact already had the tag "' . $tag->name . '"'
            : 'Added the tag "' . $tag->name . '"';
    }
}
