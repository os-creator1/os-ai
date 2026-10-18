// Automations V2 (contract §11) — the closed condition-subject vocabulary this
// builder may offer, in customer words. It mirrors ConditionSubjectRegistry
// exactly: the four identity subjects, "subscribed", "in group", a group's
// custom fields, V2-F's `contact.replied_since_enrollment` — offered now
// that the inbound producer it reads from has shipped — and the Business's
// own tags (`contact.has_tag:{id}`, "has tag" / "does not have tag").
// Nothing here is a Lead, Form or Payment subject, because none of those is
// registered.
//
// Operator families follow ConditionOperator exactly (forText, forBoolean,
// forDate, forReference). A custom field's family follows its own type the
// same way the compiler decides it: only a `date` field compares as a date.

export const REPLIED_SUBJECT = 'contact.replied_since_enrollment'
export const CUSTOM_FIELD_PREFIX = 'contact.custom_field:'
// A Business-wide Custom Field, by its stable key (same key as {{contact.<key>}}).
// The only custom-field vocabulary the Builder offers for NEW conditions; the
// legacy `contact.custom_field:{id}` above is still read and shown for
// workflows that already use it.
export const BUSINESS_FIELD_PREFIX = 'contact.field:'
export const HAS_TAG_PREFIX = 'contact.has_tag:'

export const TEXT_SUBJECTS = ['contact.first_name', 'contact.last_name', 'contact.email', 'contact.company']
export const BOOLEAN_SUBJECTS = ['contact.subscribed', REPLIED_SUBJECT, 'document.signed', 'document.paid']
export const GROUP_SUBJECTS = ['contact.in_group']

// The subjects that read the deal, document, payment or appointment behind a
// journey (ConditionSubjectRegistry::FACT_SUBJECTS). Each has a closed set of
// values (or, for the stage, a stage of this Business) and the trigger family that
// can give it something to read.
export const STAGE_SUBJECT = 'opportunity.stage'
export const FACT_SUBJECTS = {
    [STAGE_SUBJECT]: { label: 'Opportunity stage', kind: 'reference', needs: null },
    'opportunity.status': {
        label: 'Opportunity status',
        kind: 'choice',
        needs: null,
        values: { open: 'Open', won: 'Won', lost: 'Lost' },
    },
    'document.status': {
        label: 'Document status',
        kind: 'choice',
        needs: 'document',
        values: { draft: 'Draft', sent: 'Sent', signed: 'Signed', paid: 'Paid', expired: 'Expired', void: 'Void' },
    },
    'document.signed': { label: 'Document signed', kind: 'boolean', needs: 'document' },
    'document.paid': { label: 'Document paid', kind: 'boolean', needs: 'document' },
    'payment.status': {
        label: 'Payment status',
        kind: 'choice',
        needs: 'payment',
        values: { succeeded: 'Succeeded', failed: 'Failed', processing: 'Processing', requires_action: 'Needs customer action', created: 'Started', canceled: 'Cancelled' },
    },
    'appointment.status': {
        label: 'Appointment status',
        kind: 'choice',
        needs: 'appointment',
        values: { scheduled: 'Scheduled', cancelled: 'Cancelled', completed: 'Completed', no_show: 'No-show' },
    },
}

const DOCUMENT_TRIGGERS = ['document_sent', 'document_signed', 'payment_succeeded', 'payment_failed']

/** Whether the workflow's trigger gives this fact subject something to read. */
export function factSubjectAvailable(subject, triggerType) {
    const meta = FACT_SUBJECTS[subject]

    if (!meta || meta.needs === null) {
        return true
    }

    if (meta.needs === 'document') {
        return DOCUMENT_TRIGGERS.includes(triggerType)
    }

    if (meta.needs === 'payment') {
        return ['payment_succeeded', 'payment_failed'].includes(triggerType)
    }

    return ['appointment_scheduled', 'appointment_cancelled', 'appointment_rescheduled'].includes(triggerType)
}

export const SUBJECT_LABELS = {
    [REPLIED_SUBJECT]: 'Customer replied',
    'contact.first_name': 'First name',
    'contact.last_name': 'Last name',
    'contact.email': 'Email',
    'contact.company': 'Company',
    'contact.subscribed': 'Subscribed to texts',
    'contact.in_group': 'Contact group',
}

