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

// The triggers the cross-domain recipes start from. Each is "every time it
// happens", the default of its trigger, so the policy still says it is a default.
function occurrenceTrigger(type, extra) {
    return Object.assign(
        { trigger_type: type, enrollment_policy: 'once_per_occurrence', enrollment_policy_source: 'default', failure_policy: 'halt' },
        extra || {},
    )
}

// A recipe is only a starting DRAFT: it never runs until it is published. Anything
// that points at one of the Business's own resources (a pipeline and stage, a form, a
// product) starts empty — a recipe cannot know them — and the builder says exactly
// what is still to choose. `requires` names the account capabilities a recipe needs;
// the chooser shows a recipe the account cannot use disabled, with the reason.
export function listRecipes() {
    return [
        {
            key: 'welcome_new_contact',
            titleKey: 'welcome_new_contact',
            descriptionKey: 'welcome_new_contact_description',
            requires: ['sms'],
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
            requires: ['sms'],
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
            key: 'new_lead_follow_up',
            titleKey: 'new_lead_follow_up',
            descriptionKey: 'new_lead_follow_up_description',
            requires: ['email', 'sms'],
            build: () =>
                starter(occurrenceTrigger('form_submitted', { form_id: null }), [
                    newNode('send_email', { subject: 'Thanks for getting in touch', body: 'Hi {first_name},\n\nThanks for reaching out. We have your details and will be in touch shortly.' }),
                    newNode('wait', { mode: 'duration', amount: 1, unit: 'days' }),
                    newNode('send_sms', { body: 'Hi {first_name}, just checking you got our email. Any questions, reply here.' }),
                ]),
        },
        {
            key: 'booking_follow_up',
            titleKey: 'booking_follow_up',
            descriptionKey: 'booking_follow_up_description',
            requires: ['crm', 'email'],
            build: () =>
                starter(occurrenceTrigger('appointment_scheduled'), [
                    newNode('move_opportunity', { pipeline_id: null, stage_id: null }),
                    newNode('send_email', { subject: 'Your appointment is booked', body: 'Hi {first_name},\n\nYour appointment is booked. We look forward to seeing you.' }),
                ]),
        },
        {
            key: 'proposal_follow_up',
            titleKey: 'proposal_follow_up',
            descriptionKey: 'proposal_follow_up_description',
            requires: ['documents', 'email'],
            build: () => {
                const wait = newNode('wait', { mode: 'duration', amount: 3, unit: 'days' })
                const branch = newNode('if_else', {
                    match: 'all',
                    conditions: [{ subject: 'document.signed', operator: 'is_false' }],
                })
                branch.yes = [newNode('send_email', { subject: 'A reminder about your proposal', body: 'Hi {first_name},\n\nJust a reminder that your proposal is waiting for your signature. Reply if you have any questions.' })]
                branch.no = [newNode('end', {})]

                return starter(occurrenceTrigger('document_sent', { document_kind: 'proposal' }), [wait, branch])
            },
        },
        {
            key: 'signed_to_payment',
            titleKey: 'signed_to_payment',
            descriptionKey: 'signed_to_payment_description',
            requires: ['payments'],
            build: () =>
                starter(occurrenceTrigger('document_signed', { document_kind: 'proposal' }), [
                    newNode('request_payment', { source: 'document' }),
                ]),
        },
        {
            key: 'payment_complete',
            titleKey: 'payment_complete',
            descriptionKey: 'payment_complete_description',
            requires: ['crm', 'forms'],
            build: () =>
                starter(occurrenceTrigger('payment_succeeded'), [
                    newNode('move_opportunity', { pipeline_id: null, stage_id: null }),
                    newNode('send_questionnaire', { form_id: null, channels: ['email'], subject: '', message: '' }),
                    newNode('internal_notification', { message: 'Payment received from {first_name} {last_name}' }),
                ]),
        },
        {
            key: 'date_reminder',
            titleKey: 'date_reminder',
            descriptionKey: 'date_reminder_description',
            requires: ['sms'],
            build: () =>
                starter(Object.assign({}, TRIGGER_DEFAULTS.contact_date_reached), [
                    newNode('send_sms', { body: 'Hi {first_name}, just a friendly reminder from {business_name}!' }),
                ]),
        },
    ]
}
