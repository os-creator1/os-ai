// Automations — the inspector forms of the cross-domain actions: Move opportunity,
// Send booking link, Send form, Send questionnaire, Create & send proposal or
// contract, and Request payment.
//
// Same rules as the rest of the drawer: each form is a hidden `<template>` the Blade
// view rendered, and every option list comes ONLY from the `catalogs` this Business's
// page was handed — never another Business's row, and never an identifier shown to a
// person. A resource the step still names but that is archived or switched off stays
// visible, marked, so the validator's message makes sense; a way of sending the
// account cannot use is shown disabled with its reason.
import { el } from './dom.js'

function option(value, label) {
    const opt = el('option', null, label)
    opt.value = String(value)

    return opt
}

function fill(select, placeholder, rows) {
    select.innerHTML = ''
    select.appendChild(option('', placeholder))
    rows.forEach((row) => select.appendChild(row))
}

function numberOrNull(value) {
    return value === '' || value === null || value === undefined ? null : Number(value)
}

/** Minor units → "250.00"; null → "". */
function formatMajor(minor) {
    return minor === null || minor === undefined || minor === '' ? '' : (Number(minor) / 100).toFixed(2)
}

/** "250" / "250.5" / "250.00" → 25000 minor units, or null when it is not an amount. */
function parseMajor(text) {
    const clean = String(text || '').trim().replace(/,/g, '')

    if (!/^\d+(\.\d{1,2})?$/.test(clean)) {
        return null
    }

    const minor = Math.round(Number(clean) * 100)

    return minor > 0 ? minor : null
}

// ---------------------------------------------------------------------------
// Move opportunity
// ---------------------------------------------------------------------------

function populateMoveOpportunity(formEl, node, { catalogs }) {
    const pipelineSelect = formEl.querySelector('[data-role="wf-move-pipeline-select"]')
    const stageSelect = formEl.querySelector('[data-role="wf-move-stage-select"]')
    const empty = formEl.querySelector('[data-role="wf-move-no-pipelines"]')
    const pipelines = catalogs.crmPipelines || []
    const stages = catalogs.crmStages || []

    const keep = (row, selected) => !row.archived || String(row.id) === String(selected ?? '')
    const label = (row) => (row.archived ? `${row.name} (archived)` : row.name)

    fill(
        pipelineSelect,
        'Choose a pipeline',
        pipelines.filter((row) => keep(row, node.config.pipeline_id)).map((row) => option(row.id, label(row))),
    )
    pipelineSelect.value = node.config.pipeline_id != null ? String(node.config.pipeline_id) : ''
    empty.hidden = pipelines.length > 0

    function fillStages(selected) {
        const rows = stages.filter((row) => String(row.pipeline_id) === pipelineSelect.value && keep(row, selected))

        fill(stageSelect, pipelineSelect.value === '' ? 'Choose a pipeline first' : 'Choose a stage', rows.map((row) => option(row.id, label(row))))
        stageSelect.value = selected != null && rows.some((row) => String(row.id) === String(selected)) ? String(selected) : ''
    }

    fillStages(node.config.stage_id)
    pipelineSelect.addEventListener('change', () => fillStages(null))
}

function readMoveOpportunity(formEl) {
    return {
        pipeline_id: numberOrNull(formEl.querySelector('[data-role="wf-move-pipeline-select"]').value),
        stage_id: numberOrNull(formEl.querySelector('[data-role="wf-move-stage-select"]').value),
    }
}

// ---------------------------------------------------------------------------
// The three "send a link" actions share one delivery block
// ---------------------------------------------------------------------------

function populateChannels(formEl, node, { capabilities }) {
    const channels = Array.isArray(node.config.channels) ? node.config.channels : []

    formEl.querySelectorAll('input[data-role="wf-channel"]').forEach((input) => {
        const capability = (capabilities || {})[input.value === 'sms' ? 'sms' : 'email']
        const unavailable = capability && capability.available === false
        const note = formEl.querySelector(`[data-role="wf-channel-note-${input.value}"]`)

        input.checked = channels.includes(input.value)

        // Already chosen stays visible (and ticked) so the validator's message makes
        // sense; a way the account cannot use cannot be newly chosen.
        if (unavailable && !input.checked) {
            input.disabled = true
        }

        if (note) {
            note.hidden = !unavailable
            note.textContent = unavailable ? capability.reason || '' : ''
        }
    })

    formEl.querySelector('[data-field="subject"]').value = node.config.subject || ''
    formEl.querySelector('[data-field="message"]').value = node.config.message || ''
}

function readChannels(formEl) {
    return {
        channels: [...formEl.querySelectorAll('input[data-role="wf-channel"]:checked')].map((input) => input.value),
        subject: formEl.querySelector('[data-field="subject"]').value,
        message: formEl.querySelector('[data-field="message"]').value,
    }
}

