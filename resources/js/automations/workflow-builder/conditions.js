// Automations V2 (contract §11) — the closed condition-subject vocabulary this
// builder may offer, in customer words. It mirrors ConditionSubjectRegistry
// exactly: the four identity subjects, "subscribed", "in group", a group's
// custom fields, and V2-F's `contact.replied_since_enrollment` — offered now
// that the inbound producer it reads from has shipped. Nothing here is a
// Lead, Form, Payment or Tag subject, because none of those is registered.
//
// Operator families follow ConditionOperator exactly (forText, forBoolean,
// forDate, forReference). A custom field's family follows its own type the
// same way the compiler decides it: only a `date` field compares as a date.

export const REPLIED_SUBJECT = 'contact.replied_since_enrollment'
export const CUSTOM_FIELD_PREFIX = 'contact.custom_field:'

export const TEXT_SUBJECTS = ['contact.first_name', 'contact.last_name', 'contact.email', 'contact.company']
export const BOOLEAN_SUBJECTS = ['contact.subscribed', REPLIED_SUBJECT]
export const GROUP_SUBJECTS = ['contact.in_group']

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
}

const BOOLEAN_OPERATOR_LABELS = {
    'contact.subscribed': { is_true: 'is subscribed', is_false: 'is not subscribed' },
    [REPLIED_SUBJECT]: { is_true: 'has replied', is_false: 'has not replied yet' },
}

const OPERAND_FREE = ['is_empty', 'is_not_empty', 'is_true', 'is_false']

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

export function isDateSubject(subject, catalogs) {
    const field = fieldFor(subject, catalogs)

    return field !== null && field.type === 'date'
}

export function subjectOperators(subject, catalogs) {
    if (BOOLEAN_SUBJECTS.includes(subject)) {
        return BOOLEAN_OPERATORS
    }

    if (GROUP_SUBJECTS.includes(subject)) {
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

    const field = fieldFor(subject, catalogs)

    return field ? field.label : 'A contact field'
}

export function operatorLabel(subject, operator) {
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

    if (subject === 'contact.in_group') {
        return operator === 'not_equals'
            ? `Contact is not in ${groupName(condition.operand, catalogs)}`
            : `Contact is in ${groupName(condition.operand, catalogs)}`
    }

    const label = subjectLabel(subject, catalogs)
    const words = operatorLabel(subject, operator)

    if (!needsOperand(operator)) {
        return `${label} ${words}`
    }

    const operand = condition.operand === undefined || condition.operand === null || condition.operand === '' ? '…' : condition.operand

    return `${label} ${words} “${operand}”`
}
