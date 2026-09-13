// Automations V2 (contract §11, V2-D) — the closed condition-subject
// vocabulary this builder may offer. `contact.replied_since_enrollment` is
// deliberately absent: it needs V2-F's inbound producer, which has not
// shipped, so offering it here would let a customer build a workflow that
// can never evaluate that condition.
export const TEXT_SUBJECTS = ['contact.first_name', 'contact.last_name', 'contact.email', 'contact.company']
export const BOOLEAN_SUBJECTS = ['contact.subscribed']
export const GROUP_SUBJECTS = ['contact.in_group']

const TEXT_OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty']
const BOOLEAN_OPERATORS = ['is_true', 'is_false']
const REFERENCE_OPERATORS = ['equals', 'not_equals']
const DATE_OPERATORS = ['before', 'after', 'on_date', 'is_empty', 'is_not_empty']

export function subjectOperators(subject) {
    if (BOOLEAN_SUBJECTS.includes(subject)) {
        return BOOLEAN_OPERATORS
    }

    if (GROUP_SUBJECTS.includes(subject)) {
        return REFERENCE_OPERATORS
    }

    if (typeof subject === 'string' && subject.startsWith('contact.custom_field:')) {
        // A custom field's operators depend on its own type (date vs text);
        // the drawer does not currently carry field type metadata into this
        // pure lookup, so it offers the safe superset the server itself
        // validates against per field type at save time.
        return [...TEXT_OPERATORS, ...DATE_OPERATORS.filter((op) => !TEXT_OPERATORS.includes(op))]
    }

    return TEXT_OPERATORS
}