function populateBookingLink(formEl, node, context) {
    const select = formEl.querySelector('[data-role="wf-booking-type-select"]')
    const empty = formEl.querySelector('[data-role="wf-no-booking-types"]')
    const types = context.catalogs.bookingTypes || []
    const locations = context.catalogs.locations || []
    const selected = node.config.booking_type_id != null ? String(node.config.booking_type_id) : ''

    select.innerHTML = ''
    select.appendChild(option('', 'Choose a booking type'))

    // Grouped by Location, so two locations' "Consultation" are told apart.
    const byLocation = new Map()

    types
        .filter((row) => row.active || String(row.id) === selected)
        .forEach((row) => {
            const list = byLocation.get(String(row.location_id)) || []
            list.push(row)
            byLocation.set(String(row.location_id), list)
        })

    byLocation.forEach((rows, locationId) => {
        const location = locations.find((candidate) => String(candidate.id) === locationId)
        const group = el('optgroup')
        group.label = location ? location.name || 'Unnamed location' : 'Another location'
        rows.forEach((row) => group.appendChild(option(row.id, row.active ? row.name : `${row.name} (switched off)`)))
        select.appendChild(group)
    })

    select.value = selected
    empty.hidden = types.length > 0

    populateChannels(formEl, node, context)
}

function readBookingLink(formEl) {
    return {
        booking_type_id: numberOrNull(formEl.querySelector('[data-role="wf-booking-type-select"]').value),
        ...readChannels(formEl),
    }
}

function populateFormLink(formEl, node, context, questionnaire) {
    const select = formEl.querySelector('[data-role="wf-form-select"]')
    const empty = formEl.querySelector('[data-role="wf-no-forms"]')
    const selected = node.config.form_id != null ? String(node.config.form_id) : ''
    // One definition serves both: a form is one page, a questionnaire two or more.
    const rows = (context.catalogs.forms || []).filter((row) => ((row.pages || 1) >= 2) === questionnaire)

    fill(
        select,
        questionnaire ? 'Choose a questionnaire' : 'Choose a form',
        rows
            .filter((row) => row.lifecycle === 'active' || String(row.id) === selected)
            .map((row) => option(row.id, row.lifecycle === 'active' ? row.name : `${row.name} (not switched on)`)),
    )
    select.value = selected
    empty.hidden = rows.length > 0

    populateChannels(formEl, node, context)
}

function readFormLink(formEl) {
    return {
        form_id: numberOrNull(formEl.querySelector('[data-role="wf-form-select"]').value),
        ...readChannels(formEl),
    }
}

// ---------------------------------------------------------------------------
// Create & send proposal or contract
// ---------------------------------------------------------------------------

function itemOptions(catalogs, selected) {
    return (catalogs.catalogItems || [])
        .filter((row) => row.active || String(row.id) === String(selected ?? ''))
        .map((row) => option(row.id, row.active ? row.name : `${row.name} (archived)`))
}

function populateProposal(formEl, node, { catalogs }) {
    const itemSelect = formEl.querySelector('[data-role="wf-catalog-item-select"]')
    const empty = formEl.querySelector('[data-role="wf-no-catalog-items"]')
    const scheduleInputs = formEl.querySelectorAll('input[name="wf-proposal-schedule"]')
    const depositWrap = formEl.querySelector('[data-role="wf-deposit-fields"]')
    const percent = formEl.querySelector('[data-field="deposit_percent"]')

    formEl.querySelector('[data-field="title"]').value = node.config.title || ''
    fill(itemSelect, 'Choose a product or package', itemOptions(catalogs, node.config.catalog_item_id))
    itemSelect.value = node.config.catalog_item_id != null ? String(node.config.catalog_item_id) : ''
    empty.hidden = (catalogs.catalogItems || []).length > 0
    formEl.querySelector('[data-field="quantity"]').value = node.config.quantity != null ? node.config.quantity : 1

    const schedule = node.config.payment_schedule === 'deposit' ? 'deposit' : 'full'
    scheduleInputs.forEach((input) => {
        input.checked = input.value === schedule
    })
    percent.value = node.config.deposit_percent != null ? node.config.deposit_percent : 30

    function sync() {
        depositWrap.hidden = formEl.querySelector('input[name="wf-proposal-schedule"]:checked').value !== 'deposit'
    }

    scheduleInputs.forEach((input) => input.addEventListener('change', sync))
    sync()
}

function readProposal(formEl) {
    const schedule = formEl.querySelector('input[name="wf-proposal-schedule"]:checked').value
    const config = {
        title: formEl.querySelector('[data-field="title"]').value,
        catalog_item_id: numberOrNull(formEl.querySelector('[data-role="wf-catalog-item-select"]').value),
        quantity: Number(formEl.querySelector('[data-field="quantity"]').value) || 1,
        payment_schedule: schedule,
    }

    if (schedule === 'deposit') {
        config.deposit_percent = Number(formEl.querySelector('[data-field="deposit_percent"]').value)
    }

    return config
}

// ---------------------------------------------------------------------------
// Request payment
// ---------------------------------------------------------------------------

