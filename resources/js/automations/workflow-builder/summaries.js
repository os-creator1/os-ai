// Automations V2 (contract §13.1) — what each card SAYS.
//
// A card on the canvas is only useful if it tells a person what the step does
// for this contact, in plain language, without opening it: "Wait 2 days",
// "Text: “Hi {first_name}…”", "If the customer has not replied yet". This
// module turns a node's stored config into that sentence. It reads only the
// document and the Business catalogs the page was handed; it never shows an
// identifier, and an unfinished step reads as the thing still to do.
import { NODE_TYPES, CONTACT_SOURCE_LABELS, triggerTypeInfo } from './constants.js'
import { customFieldId, describeCondition } from './conditions.js'

const EXCERPT_LENGTH = 90

function excerpt(text) {
    const clean = String(text || '').replace(/\s+/g, ' ').trim()

    return clean.length > EXCERPT_LENGTH ? `${clean.slice(0, EXCERPT_LENGTH - 1)}…` : clean
}

function groupName(catalogs, id) {
    const group = (catalogs.contactGroups || []).find((row) => String(row.id) === String(id))

    return group ? group.name : null
}

function fieldLabel(rows, id) {
    const field = (rows || []).find((row) => String(row.id) === String(id))

    return field ? field.label : null
}

function plural(amount, unit) {
    const singular = unit.replace(/s$/, '')

    return Number(amount) === 1 ? `1 ${singular}` : `${amount} ${unit}`
}

function formatMoment(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''))

    if (!match) {
        return null
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]))

    return date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

function formatTime(value) {
    const match = /^(\d{2}):(\d{2})$/.exec(String(value || ''))

    if (!match) {
        return null
    }

    const date = new Date(2000, 0, 1, Number(match[1]), Number(match[2]))

    return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
}

/**
 * @returns {{ title: string, summary: string, incomplete: boolean }}
 */
export function summarize(node, catalogs) {
    const config = node.config || {}

    switch (node.type) {
        case NODE_TYPES.TRIGGER:
            return summarizeTrigger(config, catalogs)

        case NODE_TYPES.SEND_SMS:
            return config.body && String(config.body).trim() !== ''
                ? { summary: `“${excerpt(config.body)}”`, incomplete: false }
                : { summary: 'Write the message to send', incomplete: true }

        case NODE_TYPES.INTERNAL_NOTIFICATION:
            return config.message && String(config.message).trim() !== ''
                ? { summary: `“${excerpt(config.message)}”`, incomplete: false }
                : { summary: 'Write what your team should be told', incomplete: true }

        case NODE_TYPES.UPDATE_CONTACT_FIELD: {
            const label = fieldLabel(catalogs.writableFields, config.field_id)

            if (!label) {
                return { summary: 'Choose a field to update', incomplete: true }
            }

            return String(config.value || '') === ''
                ? { summary: `Clear ${label}`, incomplete: false }
                : { summary: `Set ${label} to “${excerpt(config.value)}”`, incomplete: false }
        }

        case NODE_TYPES.WAIT:
            if (config.mode === 'until_datetime') {
                const moment = formatMoment(config.at)

                return moment
                    ? { summary: `Until ${moment}`, incomplete: false }
                    : { summary: 'Choose when to continue', incomplete: true }
            }

            return Number(config.amount) > 0 && config.unit
                ? { summary: `Wait ${plural(config.amount, config.unit)}`, incomplete: false }
                : { summary: 'Choose how long to wait', incomplete: true }

        case NODE_TYPES.IF_ELSE: {
            const conditions = Array.isArray(config.conditions) ? config.conditions : []

            if (conditions.length === 0) {
                return { summary: 'Add a condition to check', incomplete: true }
            }

            const joiner = config.match === 'any' ? ' or ' : ' and '
            // Read as one sentence after "If": built-in subjects go lower-case
            // ("If customer has not replied yet and first name is …"), while a
            // custom field keeps the capitals its Business gave its label.
            const sentence = conditions
                .map((condition) => {
                    const text = describeCondition(condition, catalogs)

                    return customFieldId(condition && condition.subject) === null ? text.charAt(0).toLowerCase() + text.slice(1) : text
                })
                .join(joiner)

            return { summary: `If ${sentence}`, incomplete: false }
        }

        case NODE_TYPES.END:
            return { summary: 'The workflow stops here for this contact', incomplete: false }

        default:
            return { summary: '', incomplete: false }
    }
}

function summarizeTrigger(config, catalogs) {
    const info = triggerTypeInfo(config.trigger_type)

    if (!info) {
        return { title: 'Trigger', summary: 'Choose what starts this workflow', incomplete: true }
    }

    const group = groupName(catalogs, config.contact_group_id)

    switch (info.value) {
        case 'contact_created': {
            const when = group ? `When a contact is added to ${group}` : 'When any new contact is added'
            const source = config.source && config.source !== 'any' ? ` · ${CONTACT_SOURCE_LABELS[config.source] || ''}` : ''

            return { title: info.title, summary: `${when}${source}`, incomplete: false }
        }

        case 'message_received':
            return { title: info.title, summary: 'When a contact texts your business', incomplete: false }

        case 'contact_date_reached': {
            const field = fieldLabel(catalogs.dateFields, config.date_field_id)

            if (!group || !field) {
                return { title: info.title, summary: 'Choose a group and a date field', incomplete: true }
            }

            const time = formatTime(config.send_at)
            const when = !config.offset || config.offset === '0 day' ? 'On' : `${config.offset} before`

            return {
                title: info.title,
                summary: `${when} ${field} · ${group}${time ? ` · at ${time}` : ''}`,
                incomplete: false,
            }
        }

        case 'manual_enrollment':
            return { title: info.title, summary: 'When you add a contact to this workflow', incomplete: false }

        default:
            return { title: info.title, summary: '', incomplete: false }
    }
}
