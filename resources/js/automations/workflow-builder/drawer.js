// Automations V2 (contract §13.1, §14.2, V2-D) — the step inspector.
//
// A panel docked to the right of the canvas, so the workflow stays visible and
// the selected card stays highlighted while it is being configured. Its body is
// populated per node type from the hidden `<template>` partials the Blade view
// rendered server-side (contract §13.1: "one server-rendered form partial per
// node type"). This module never invents a field NodeTypeRegistry does not
// validate, and every option list it offers (contact groups, writable fields,
// date fields) comes only from the `catalogs` this Business's page was handed —
// never another Business's row.
import { NODE_LABELS, NODE_ICONS, STEP_CATALOG, TRIGGER_TYPES, offsetLabel, triggerTypeInfo } from './constants.js'
import { GROUP_SUBJECTS, REPLIED_SUBJECT, isDateSubject, needsOperand, operatorLabel, subjectOperators } from './conditions.js'
import { el, icon } from './dom.js'

function fillSelect(select, options, valueKey, labelKey, placeholder) {
    select.innerHTML = ''

    if (placeholder) {
        const opt = el('option', null, placeholder)
        opt.value = ''
        select.appendChild(opt)
    }

    options.forEach((row) => {
        const opt = el('option', null, row[labelKey])
        opt.value = String(row[valueKey])
        select.appendChild(opt)
    })
}

/** Fields grouped under their contact group's name, so two "Notes" fields are told apart. */
function fillFieldSelect(select, fields, groups, placeholder) {
    select.innerHTML = ''

    const empty = el('option', null, placeholder)
    empty.value = ''
    select.appendChild(empty)

    groups.forEach((group) => {
        const rows = fields.filter((field) => String(field.contact_group_id) === String(group.id))

        if (rows.length === 0) {
            return
        }

        const optgroup = el('optgroup')
        optgroup.label = group.name
        rows.forEach((field) => {
            const opt = el('option', null, field.label)
            opt.value = String(field.id)
            optgroup.appendChild(opt)
        })
        select.appendChild(optgroup)
    })
}

function attachCounter(field, counterEl, max) {
    if (!field || !counterEl) {
        return
    }

    const sync = () => {
        const length = field.value.length
        counterEl.textContent = `${length} / ${max}`
        counterEl.classList.toggle('is-over', length > max)
    }

    field.addEventListener('input', sync)
    sync()
}

