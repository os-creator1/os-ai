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

    case Wait = 'wait';
    case IfElse = 'if_else';
    case End = 'end';

    public function sideEffectClass(): NodeSideEffectClass
    {
        return match ($this) {
            self::Trigger, self::Wait, self::IfElse, self::End => NodeSideEffectClass::None,
            self::UpdateContactField => NodeSideEffectClass::IdempotentDatabase,
            self::SendSms, self::InternalNotification => NodeSideEffectClass::External,
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
            self::Wait => 'Wait',
            self::IfElse => 'If / Else',
            self::End => 'End',
        };
    }
}