const TEXT_OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty']
const BOOLEAN_OPERATORS = ['is_true', 'is_false']
const REFERENCE_OPERATORS = ['equals', 'not_equals']
const DATE_OPERATORS = ['before', 'after', 'on_date', 'is_empty', 'is_not_empty']
const NUMBER_OPERATORS = ['equals', 'not_equals', 'greater_than', 'less_than', 'is_empty', 'is_not_empty']
const SELECT_OPERATORS = ['equals', 'not_equals', 'is_empty', 'is_not_empty']
const MULTI_OPERATORS = ['contains', 'not_contains', 'is_empty', 'is_not_empty']

const OPERATOR_LABELS = {
    equals: 'is',
    not_equals: 'is not',
    contains: 'contains',
    not_contains: 'does not contain',
    is_empty: 'is empty',
    is_not_empty: 'is not empty',
    before: 'is before',
    after: 'is after',
    on_date: 'is on',
    greater_than: 'is greater than',
    less_than: 'is less than',
}

const BOOLEAN_OPERATOR_LABELS = {
    'document.signed': { is_true: 'is signed', is_false: 'is not signed yet' },
    'document.paid': { is_true: 'is paid', is_false: 'is not paid yet' },
    'contact.subscribed': { is_true: 'is subscribed', is_false: 'is not subscribed' },
    [REPLIED_SUBJECT]: { is_true: 'has replied', is_false: 'has not replied yet' },
}

const OPERAND_FREE = ['is_empty', 'is_not_empty', 'is_true', 'is_false']

/** The tag id in a `contact.has_tag:{id}` subject, or null when it is not one. */
export function tagId(subject) {
    if (typeof subject !== 'string' || !subject.startsWith(HAS_TAG_PREFIX)) {
        return null
    }

    const raw = subject.slice(HAS_TAG_PREFIX.length)

    return /^\d+$/.test(raw) && Number(raw) > 0 ? Number(raw) : null
}

function tagFor(subject, catalogs) {
    const id = tagId(subject)

    if (id === null || !catalogs) {
        return null
    }

    return (catalogs.tags || []).find((tag) => Number(tag.id) === id) || null
}

export function customFieldId(subject) {
    if (typeof subject !== 'string' || !subject.startsWith(CUSTOM_FIELD_PREFIX)) {
        return null
    }

    const raw = subject.slice(CUSTOM_FIELD_PREFIX.length)

    return /^\d+$/.test(raw) && Number(raw) > 0 ? Number(raw) : null
}

function fieldFor(subject, catalogs) {
    const id = customFieldId(subject)

    if (id === null || !catalogs) {
        return null
    }

    return (catalogs.writableFields || []).find((field) => Number(field.id) === id) || null
}

/** The key in a `contact.field:{key}` subject, or null when it is not one. */
export function businessFieldKey(subject) {
    if (typeof subject !== 'string' || !subject.startsWith(BUSINESS_FIELD_PREFIX)) {
        return null
    }

    const raw = subject.slice(BUSINESS_FIELD_PREFIX.length)

    return /^[a-z][a-z0-9_]{0,39}$/.test(raw) ? raw : null
}

/** The Business-wide Custom Field a subject names, from this page's catalog (or null). */
export function businessFieldFor(subject, catalogs) {
    const key = businessFieldKey(subject)

    if (key === null || !catalogs) {
        return null
    }

    return (catalogs.customFields || []).find((field) => field.key === key) || null
}

/** family: text | number | date | boolean | select | multi — null for any other subject. */
function businessFieldFamily(subject, catalogs) {
    const field = businessFieldFor(subject, catalogs)

    return field ? field.family : null
}

/** A dropdown / multi-select custom field: its operand is one of its own options. */
export function optionSubject(subject, catalogs) {
    const field = businessFieldFor(subject, catalogs)

    return field && (field.family === 'select' || field.family === 'multi') ? field : null
}

export function isNumberSubject(subject, catalogs) {
    return businessFieldFamily(subject, catalogs) === 'number'
}

export function isDateSubject(subject, catalogs) {
    if (businessFieldFamily(subject, catalogs) === 'date') {
        return true
    }

    const field = fieldFor(subject, catalogs)

    return field !== null && field.type === 'date'
}