export function createDrawer({ drawerEl, catalogs, dateOffsets, limits, onSave, onDelete, onClose }) {
    const iconEl = drawerEl.querySelector('[data-role="wf-drawer-icon"]')
    const eyebrowEl = drawerEl.querySelector('[data-role="wf-drawer-eyebrow"]')
    const titleEl = drawerEl.querySelector('[data-role="wf-drawer-title"]')
    const descriptionEl = drawerEl.querySelector('[data-role="wf-drawer-description"]')
    const formEl = drawerEl.querySelector('[data-role="wf-drawer-form"]')
    const errorsEl = drawerEl.querySelector('[data-role="wf-drawer-errors"]')
    const saveButton = drawerEl.querySelector('[data-role="wf-drawer-save"]')
    const cancelButton = drawerEl.querySelector('[data-role="wf-drawer-cancel"]')
    const closeButton = drawerEl.querySelector('[data-role="wf-drawer-close"]')
    const deleteButton = drawerEl.querySelector('[data-role="wf-drawer-delete"]')

    let currentNode = null
    let readOnly = false

    function open(node, errorMessages, options = {}) {
        currentNode = node
        readOnly = Boolean(options.readOnly)

        const catalogEntry = STEP_CATALOG.find((entry) => entry.type === node.type)

        iconEl.innerHTML = ''
        iconEl.appendChild(icon(NODE_ICONS[node.type] || 'zap', `wf-tone wf-tone--${node.type}`))
        eyebrowEl.textContent = node.type === 'trigger' ? 'Trigger' : 'Step'
        titleEl.textContent = node.type === 'trigger' ? 'What starts this workflow' : NODE_LABELS[node.type]
        descriptionEl.textContent = node.type === 'trigger'
            ? 'Choose the moment a contact enters this workflow.'
            : (catalogEntry && catalogEntry.description) || ''

        formEl.innerHTML = ''
        const template = document.getElementById(`wf-node-form-${node.type}`)

        if (template) {
            formEl.appendChild(template.content.cloneNode(true))
        }

        populate(node)
        showErrors(errorMessages || [])

        deleteButton.hidden = node.type === 'trigger' || readOnly
        saveButton.hidden = readOnly
        cancelButton.textContent = readOnly ? 'Close' : 'Cancel'
        formEl.querySelectorAll('input, select, textarea, button').forEach((control) => {
            control.disabled = control.disabled || readOnly
        })

        drawerEl.hidden = false

        const first = formEl.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])')

        if (first && !options.keepFocus) {
            first.focus({ preventScroll: true })
        }
    }

    function close() {
        if (drawerEl.hidden) {
            return
        }

        drawerEl.hidden = true
        const node = currentNode
        currentNode = null

        if (onClose) {
            onClose(node)
        }
    }

    function isOpen() {
        return !drawerEl.hidden
    }

    function showErrors(messages) {
        errorsEl.innerHTML = ''

        if (!messages || messages.length === 0) {
            errorsEl.classList.add('d-none')

            return
        }

        errorsEl.classList.remove('d-none')
        messages.forEach((message) => errorsEl.appendChild(el('p', 'mb-0', message)))
    }

    function populate(node) {
        if (node.type === 'trigger') {
            populateTrigger(node)
        } else if (node.type === 'if_else') {
            populateIfElse(node)
        } else if (node.type === 'update_contact_field') {
            populateUpdateContactField(node)
        } else if (node.type === 'wait') {
            populateWait(node)
        } else {
            populateGeneric(node)
        }
    }

    function populateGeneric(node) {
        formEl.querySelectorAll('[data-field]').forEach((field) => {
            const value = node.config[field.dataset.field]

            if (value !== undefined && value !== null) {
                field.value = value
            }
        })

        attachCounter(formEl.querySelector('[data-field="body"]'), formEl.querySelector('[data-role="wf-body-counter"]'), 1600)
        attachCounter(formEl.querySelector('[data-field="message"]'), formEl.querySelector('[data-role="wf-message-counter"]'), 255)

        // Merge tags insert at the cursor rather than being typed from memory.
        formEl.querySelectorAll('[data-insert]').forEach((chip) => {
            chip.addEventListener('click', () => {
                const target = formEl.querySelector('[data-field="body"], [data-field="message"]')

                if (!target) {
                    return
                }

                const start = target.selectionStart ?? target.value.length
                const end = target.selectionEnd ?? target.value.length
                target.value = target.value.slice(0, start) + chip.dataset.insert + target.value.slice(end)
                target.focus()
                target.selectionStart = target.selectionEnd = start + chip.dataset.insert.length
                target.dispatchEvent(new Event('input'))
            })
        })
    }

    function populateWait(node) {
        const modeInputs = formEl.querySelectorAll('input[name="wf-wait-mode"]')
        const durationFields = formEl.querySelector('[data-role="wf-wait-duration-fields"]')
        const datetimeFields = formEl.querySelector('[data-role="wf-wait-datetime-fields"]')

        const mode = node.config.mode === 'until_datetime' ? 'until_datetime' : 'duration'
        modeInputs.forEach((input) => {
            input.checked = input.value === mode
        })

        formEl.querySelector('[data-field="amount"]').value = node.config.amount != null ? node.config.amount : 1
        formEl.querySelector('[data-field="unit"]').value = node.config.unit || 'days'
        // The server accepts "YYYY-MM-DD HH:MM" or the T form a datetime-local input writes.
        formEl.querySelector('[data-field="at"]').value = node.config.at ? String(node.config.at).replace(' ', 'T').slice(0, 16) : ''

        function sync() {
            const isDuration = formEl.querySelector('input[name="wf-wait-mode"]:checked').value === 'duration'
            durationFields.hidden = !isDuration
            datetimeFields.hidden = isDuration
        }

        modeInputs.forEach((input) => input.addEventListener('change', sync))
        sync()
    }

    function populateUpdateContactField(node) {
        const select = formEl.querySelector('[data-role="wf-writable-field-select"]')
        fillFieldSelect(select, catalogs.writableFields, catalogs.contactGroups, 'Choose a field')

        select.value = node.config.field_id != null ? String(node.config.field_id) : ''
        formEl.querySelector('[data-field="value"]').value = node.config.value != null ? node.config.value : ''
    }

    function populateTrigger(node) {
        const typeInputs = formEl.querySelectorAll('input[name="wf-trigger-type"]')
        const sections = formEl.querySelectorAll('[data-trigger-section]')
        const groupSelect = formEl.querySelector('[data-role="wf-contact-group-select"]')
        const dateGroupSelect = formEl.querySelector('[data-role="wf-date-contact-group-select"]')
        const dateFieldSelect = formEl.querySelector('[data-role="wf-date-field-select"]')
        const offsetSelect = formEl.querySelector('[data-role="wf-offset-select"]')
        const sourceSelect = formEl.querySelector('select[data-field="source"]')
        const policySelect = formEl.querySelector('[data-role="wf-enrollment-policy"]')
        const policySourceInput = formEl.querySelector('[data-field="enrollment_policy_source"]')
        const confirmNote = formEl.querySelector('[data-role="wf-policy-confirm-note"]')

        fillSelect(groupSelect, catalogs.contactGroups, 'id', 'name', 'Any group')
        fillSelect(dateGroupSelect, catalogs.contactGroups, 'id', 'name', 'Choose a group')
        fillSelect(offsetSelect, dateOffsets.map((value) => ({ value, label: offsetLabel(value) })), 'value', 'label', null)

        const selectedType = () => {
            const checked = formEl.querySelector('input[name="wf-trigger-type"]:checked')

            return checked ? checked.value : 'contact_created'
        }

        function refreshDateFieldOptions() {
            const rows = catalogs.dateFields.filter((f) => String(f.contact_group_id) === String(dateGroupSelect.value))
            fillSelect(dateFieldSelect, rows, 'id', 'label', rows.length ? 'Choose a date field' : 'This group has no date fields')
        }

        function syncVisibility() {
            const type = selectedType()
            sections.forEach((section) => {
                section.hidden = section.dataset.triggerSection !== type
            })
            formEl.querySelectorAll('.wf-choice').forEach((choice) => {
                choice.classList.toggle('is-checked', choice.querySelector('input').checked)
            })
        }

        function defaultPolicyFor(triggerType) {
            const info = triggerTypeInfo(triggerType)

            return info ? info.defaultPolicy : 'once_ever'
        }

        typeInputs.forEach((input) => {
            input.addEventListener('change', () => {
                syncVisibility()
                refreshDateFieldOptions()

                // Mirrors WorkflowDraftService::withTriggerChanged(): a policy
                // still carrying its trigger's DEFAULT origin follows the new
                // trigger silently; a customer's own explicit choice is left
                // alone, with a note rather than a silent override (§7.5).
                if (policySourceInput.value === 'default') {
                    policySelect.value = defaultPolicyFor(selectedType())
                    confirmNote.hidden = true
                } else {
                    confirmNote.hidden = policySelect.value === defaultPolicyFor(selectedType())
                }
            })
        })

        dateGroupSelect.addEventListener('change', refreshDateFieldOptions)

        policySelect.addEventListener('change', () => {
            // Any manual change is a deliberate customer choice from this
            // moment on.
            policySourceInput.value = 'user'
            confirmNote.hidden = true
        })

        const type = TRIGGER_TYPES.some((entry) => entry.value === node.config.trigger_type) ? node.config.trigger_type : 'contact_created'
        typeInputs.forEach((input) => {
            input.checked = input.value === type
        })

        sourceSelect.value = node.config.source || 'any'
        groupSelect.value = node.config.contact_group_id != null ? String(node.config.contact_group_id) : ''
        dateGroupSelect.value = node.config.contact_group_id != null ? String(node.config.contact_group_id) : ''
        refreshDateFieldOptions()
        dateFieldSelect.value = node.config.date_field_id != null ? String(node.config.date_field_id) : ''
        offsetSelect.value = node.config.offset || '0 day'
        formEl.querySelector('[data-field="send_at"]').value = node.config.send_at || '09:00'
        policySelect.value = node.config.enrollment_policy || defaultPolicyFor(type)
        policySourceInput.value = node.config.enrollment_policy_source || 'default'
        confirmNote.hidden = true
        syncVisibility()
    }

    function populateIfElse(node) {
        const matchSelect = formEl.querySelector('[data-field="match"]')
        matchSelect.value = node.config.match || 'all'

        const list = formEl.querySelector('[data-role="wf-conditions-list"]')
        const addButton = formEl.querySelector('[data-role="wf-add-condition"]')
        const limitNote = formEl.querySelector('[data-role="wf-condition-limit"]')
        const rowTemplate = document.getElementById('wf-if-else-condition-row')
        const maxConditions = (limits && limits.maxConditionsPerBranch) || 5

        function syncLimit() {
            const count = list.querySelectorAll('[data-role="wf-condition-row"]').length
            addButton.disabled = readOnly || count >= maxConditions
            limitNote.hidden = count < maxConditions
            list.querySelectorAll('[data-role="wf-condition-join"]').forEach((join, index) => {
                join.textContent = matchSelect.value === 'any' ? 'or' : 'and'
                join.hidden = index === 0
            })
        }

        function addRow(condition) {
            const fragment = rowTemplate.content.cloneNode(true)
            const row = fragment.querySelector('[data-role="wf-condition-row"]')
            const subjectSelect = row.querySelector('[data-role="wf-condition-subject"]')
            const customGroup = row.querySelector('[data-role="wf-condition-custom-field-group"]')
            const operatorSelect = row.querySelector('[data-role="wf-condition-operator"]')
            const operandWrap = row.querySelector('[data-role="wf-condition-operand-wrapper"]')
            const operandInput = row.querySelector('[data-role="wf-condition-operand"]')
            const operandGroupWrap = row.querySelector('[data-role="wf-condition-operand-group-wrapper"]')
            const operandGroupSelect = row.querySelector('[data-role="wf-condition-operand-group"]')
            const help = row.querySelector('[data-role="wf-condition-help"]')

            catalogs.writableFields.forEach((field) => {
                const opt = el('option', null, field.label)
                opt.value = `contact.custom_field:${field.id}`
                customGroup.appendChild(opt)
            })

            if (customGroup.children.length === 0) {
                customGroup.remove()
            }

            fillSelect(operandGroupSelect, catalogs.contactGroups, 'id', 'name', null)

            function syncOperators() {
                const subject = subjectSelect.value
                const previous = operatorSelect.value
                operatorSelect.innerHTML = ''
                subjectOperators(subject, catalogs).forEach((op) => {
                    const opt = el('option', null, operatorLabel(subject, op))
                    opt.value = op
                    operatorSelect.appendChild(opt)
                })

                if ([...operatorSelect.options].some((opt) => opt.value === previous)) {
                    operatorSelect.value = previous
                }

                help.hidden = subject !== REPLIED_SUBJECT
            }

            function syncOperand() {
                const subject = subjectSelect.value
                const takesValue = needsOperand(operatorSelect.value)
                const isGroup = GROUP_SUBJECTS.includes(subject)

                operandWrap.hidden = !takesValue || isGroup
                operandGroupWrap.hidden = !takesValue || !isGroup
                operandInput.type = isDateSubject(subject, catalogs) ? 'date' : 'text'
            }

            subjectSelect.addEventListener('change', () => {
                syncOperators()
                syncOperand()
            })
            operatorSelect.addEventListener('change', syncOperand)

            row.querySelector('[data-role="wf-remove-condition"]').addEventListener('click', () => {
                row.remove()
                syncLimit()
            })

            subjectSelect.value = condition.subject || REPLIED_SUBJECT

            if (subjectSelect.value === '') {
                // A stored subject this Business no longer offers (a deleted
                // field): keep it visible and honest instead of silently
                // swapping in another subject.
                const orphan = el('option', null, 'A field that no longer exists')
                orphan.value = condition.subject
                subjectSelect.appendChild(orphan)
                subjectSelect.value = condition.subject
            }

            syncOperators()
            operatorSelect.value = condition.operator && [...operatorSelect.options].some((o) => o.value === condition.operator)
                ? condition.operator
                : operatorSelect.options[0].value
            syncOperand()

            if (GROUP_SUBJECTS.includes(subjectSelect.value)) {
                operandGroupSelect.value = condition.operand != null ? String(condition.operand) : ''
            } else {
                operandInput.value = condition.operand != null ? condition.operand : ''
            }

            list.appendChild(row)
            syncLimit()
        }

        list.innerHTML = ''
        const conditions = node.config.conditions || []

        if (conditions.length === 0 && !readOnly) {
            addRow({})
        } else {
            conditions.forEach(addRow)
        }

        matchSelect.addEventListener('change', syncLimit)
        addButton.addEventListener('click', () => addRow({}))
        syncLimit()
    }

    function readGeneric() {
        const config = {}
        formEl.querySelectorAll('[data-field]').forEach((field) => {
            config[field.dataset.field] = field.value
        })

        return config
    }

    function readWait() {
        const mode = formEl.querySelector('input[name="wf-wait-mode"]:checked').value

        if (mode === 'until_datetime') {
            return { mode, at: formEl.querySelector('[data-field="at"]').value.replace('T', ' ') }
        }

        return {
            mode,
            amount: Number(formEl.querySelector('[data-field="amount"]').value),
            unit: formEl.querySelector('[data-field="unit"]').value,
        }
    }

    function readTrigger() {
        const checked = formEl.querySelector('input[name="wf-trigger-type"]:checked')
        const triggerType = checked ? checked.value : 'contact_created'
        const config = {
            trigger_type: triggerType,
            enrollment_policy: formEl.querySelector('[data-role="wf-enrollment-policy"]').value,
            enrollment_policy_source: formEl.querySelector('[data-field="enrollment_policy_source"]').value,
            failure_policy: 'halt',
        }

        if (triggerType === 'contact_date_reached') {
            const groupValue = formEl.querySelector('[data-role="wf-date-contact-group-select"]').value
            config.contact_group_id = groupValue ? Number(groupValue) : null
            const fieldValue = formEl.querySelector('[data-role="wf-date-field-select"]').value
            config.date_field_id = fieldValue ? Number(fieldValue) : null
            config.offset = formEl.querySelector('[data-role="wf-offset-select"]').value
            config.send_at = formEl.querySelector('[data-field="send_at"]').value
        } else if (triggerType === 'contact_created') {
            config.source = formEl.querySelector('select[data-field="source"]').value
            const groupValue = formEl.querySelector('[data-role="wf-contact-group-select"]').value
            config.contact_group_id = groupValue ? Number(groupValue) : null
        }

        return config
    }

    function readIfElse() {
        const conditions = []

        formEl.querySelectorAll('[data-role="wf-condition-row"]').forEach((row) => {
            const subject = row.querySelector('[data-role="wf-condition-subject"]').value
            const operator = row.querySelector('[data-role="wf-condition-operator"]').value
            const condition = { subject, operator }

            if (needsOperand(operator)) {
                condition.operand = GROUP_SUBJECTS.includes(subject)
                    ? row.querySelector('[data-role="wf-condition-operand-group"]').value
                    : row.querySelector('[data-role="wf-condition-operand"]').value
            }

            conditions.push(condition)
        })

        return { match: formEl.querySelector('[data-field="match"]').value, conditions }
    }

    function readUpdateContactField() {
        const fieldValue = formEl.querySelector('[data-role="wf-writable-field-select"]').value

        return {
            field_id: fieldValue ? Number(fieldValue) : null,
            value: formEl.querySelector('[data-field="value"]').value,
        }
    }

    function save() {
        if (!currentNode || readOnly) {
            return
        }

        let config

        if (currentNode.type === 'trigger') {
            config = readTrigger()
        } else if (currentNode.type === 'if_else') {
            config = readIfElse()
        } else if (currentNode.type === 'update_contact_field') {
            config = readUpdateContactField()
        } else if (currentNode.type === 'wait') {
            config = readWait()
        } else if (currentNode.type === 'end') {
            config = {}
        } else {
            config = readGeneric()
        }

        const node = currentNode
        onSave(node, config)
        close()
    }

    formEl.addEventListener('submit', (event) => {
        event.preventDefault()
        save()
    })

    saveButton.addEventListener('click', save)
    cancelButton.addEventListener('click', close)
    closeButton.addEventListener('click', close)

    deleteButton.addEventListener('click', () => {
        if (!currentNode) {
            return
        }

        const node = currentNode
        close()
        onDelete(node)
    })

    drawerEl.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault()
            close()
        }
    })

    return { open, close, isOpen, currentKey: () => (currentNode ? currentNode.key : null) }
}
