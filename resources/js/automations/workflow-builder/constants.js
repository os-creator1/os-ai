// Automations V2 (contract §5.2, V2-D) — the closed vocabulary this builder
// may ever render. Mirrors App\Enums\Automation\Workflow\WorkflowNodeType
// exactly; nothing here may be added without a matching registry entry on
// the server, because an unregistered type is refused at validate/compile.

export const NODE_TYPES = {
    TRIGGER: 'trigger',
    SEND_SMS: 'send_sms',
    UPDATE_CONTACT_FIELD: 'update_contact_field',
    INTERNAL_NOTIFICATION: 'internal_notification',
    SEND_EMAIL: 'send_email',
    ADD_TAG: 'add_tag',
    REMOVE_TAG: 'remove_tag',
    MOVE_OPPORTUNITY: 'move_opportunity',
    SEND_BOOKING_LINK: 'send_booking_link',
    SEND_FORM: 'send_form',
    SEND_QUESTIONNAIRE: 'send_questionnaire',
    CREATE_SEND_PROPOSAL: 'create_send_proposal',
    REQUEST_PAYMENT: 'request_payment',
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
    [NODE_TYPES.SEND_EMAIL]: 'Send email',
    [NODE_TYPES.ADD_TAG]: 'Add tag',
    [NODE_TYPES.REMOVE_TAG]: 'Remove tag',
    [NODE_TYPES.MOVE_OPPORTUNITY]: 'Move opportunity',
    [NODE_TYPES.SEND_BOOKING_LINK]: 'Send booking link',
    [NODE_TYPES.SEND_FORM]: 'Send form',
    [NODE_TYPES.SEND_QUESTIONNAIRE]: 'Send questionnaire',
    [NODE_TYPES.CREATE_SEND_PROPOSAL]: 'Create & send proposal or contract',
    [NODE_TYPES.REQUEST_PAYMENT]: 'Request payment',
    [NODE_TYPES.WAIT]: 'Wait',
    [NODE_TYPES.IF_ELSE]: 'If / Else',
    [NODE_TYPES.END]: 'End workflow',
}

// The step picker's catalogue. Every entry is a registered node type; the
// keywords are only there so a search for "delay", "sms" or "branch" finds
// the step a person means. `group` is how the picker sections them, in this
// order; `requires` is the account capability the step needs (the server's
// WorkflowCapabilities) — a step the account cannot use is shown, disabled,
// with the reason, rather than offered and refused at publish.
export const STEP_GROUPS = ['Messaging', 'CRM', 'Documents', 'Payments', 'Internal', 'Flow']

