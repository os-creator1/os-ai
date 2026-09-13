// Automations V2 (contract §13.3, task requirement, V2-D) — recipe
// templates. A recipe is ONLY a starting document: each factory below
// produces the exact same §5.3 nested-list shape a hand-built workflow
// would, using nothing but the launch node/trigger vocabulary
// (constants.js). There is no second engine, no recipe-specific runtime,
// and no domain (Forms/Calendar/Payments/Pipeline/Tags) this contract does
// not support — every recipe here is mechanically re-derivable from
// NODE_TYPES alone.
import { newNode } from './document-model.js'

function starter(triggerConfig, body) {
    const root = newNode('trigger', triggerConfig)
    root.next = body

    return { schema_version: 1, root }
}

const TRIGGER_DEFAULTS = {
    contact_created: {
        trigger_type: 'contact_created',
        source: 'any',
        contact_group_id: null,
        enrollment_policy: 'once_ever',
        enrollment_policy_source: 'default',
        failure_policy: 'halt',
    },
    contact_date_reached: {
        trigger_type: 'contact_date_reached',
        contact_group_id: null,
        date_field_id: null,
        offset: '0 day',
        send_at: '09:00',
        enrollment_policy: 'once_per_occurrence',
        enrollment_policy_source: 'default',
        failure_policy: 'halt',
    },
}

export function listRecipes() {
    return [
        {
            key: 'welcome_new_contact',
            titleKey: 'welcome_new_contact',
            descriptionKey: 'welcome_new_contact_description',
            build: () =>
                starter(Object.assign({}, TRIGGER_DEFAULTS.contact_created), [
                    newNode('send_sms', { body: 'Hi {first_name}, thanks for reaching out! We will be in touch shortly.' }),
                ]),
        },
        {
            key: 'notify_team_new_contact',
            titleKey: 'notify_team_new_contact',
            descriptionKey: 'notify_team_new_contact_description',
            build: () =>
                starter(Object.assign({}, TRIGGER_DEFAULTS.contact_created), [
                    newNode('internal_notification', { message: 'New contact: {first_name} {last_name}' }),
                ]),
        },
        {
            key: 'check_in_after_days',
            titleKey: 'check_in_after_days',
            descriptionKey: 'check_in_after_days_description',
            build: () => {
                const wait = newNode('wait', { mode: 'duration', amount: 2, unit: 'days' })
                const branch = newNode('if_else', {
                    match: 'all',
                    conditions: [{ subject: 'contact.subscribed', operator: 'is_true' }],
                })
                branch.yes = [newNode('send_sms', { body: 'Hi {first_name}, just checking in — still interested?' })]
                branch.no = [newNode('end', {})]

                // A straight chain is one flat sibling array (contract §5.3's
                // own example: n_1/n_2/n_3 all sit directly under root.next),
                // never nesting via one step's own `next` — the canvas
                // renderer and every insert/delete/move helper share that
                // one invariant throughout this module.
                return starter(Object.assign({}, TRIGGER_DEFAULTS.contact_created), [wait, branch])
            },
        },
        {
            key: 'date_reminder',
            titleKey: 'date_reminder',
            descriptionKey: 'date_reminder_description',
            build: () =>
                starter(Object.assign({}, TRIGGER_DEFAULTS.contact_date_reached), [
                    newNode('send_sms', { body: 'Hi {first_name}, just a friendly reminder from {business_name}!' }),
                ]),
        },
    ]
}
