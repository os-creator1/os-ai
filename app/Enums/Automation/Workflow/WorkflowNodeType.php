<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §5.2 — the closed, code-backed set of step types.
 *
 * Every type is registered in
 * App\Library\Automation\Workflow\NodeTypeRegistry with its config validator
 * and its side-effect class. An unregistered or unknown type is refused at
 * validation, at compile and at execution — a workflow can never reference a
 * step type that does not exist in code.
 *
 * Executors are NOT part of this slice. V2-A supplies the logic executors
 * (wait, if/else, end) and V2-B the action executors (send SMS, update field,
 * notification), both through the NodeExecutor contract. The types are declared
 * here so the compiler, the validator and the builder all agree on one list
 * before any runtime exists.
 */
enum WorkflowNodeType: string
{
    /** The root. Exactly one per version, and always the root. */
    case Trigger = 'trigger';

    case SendSms = 'send_sms';
    case UpdateContactField = 'update_contact_field';
    case InternalNotification = 'internal_notification';

    /*
     * Merged-foundation actions. Each calls its owning domain's canonical seam
     * (BusinessEmailSender, TagManager); none talks to a provider or writes a
     * domain table itself.
     */
    case SendEmail = 'send_email';
    case AddTag = 'add_tag';
    case RemoveTag = 'remove_tag';

    case Wait = 'wait';
    case IfElse = 'if_else';
    case End = 'end';

    public function sideEffectClass(): NodeSideEffectClass
    {
        return match ($this) {
            self::Trigger, self::Wait, self::IfElse, self::End => NodeSideEffectClass::None,
            // TagManager's attach/detach are idempotent by their own contract: a
            // second attach of a held tag, or a detach of an absent one, writes
            // nothing and emits nothing.
            self::UpdateContactField, self::AddTag, self::RemoveTag => NodeSideEffectClass::IdempotentDatabase,
            self::SendSms, self::InternalNotification, self::SendEmail => NodeSideEffectClass::External,
        };
    }

    /** A branching node emits `yes`/`no` edges instead of a single `next`. */
    public function isBranching(): bool
    {
        return $this === self::IfElse;
    }

    /** A terminal node ends its path: it may have no outgoing edge at all. */
    public function isTerminal(): bool
    {
        return $this === self::End;
    }

    /** Whether this type may appear anywhere other than the root. */
    public function isPlaceableInBody(): bool
    {
        return $this !== self::Trigger;
    }

    public function label(): string
    {
        return match ($this) {
            self::Trigger => 'Trigger',
            self::SendSms => 'Send a text message',
            self::UpdateContactField => 'Update a contact field',
            self::InternalNotification => 'Notify the team',
            self::SendEmail => 'Send an email',
            self::AddTag => 'Add a tag',
            self::RemoveTag => 'Remove a tag',
            self::Wait => 'Wait',
            self::IfElse => 'If / Else',
            self::End => 'End',
        };
    }
}
