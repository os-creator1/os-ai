// Automations V2 (contract §13.1, §14.2, V2-D) — the configuration drawer.
//
// One Bootstrap 5 offcanvas, its body populated per node type from the
// hidden `<template>` partials the Blade view already rendered
// server-side (contract §13.1: "one server-rendered form partial per node
// type"). This module never invents a field NodeTypeRegistry does not
// validate, and every option list it offers (contact groups, writable
// fields, date fields) comes only from the `catalogs` this Business's
// caller supplied — never a value typed into the DOM by a script, and
// never another Business's row.
import { TEXT_SUBJECTS, BOOLEAN_SUBJECTS, GROUP_SUBJECTS, subjectOperators } from './conditions.js'

function fillSelect(select, options, valueKey, labelKey, placeholder) {
    select.innerHTML = ''

    if (placeholder) {
        const opt = document.createElement('option')
        opt.value = ''
        opt.textContent = placeholder
        select.appendChild(opt)
    }

    options.forEach((row) => {
        const opt = document.createElement('option')
        opt.value = String(row[valueKey])
        opt.textContent = row[labelKey]
        select.appendChild(opt)
    })
}

export function createDrawer({ drawerEl, catalogs, dateOffsets, contactSources, onSave, onDelete }) {
    const OffcanvasCtor = window.bootstrap && window.bootstrap.Offcanvas
    const instance = OffcanvasCtor ? OffcanvasCtor.getOrCreateInstance(drawerEl) : null

    const titleEl = drawerEl.querySelector('[data-role="wf-drawer-title"]')
    const formEl = drawerEl.querySelector('[data-role="wf-drawer-form"]')
    const errorsEl = drawerEl.querySelector('[data-role="wf-drawer-errors"]')
    const saveButton = drawerEl.querySelector('[data-role="wf-drawer-save"]')
    const deleteButton = drawerEl.querySelector('[data-role="wf-drawer-delete"]')

    let currentNode = null

    function open(node, errorMessages) {
        currentNode = node
        titleEl.textContent = titleFor(node.type)
        formEl.innerHTML = ''
        errorsEl.classList.add('d-none')
        errorsEl.innerHTML = ''

        const template = document.getElementById(`wf-node-form-${node.type}`)

        if (template) {
            formEl.appendChild(template.content.cloneNode(true))
        }

        populate(node)
        showErrors(errorMessages || [])

        deleteButton.classList.toggle('d-none', node.type === 'trigger')

        if (instance) {
            instance.show()
        }
    }

    function close() {
        if (instance) {
            instance.hide()
        }

        currentNode = null
    }

    function showErrors(messages) {
        if (!messages || messages.length === 0) {
            errorsEl.classList.add('d-none')

            return
        }

        errorsEl.classList.remove('d-none')
        errorsEl.innerHTML = ''
        messages.forEach((message) => {
            const p = document.createElement('p')
            p.className = 'mb-0'
            p.textContent = message
            errorsEl.appendChild(p)
        })
    }

    function populate(node) {
        if (node.type === 'trigger') {
            populateTrigger(node)
        } else if (node.type === 'if_else') {
            populateIfElse(node)
        } else if (node.type === 'update_contact_field') {
            populateUpdateContactField(node)
        } else {
            populateGeneric(node)
        }
    }

    function populateGeneric(node) {
        formEl.querySelectorAll('[data-field]').forEach((field) => {
            const key = field.dataset.field
            const value = node.config[key]

            if (value !== undefined && value !== null) {
                field.value = value
            }
        })

        if (node.type === 'wait') {
            wireWaitMode()
        }
    }

    function wireWaitMode() {
        const modeSelect = formEl.querySelector('[data-field="mode"]')
        const durationFields = formEl.querySelector('[data-role="wf-wait-duration-fields"]')
        const datetimeFields = formEl.querySelector('[data-role="wf-wait-datetime-fields"]')

        function sync() {
            const isDuration = modeSelect.value === 'duration'
            durationFields.classList.toggle('d-none', !isDuration)
            datetimeFields.classList.toggle('d-none', isDuration)
        }

        modeSelect.addEventListener('change', sync)
        sync()
    }

    function populateUpdateContactField(node) {
        const select = formEl.querySelector('[data-role="wf-writable-field-select"]')
        fillSelect(select, catalogs.writableFields, 'id', 'label', null)

        formEl.querySelectorAll('[data-field]').forEach((field) => {
            const key = field.dataset.field
            const value = node.config[key]

            if (value !== undefined && value !== null) {
                field.value = value
            }
        })
    }

    function populateTrigger(node) {
        const typeSelect = formEl.querySelector('[data-role="wf-trigger-type"]')
        const createdFields = formEl.querySelector('[data-role="wf-trigger-contact-created-fields"]')
        const dateFields = formEl.querySelector('[data-role="wf-trigger-date-fields"]')
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
        fillSelect(offsetSelect, dateOffsets.map((value) => ({ value, label: value })), 'value', 'label', null)

        sourceSelect.innerHTML = ''
        contactSources.forEach((source) => {
            const opt = document.createElement('option')
            opt.value = source
            opt.textContent = source
            sourceSelect.appendChild(opt)
        })

        function refreshDateFieldOptions() {
            const groupId = dateGroupSelect.value
            const rows = catalogs.dateFields.filter((f) => String(f.contact_group_id) === String(groupId))
            fillSelect(dateFieldSelect, rows, 'id', 'label', rows.length ? null : 'No date fields in this group')
        }

        function syncVisibility() {
            const isDate = typeSelect.value === 'contact_date_reached'
            createdFields.classList.toggle('d-none', isDate)
            dateFields.classList.toggle('d-none', !isDate)
        }

        function defaultPolicyFor(triggerType) {
            return triggerType === 'contact_date_reached' || triggerType === 'message_received'
                ? 'once_per_occurrence'
                : 'once_ever'
        }

        typeSelect.addEventListener('change', () => {
            syncVisibility()
            refreshDateFieldOptions()

            // Mirrors WorkflowDraftService::withTriggerChanged(): a policy
            // still carrying its trigger's DEFAULT origin follows the new
            // trigger silently; a customer's own explicit choice is left
            // alone, with a note rather than a silent override (§7.5).
            if (policySourceInput.value === 'default') {
                policySelect.value = defaultPolicyFor(typeSelect.value)
                confirmNote.classList.add('d-none')
            } else {
                confirmNote.classList.remove('d-none')
            }
        })

        dateGroupSelect.addEventListener('change', refreshDateFieldOptions)

        policySelect.addEventListener('change', () => {
            // Any manual change is a deliberate customer choice from this
            // moment on.
            policySourceInput.value = 'user'
            confirmNote.classList.add('d-none')
        })

        typeSelect.value = node.config.trigger_type || 'contact_created'
        sourceSelect.value = node.config.source || 'any'
        groupSelect.value = node.config.contact_group_id != null ? String(node.config.contact_group_id) : ''
        dateGroupSelect.value = node.config.contact_group_id != null ? String(node.config.contact_group_id) : ''
        refreshDateFieldOptions()
        dateFieldSelect.value = node.config.date_field_id != null ? String(node.config.date_field_id) : ''
        offsetSelect.value = node.config.offset || '0 day'
        formEl.querySelector('[data-field="send_at"]').value = node.config.send_at || '09:00'
        policySelect.value = node.config.enrollment_policy || defaultPolicyFor(typeSelect.value)
        policySourceInput.value = node.config.enrollment_policy_source || 'default'
        syncVisibility()
    }

    function populateIfElse(node) {
        const matchSelect = formEl.querySelector('[data-field="match"]')
        matchSelect.value = node.config.match || 'all'

        const list = formEl.querySelector('[data-role="wf-conditions-list"]')
        const addButton = formEl.querySelector('[data-role="wf-add-condition"]')
        const rowTemplate = document.getElementById('wf-if-else-condition-row')

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

            catalogs.writableFields.forEach((field) => {
                const opt = document.createElement('option')
                opt.value = `contact.custom_field:${field.id}`
                opt.textContent = field.label
                customGroup.appendChild(opt)
            })

            fillSelect(operandGroupSelect, catalogs.contactGroups, 'id', 'name', null)

            function syncOperators() {
                const subject = subjectSelect.value
                const operators = subjectOperators(subject)
                operatorSelect.innerHTML = ''
                operators.forEach((op) => {
                    const opt = document.createElement('option')
                    opt.value = op
                    opt.textContent = op.replace(/_/g, ' ')
                    operatorSelect.appendChild(opt)
                })
            }

            function syncOperandVisibility() {
                const operator = operatorSelect.value
                const needsOperand = !['is_empty', 'is_not_empty', 'is_true', 'is_false'].includes(operator)
                const isGroupSubject = GROUP_SUBJECTS.includes(subjectSelect.value)

                operandWrap.classList.toggle('d-none', !needsOperand || isGroupSubject)
                operandGroupWrap.classList.toggle('d-none', !needsOperand || !isGroupSubject)
            }

            subjectSelect.addEventListener('change', () => {
                syncOperators()
                syncOperandVisibility()
            })
            operatorSelect.addEventListener('change', syncOperandVisibility)

            row.querySelector('[data-role="wf-remove-condition"]').addEventListener('click', () => {
                row.remove()
            })

            subjectSelect.value = condition.subject || 'contact.first_name'
            syncOperators()
            operatorSelect.value = condition.operator || operatorSelect.options[0].value
            syncOperandVisibility()

            if (GROUP_SUBJECTS.includes(subjectSelect.value)) {
                operandGroupSelect.value = condition.operand != null ? String(condition.operand) : ''
            } else {
                operandInput.value = condition.operand != null ? condition.operand : ''
            }

            list.appendChild(row)
        }

        list.innerHTML = ''
        ;(node.config.conditions || []).forEach(addRow)

        addButton.onclick = () => addRow({})
    }

    function readGeneric() {
        const config = {}
        formEl.querySelectorAll('[data-field]').forEach((field) => {
            config[field.dataset.field] = field.value
        })

        return config
    }

    function readTrigger() {
        const triggerType = formEl.querySelector('[data-role="wf-trigger-type"]').value
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
        } else {
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

            if (!['is_empty', 'is_not_empty', 'is_true', 'is_false'].includes(operator)) {
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

    function titleFor(type) {
        return type
            .split('_')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ')
    }

    saveButton.addEventListener('click', () => {
        if (!currentNode) {
            return
        }

        let config

        if (currentNode.type === 'trigger') {
            config = readTrigger()
        } else if (currentNode.type === 'if_else') {
            config = readIfElse()
        } else if (currentNode.type === 'update_contact_field') {
            config = readUpdateContactField()
        } else if (currentNode.type === 'end') {
            config = {}
        } else {
            config = readGeneric()
        }

        onSave(currentNode, config)
        close()
    })

    deleteButton.addEventListener('click', () => {
        if (!currentNode) {
            return
        }

        const node = currentNode
        close()
        onDelete(node)
    })

    return { open, close }
}
