// Automations V2 (contract §5.2, V2-D) — the closed vocabulary this builder
// may ever render. Mirrors App\Enums\Automation\Workflow\WorkflowNodeType
// exactly; nothing here may be added without a matching registry entry on
// the server, because an unregistered type is refused at validate/compile.

export const NODE_TYPES = {
    TRIGGER: 'trigger',
    SEND_SMS: 'send_sms',
    UPDATE_CONTACT_FIELD: 'update_contact_field',
    INTERNAL_NOTIFICATION: 'internal_notification',
    WAIT: 'wait',
    IF_ELSE: 'if_else',
    END: 'end',
}

export const NODE_LABELS = {
    [NODE_TYPES.TRIGGER]: 'Trigger',
    [NODE_TYPES.SEND_SMS]: 'Send a text message',
    [NODE_TYPES.UPDATE_CONTACT_FIELD]: 'Update a contact field',
    [NODE_TYPES.INTERNAL_NOTIFICATION]: 'Notify the team',
    [NODE_TYPES.WAIT]: 'Wait',
    [NODE_TYPES.IF_ELSE]: 'If / Else',
    [NODE_TYPES.END]: 'End',
}

// Types a customer may insert anywhere in the body (never the trigger,
// which is always the one root the document validator requires).
export const INSERTABLE_TYPES = [
    NODE_TYPES.SEND_SMS,
    NODE_TYPES.UPDATE_CONTACT_FIELD,
    NODE_TYPES.INTERNAL_NOTIFICATION,
    NODE_TYPES.WAIT,
    NODE_TYPES.IF_ELSE,
    NODE_TYPES.END,
]

export function isBranching(type) {
    return type === NODE_TYPES.IF_ELSE
}

export function isTerminal(type) {
    return type === NODE_TYPES.END
}

// Default config for a freshly inserted node of each type — deliberately
// the smallest shape NodeTypeRegistry's validator can score, so a new step
// always starts with one clear, singular validation message rather than a
// wall of them.
export function defaultConfigFor(type) {
    switch (type) {
        case NODE_TYPES.SEND_SMS:
            return { body: '' }
        case NODE_TYPES.UPDATE_CONTACT_FIELD:
            return { field_id: null, value: '' }
        case NODE_TYPES.INTERNAL_NOTIFICATION:
            return { message: '' }
        case NODE_TYPES.WAIT:
            return { mode: 'duration', amount: 1, unit: 'days' }
        case NODE_TYPES.IF_ELSE:
            return { match: 'all', conditions: [] }
        case NODE_TYPES.END:
            return {}
        default:
            return {}
    }
}