export const STEP_CATALOG = [
    {
        type: NODE_TYPES.SEND_EMAIL,
        group: 'Messaging',
        description: 'Email the contact from your connected mailbox. Personalise it with their name.',
        keywords: ['email', 'mail', 'message', 'send', 'follow up', 'confirmation'],
        icon: 'mail',
        requires: 'email',
    },
    {
        type: NODE_TYPES.SEND_SMS,
        group: 'Messaging',
        description: 'Text the contact. Personalise it with their name.',
        keywords: ['sms', 'text', 'message', 'send', 'follow up', 'reminder'],
        icon: 'message-square-text',
        requires: 'sms',
    },
    {
        type: NODE_TYPES.SEND_BOOKING_LINK,
        group: 'Messaging',
        description: 'Send the link to book one of your booking types, by email, text, or both.',
        keywords: ['booking', 'book', 'appointment', 'schedule', 'calendar', 'link'],
        icon: 'calendar-plus',
        requires: 'calendar',
    },
    {
        type: NODE_TYPES.SEND_FORM,
        group: 'Messaging',
        description: 'Send the link to one of your forms, by email, text, or both.',
        keywords: ['form', 'link', 'collect', 'intake', 'enquiry'],
        icon: 'clipboard-list',
        requires: 'forms',
    },
    {
        type: NODE_TYPES.SEND_QUESTIONNAIRE,
        group: 'Messaging',
        description: 'Send the link to one of your multi-step questionnaires, by email, text, or both.',
        keywords: ['questionnaire', 'survey', 'questions', 'link', 'intake', 'feedback'],
        icon: 'list-checks',
        requires: 'forms',
    },
    {
        type: NODE_TYPES.ADD_TAG,
        group: 'CRM',
        description: 'Tag the contact so you can find, segment or follow up with them later.',
        keywords: ['tag', 'label', 'add', 'segment', 'mark'],
        icon: 'tag',
    },
    {
        type: NODE_TYPES.REMOVE_TAG,
        group: 'CRM',
        description: 'Take a tag off the contact.',
        keywords: ['tag', 'label', 'remove', 'untag', 'clear'],
        icon: 'tag',
    },
    {
        type: NODE_TYPES.UPDATE_CONTACT_FIELD,
        group: 'CRM',
        description: 'Save a value on the contact, such as a status or a note.',
        keywords: ['update', 'field', 'contact', 'save', 'set', 'status', 'note'],
        icon: 'user-pen',
    },
    {
        type: NODE_TYPES.MOVE_OPPORTUNITY,
        group: 'CRM',
        description: "Move the contact's opportunity to another stage of its pipeline.",
        keywords: ['opportunity', 'deal', 'stage', 'pipeline', 'move', 'crm', 'sales'],
        icon: 'arrow-right-left',
        requires: 'crm',
    },
    {
        type: NODE_TYPES.CREATE_SEND_PROPOSAL,
        group: 'Documents',
        description: 'Create a proposal or contract from one of your products or packages and email it for signing.',
        keywords: ['proposal', 'contract', 'quote', 'agreement', 'sign', 'document', 'send'],
        icon: 'file-signature',
        requires: 'documents',
    },
    {
        type: NODE_TYPES.REQUEST_PAYMENT,
        group: 'Payments',
        description: 'Email a secure payment link: for the document this workflow is about, or a new invoice.',
        keywords: ['payment', 'pay', 'invoice', 'deposit', 'charge', 'money', 'request'],
        icon: 'credit-card',
        requires: 'payments',
    },
    {
        type: NODE_TYPES.INTERNAL_NOTIFICATION,
        group: 'Internal',
        description: 'Let your team know something needs their attention.',
        keywords: ['notify', 'alert', 'team', 'staff', 'owner', 'tell'],
        icon: 'bell',
    },
    {
        type: NODE_TYPES.WAIT,
        group: 'Flow',
        description: 'Pause for a while, or until a set date and time.',
        keywords: ['wait', 'delay', 'pause', 'later', 'timer', 'days', 'hours', 'minutes'],
        icon: 'clock',
    },
    {
        type: NODE_TYPES.IF_ELSE,
        group: 'Flow',
        description: 'Split the path: one set of steps if something is true, another if not.',
        keywords: ['if', 'else', 'branch', 'condition', 'split', 'check', 'replied', 'decide'],
        icon: 'split',
    },
    {
        type: NODE_TYPES.END,
        group: 'Flow',
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
        case NODE_TYPES.SEND_EMAIL:
            return { subject: '', body: '' }
        case NODE_TYPES.ADD_TAG:
        case NODE_TYPES.REMOVE_TAG:
            return { tag_id: null }
        case NODE_TYPES.MOVE_OPPORTUNITY:
            return { pipeline_id: null, stage_id: null }
        case NODE_TYPES.SEND_BOOKING_LINK:
            return { booking_type_id: null, channels: ['email'], subject: '', message: '' }
        case NODE_TYPES.SEND_FORM:
        case NODE_TYPES.SEND_QUESTIONNAIRE:
            return { form_id: null, channels: ['email'], subject: '', message: '' }
        case NODE_TYPES.CREATE_SEND_PROPOSAL:
            return { title: '', catalog_item_id: null, quantity: 1, payment_schedule: 'full' }
        case NODE_TYPES.REQUEST_PAYMENT:
            return { source: 'invoice', title: '', catalog_item_id: null, quantity: 1 }
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

// Triggers with a real producer today (WorkflowTriggerType::isIngestableInThisSlice()),
// in the groups the picker shows them under. Documents, payments and questionnaires
// are the owning domain's own durable events, never a browser redirect.
export const TRIGGER_GROUPS = ['CRM', 'Messaging', 'Forms', 'Calendar', 'Documents', 'Payments', 'Manual / time']

export const TRIGGER_TYPES = [
    {
        value: 'contact_created',
        group: 'CRM',
        title: 'Contact is created',
        description: 'Starts when a new contact is added.',
        icon: 'user-plus',
        defaultPolicy: 'once_ever',
    },
    // CRM sales opportunities. Each defaults to "every time it happens": one
    // contact can have many deals, and a deal many moves.
    {
        value: 'opportunity_created',
        group: 'CRM',
        title: 'Opportunity created',
        description: 'Starts when a new opportunity is added for a contact.',
        icon: 'briefcase-business',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'opportunity_stage_changed',
        group: 'CRM',
        title: 'Opportunity moves stage',
        description: 'Starts when an opportunity moves to another stage.',
        icon: 'arrow-right-left',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'opportunity_won',
        group: 'CRM',
        title: 'Opportunity marked won',
        description: 'Starts when an opportunity is marked won.',
        icon: 'trophy',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'opportunity_lost',
        group: 'CRM',
        title: 'Opportunity marked lost',
        description: 'Starts when an opportunity is marked lost.',
        icon: 'circle-x',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'contact_tag_added',
        group: 'CRM',
        title: 'Tag added to a contact',
        description: 'Starts when a tag is added to a contact.',
        icon: 'tag',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'contact_tag_removed',
        group: 'CRM',
        title: 'Tag removed from a contact',
        description: 'Starts when a tag is taken off a contact.',
        icon: 'tag',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'message_received',
        group: 'Messaging',
        title: 'Customer sends a text',
        description: 'Starts when a contact texts your business.',
        icon: 'message-square-reply',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'form_submitted',
        group: 'Forms',
        title: 'Form submitted',
        description: 'Starts when someone finishes and submits one of your forms.',
        icon: 'clipboard-list',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'questionnaire_submitted',
        group: 'Forms',
        title: 'Questionnaire submitted',
        description: 'Starts when someone finishes one of your multi-step questionnaires.',
        icon: 'list-checks',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'appointment_scheduled',
        group: 'Calendar',
        title: 'Appointment booked',
        description: 'Starts when an appointment is booked for a contact.',
        icon: 'calendar-check',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'appointment_rescheduled',
        group: 'Calendar',
        title: 'Appointment rescheduled',
        description: 'Starts when an appointment is moved to another time.',
        icon: 'calendar-clock',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'appointment_cancelled',
        group: 'Calendar',
        title: 'Appointment cancelled',
        description: 'Starts when an appointment is cancelled.',
        icon: 'calendar-x',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'document_sent',
        group: 'Documents',
        title: 'Proposal or document sent',
        description: 'Starts when a proposal, contract or invoice is sent to a contact.',
        icon: 'file-text',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'document_signed',
        group: 'Documents',
        title: 'Proposal or document signed',
        description: 'Starts when a contact signs a proposal or contract.',
        icon: 'file-signature',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'payment_succeeded',
        group: 'Payments',
        title: 'Payment succeeded',
        description: 'Starts when a contact pays an invoice, a deposit or a balance.',
        icon: 'circle-check',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'payment_failed',
        group: 'Payments',
        title: 'Payment failed',
        description: "Starts when a contact's payment attempt is declined.",
        icon: 'circle-alert',
        defaultPolicy: 'once_per_occurrence',
    },
    {
        value: 'manual_enrollment',
        group: 'Manual / time',
        title: 'Added by hand',
        description: 'Starts only when you add a contact to it yourself.',
        icon: 'hand',
        defaultPolicy: 'once_ever',
    },
    {
        value: 'contact_date_reached',
        group: 'Manual / time',
        title: 'Contact date arrives',
        description: 'Starts on a date saved on the contact, such as a birthday.',
        icon: 'calendar-clock',
        defaultPolicy: 'once_per_occurrence',
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
