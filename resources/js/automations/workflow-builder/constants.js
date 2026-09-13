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

// Customer wording for each step. Outcome-first: what the step does for the
// business, never how the engine names it.
export const NODE_LABELS = {
    [NODE_TYPES.TRIGGER]: 'Trigger',
    [NODE_TYPES.SEND_SMS]: 'Send text message',
    [NODE_TYPES.UPDATE_CONTACT_FIELD]: 'Update contact field',
    [NODE_TYPES.INTERNAL_NOTIFICATION]: 'Notify your team',
    [NODE_TYPES.WAIT]: 'Wait',
    [NODE_TYPES.IF_ELSE]: 'If / Else',
    [NODE_TYPES.END]: 'End workflow',
}

// The step picker's catalogue. Every entry is a registered node type; the
// keywords are only there so a search for "delay", "sms" or "branch" finds
// the step a person means.
export const STEP_CATALOG = [
    {
        type: NODE_TYPES.SEND_SMS,
        group: 'Messages',
        description: 'Text the contact. Personalise it with their name.',
        keywords: ['sms', 'text', 'message', 'send', 'follow up', 'reminder'],
        icon: 'message-square-text',
    },
    {
        type: NODE_TYPES.INTERNAL_NOTIFICATION,
        group: 'Messages',
        description: 'Let your team know something needs their attention.',
        keywords: ['notify', 'alert', 'team', 'staff', 'owner', 'tell'],
        icon: 'bell',
    },
    {
        type: NODE_TYPES.UPDATE_CONTACT_FIELD,
        group: 'Contact',
        description: 'Save a value on the contact, such as a status or a note.',
        keywords: ['update', 'field', 'contact', 'save', 'set', 'status', 'note'],
        icon: 'user-pen',
    },
    {
        type: NODE_TYPES.WAIT,
        group: 'Timing',
        description: 'Pause for a while, or until a set date and time.',
        keywords: ['wait', 'delay', 'pause', 'later', 'timer', 'days', 'hours', 'minutes'],
        icon: 'clock',
    },
    {
        type: NODE_TYPES.IF_ELSE,
        group: 'Logic',
        description: 'Split the path: one set of steps if something is true, another if not.',
        keywords: ['if', 'else', 'branch', 'condition', 'split', 'check', 'replied', 'decide'],
        icon: 'split',
    },
    {
        type: NODE_TYPES.END,
        group: 'Logic',
        description: 'Stop the workflow for this contact here.',
        keywords: ['end', 'stop', 'finish', 'exit', 'done'],
        icon: 'circle-stop',
    },
]

export const NODE_ICONS = {
    [NODE_TYPES.TRIGGER]: 'zap',
    ...Object.fromEntries(STEP_CATALOG.map((entry) => [entry.type, entry.icon])),
}

// Types a customer may insert anywhere in the body (never the trigger,
// which is always the one root the document validator requires).
export const INSERTABLE_TYPES = STEP_CATALOG.map((entry) => entry.type)

export function isBranching(type) {
    return type === NODE_TYPES.IF_ELSE
}

export function isTerminal(type) {
    return type === NODE_TYPES.END
}

/** A step that must be the last in its path (§5.3): If / Else and End. */
export function closesSequence(type) {
    return isBranching(type) || isTerminal(type)
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

// Triggers with a real producer today (WorkflowTriggerType::isIngestableInThisSlice()).
// Forms, calendars, payments and tags have none, so none is offered.
export const TRIGGER_TYPES = [
    {
        value: 'contact_created',
        title: 'Contact is created',
        description: 'Starts when a new contact is added.',
        icon: 'user-plus',
        defaultPolicy: 'once_ever',
    },
    {
        value: 'message_received',
        title: 'Customer sends a text',
        description: 'Starts when a contact texts your business.',
        icon: 'message-square-reply',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'contact_date_reached',
        title: 'Contact date arrives',
        description: 'Starts on a date saved on the contact, such as a birthday.',
        icon: 'calendar-clock',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'manual_enrollment',
        title: 'Added by hand',
        description: 'Starts only when you add a contact to it yourself.',
        icon: 'hand',
        defaultPolicy: 'once_ever',
    },
]

export function triggerTypeInfo(value) {
    return TRIGGER_TYPES.find((entry) => entry.value === value) || null
}

export const CONTACT_SOURCE_LABELS = {
    any: 'From anywhere',
    opt_in_form: 'From an opt-in form',
    in_app: 'Added in the app',
}

export const ENROLLMENT_POLICY_LABELS = {
    once_ever: 'Only once per contact',
    once_per_occurrence: 'Every time it happens',
}

/** '0 day' → 'On the date'; '1 week' → '1 week before'. */
export function offsetLabel(offset) {
    if (!offset || offset === '0 day') {
        return 'On the date'
    }

    return `${offset} before`
}