function populateRequestPayment(formEl, node, { catalogs, currency, triggerType }) {
    const sourceInputs = formEl.querySelectorAll('input[name="wf-payment-source"]')
    const documentWrap = formEl.querySelector('[data-role="wf-payment-document-fields"]')
    const invoiceWrap = formEl.querySelector('[data-role="wf-payment-invoice-fields"]')
    const documentOption = formEl.querySelector('input[name="wf-payment-source"][value="document"]')
    const documentNote = formEl.querySelector('[data-role="wf-payment-document-note"]')
    const basisInputs = formEl.querySelectorAll('input[name="wf-payment-basis"]')
    const itemWrap = formEl.querySelector('[data-role="wf-payment-item-fields"]')
    const amountWrap = formEl.querySelector('[data-role="wf-payment-amount-fields"]')
    const itemSelect = formEl.querySelector('[data-role="wf-catalog-item-select"]')
    const empty = formEl.querySelector('[data-role="wf-no-catalog-items"]')

    // "This journey's document" only exists when the workflow starts from one.
    const documentTriggers = ['document_sent', 'document_signed', 'payment_succeeded', 'payment_failed']
    const hasDocument = documentTriggers.includes(typeof triggerType === 'function' ? triggerType() : triggerType)

    if (!hasDocument) {
        documentOption.disabled = true
        documentNote.hidden = false
    }

    // A new step starts on the journey's own document when there is one: that is what
    // "request payment" almost always means after a proposal is sent or signed.
    const source = hasDocument && node.config.source !== 'invoice' ? 'document' : 'invoice'
    sourceInputs.forEach((input) => {
        input.checked = input.value === source
    })

    formEl.querySelector('[data-field="title"]').value = node.config.title || ''
    fill(itemSelect, 'Choose a product or package', itemOptions(catalogs, node.config.catalog_item_id))
    itemSelect.value = node.config.catalog_item_id != null ? String(node.config.catalog_item_id) : ''
    empty.hidden = (catalogs.catalogItems || []).length > 0
    formEl.querySelector('[data-field="quantity"]').value = node.config.quantity != null ? node.config.quantity : 1
    formEl.querySelector('[data-field="amount"]').value = formatMajor(node.config.amount_minor)
    formEl.querySelector('[data-role="wf-currency"]').textContent = currency || ''

    const basis = node.config.amount_minor != null && node.config.catalog_item_id == null ? 'amount' : 'item'
    basisInputs.forEach((input) => {
        input.checked = input.value === basis
    })

    function sync() {
        const isDocument = formEl.querySelector('input[name="wf-payment-source"]:checked').value === 'document'
        documentWrap.hidden = !isDocument
        invoiceWrap.hidden = isDocument

        const byAmount = formEl.querySelector('input[name="wf-payment-basis"]:checked').value === 'amount'
        itemWrap.hidden = byAmount
        amountWrap.hidden = !byAmount
    }

    sourceInputs.forEach((input) => input.addEventListener('change', sync))
    basisInputs.forEach((input) => input.addEventListener('change', sync))
    sync()
}

function readRequestPayment(formEl) {
    if (formEl.querySelector('input[name="wf-payment-source"]:checked').value === 'document') {
        return { source: 'document' }
    }

    const config = { source: 'invoice', title: formEl.querySelector('[data-field="title"]').value }

    if (formEl.querySelector('input[name="wf-payment-basis"]:checked').value === 'amount') {
        // An unreadable amount stays unset, so the validator says what is missing
        // rather than the browser guessing a number.
        config.amount_minor = parseMajor(formEl.querySelector('[data-field="amount"]').value)
    } else {
        config.catalog_item_id = numberOrNull(formEl.querySelector('[data-role="wf-catalog-item-select"]').value)
        config.quantity = Number(formEl.querySelector('[data-field="quantity"]').value) || 1
    }

    return config
}

// ---------------------------------------------------------------------------

export const ACTION_TYPES = [
    'move_opportunity',
    'send_booking_link',
    'send_form',
    'send_questionnaire',
    'create_send_proposal',
    'request_payment',
]

export function populateAction(type, formEl, node, context) {
    switch (type) {
        case 'move_opportunity':
            return populateMoveOpportunity(formEl, node, context)
        case 'send_booking_link':
            return populateBookingLink(formEl, node, context)
        case 'send_form':
            return populateFormLink(formEl, node, context, false)
        case 'send_questionnaire':
            return populateFormLink(formEl, node, context, true)
        case 'create_send_proposal':
            return populateProposal(formEl, node, context)
        case 'request_payment':
            return populateRequestPayment(formEl, node, context)
        default:
            return undefined
    }
}

export function readAction(type, formEl) {
    switch (type) {
        case 'move_opportunity':
            return readMoveOpportunity(formEl)
        case 'send_booking_link':
            return readBookingLink(formEl)
        case 'send_form':
        case 'send_questionnaire':
            return readFormLink(formEl)
        case 'create_send_proposal':
            return readProposal(formEl)
        case 'request_payment':
            return readRequestPayment(formEl)
        default:
            return {}
    }
}