export function subjectOperators(subject, catalogs) {
    if (BOOLEAN_SUBJECTS.includes(subject) || tagId(subject) !== null) {
        return BOOLEAN_OPERATORS
    }

    if (businessFieldKey(subject) !== null) {
        const family = businessFieldFamily(subject, catalogs)

        if (family === 'number') return NUMBER_OPERATORS
        if (family === 'date') return DATE_OPERATORS
        if (family === 'boolean') return BOOLEAN_OPERATORS
        if (family === 'select') return SELECT_OPERATORS
        if (family === 'multi') return MULTI_OPERATORS

        return TEXT_OPERATORS
    }

    if (GROUP_SUBJECTS.includes(subject) || (FACT_SUBJECTS[subject] && FACT_SUBJECTS[subject].kind !== 'boolean')) {
        return REFERENCE_OPERATORS
    }

    if (customFieldId(subject) !== null) {
        return isDateSubject(subject, catalogs) ? DATE_OPERATORS : TEXT_OPERATORS
    }

    return TEXT_OPERATORS
}

export function needsOperand(operator) {
    return !OPERAND_FREE.includes(operator)
}

export function subjectLabel(subject, catalogs) {
    if (SUBJECT_LABELS[subject]) {
        return SUBJECT_LABELS[subject]
    }

    if (FACT_SUBJECTS[subject]) {
        return FACT_SUBJECTS[subject].label
    }

    if (tagId(subject) !== null) {
        const tag = tagFor(subject, catalogs)

        return tag ? `Tag “${tag.name}”` : 'A tag'
    }

    const businessField = businessFieldFor(subject, catalogs)

    if (businessField) {
        return businessField.label
    }

    const field = fieldFor(subject, catalogs)

    return field ? field.label : 'A contact field'
}

export function operatorLabel(subject, operator, catalogs) {
    const family = businessFieldFamily(subject, catalogs)

    if (family === 'boolean') {
        return operator === 'is_true' ? 'is Yes' : 'is No'
    }

    if (family === 'multi') {
        if (operator === 'contains') return 'includes'
        if (operator === 'not_contains') return 'does not include'
    }

    if (tagId(subject) !== null && (operator === 'is_true' || operator === 'is_false')) {
        return operator === 'is_true' ? 'is on the contact' : 'is not on the contact'
    }

    const booleanLabels = BOOLEAN_OPERATOR_LABELS[subject]

    if (booleanLabels && booleanLabels[operator]) {
        return booleanLabels[operator]
    }

    return OPERATOR_LABELS[operator] || operator.replace(/_/g, ' ')
}

function groupName(id, catalogs) {
    const group = ((catalogs && catalogs.contactGroups) || []).find((row) => String(row.id) === String(id))

    return group ? group.name : 'a group'
}

/** One condition in a sentence: "Customer has not replied yet", "First name is “Sam”". */
export function describeCondition(condition, catalogs) {
    if (!condition || !condition.subject || !condition.operator) {
        return 'An unfinished condition'
    }

    const { subject, operator } = condition

    if (subject === REPLIED_SUBJECT) {
        return `Customer ${operatorLabel(subject, operator)}`
    }

    if (subject === 'contact.subscribed') {
        return `Contact ${operatorLabel(subject, operator)}`
    }

    if (tagId(subject) !== null) {
        const tag = tagFor(subject, catalogs)
        const name = tag ? `“${tag.name}”` : 'a tag'

        return operator === 'is_false' ? `Contact does not have the tag ${name}` : `Contact has the tag ${name}`
    }

    if (FACT_SUBJECTS[subject]) {
        const meta = FACT_SUBJECTS[subject]

        if (meta.kind === 'boolean') {
            return `${meta.label.replace(/ (signed|paid)$/, '')} ${operatorLabel(subject, operator)}`
        }

        const operand = meta.kind === 'reference'
            ? ((catalogs && catalogs.crmStages) || []).find((row) => String(row.id) === String(condition.operand))?.name
            : meta.values[condition.operand]

        return `${meta.label} ${operatorLabel(subject, operator)} “${operand || '…'}”`
    }

    if (subject === 'contact.in_group') {
        return operator === 'not_equals'
            ? `Contact is not in ${groupName(condition.operand, catalogs)}`
            : `Contact is in ${groupName(condition.operand, catalogs)}`
    }

    const label = subjectLabel(subject, catalogs)
    const words = operatorLabel(subject, operator, catalogs)

    if (!needsOperand(operator)) {
        return `${label} ${words}`
    }

    let operand = condition.operand === undefined || condition.operand === null || condition.operand === '' ? '…' : condition.operand
    const optionField = optionSubject(subject, catalogs)

    if (optionField) {
        // The stored operand is an option's stable id; the sentence shows its name.
        const option = (optionField.options || []).find((row) => row.id === operand)
        operand = option ? option.label : '…'
    }

    return `${label} ${words} “${operand}”`
}
