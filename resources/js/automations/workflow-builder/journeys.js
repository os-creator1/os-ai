// Automations V2 (contract §13.1 tabs "Enrollment history" and "Execution logs") —
// who entered the workflow, where they are, and what happened to each of them,
// step by step.
//
// Read-only. It shows what the two existing JSON endpoints already return
// (GET /enrollments, GET /enrollments/{uid}/logs): the people are shown by number,
// never by id, the reasons are the bounded customer-readable ones, and nothing here
// can start, stop or change a journey. Loaded when its tab is first opened.
import { NODE_LABELS } from './constants.js'
import { el } from './dom.js'

const STATUS_TONES = {
    active: 'info',
    waiting: 'info',
    completed: 'success',
    failed: 'danger',
    exited: 'secondary',
    cancelled: 'secondary',
}

const STEP_WORDS = {
    succeeded: 'Done',
    failed: 'Could not run',
    skipped: 'Skipped',
    waiting: 'Waiting',
    pending: 'Queued',
    running: 'Running',
}

function when(iso) {
    if (!iso) {
        return ''
    }

    const date = new Date(iso)

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
}

function badge(text, tone) {
    return el('span', `badge bg-${tone || 'secondary'}`, text)
}

export function createJourneys({ api, basePath, historyEl, logsEl, showLogsTab }) {
    let loadedHistory = false
    let selectedUid = null

    function note(container, text) {
        container.innerHTML = ''
        container.appendChild(el('p', 'text-muted mb-0', text))
    }

    async function loadHistory() {
        note(historyEl, 'Loading…')

        try {
            const result = await api.enrollments(basePath)

            if (result.status !== 200) {
                note(historyEl, 'The history could not be loaded right now.')

                return
            }

            renderHistory((result.body && result.body.enrollments) || [])
            loadedHistory = true
        } catch (error) {
            note(historyEl, 'The history could not be loaded right now.')
        }
    }

    function renderHistory(rows) {
        historyEl.innerHTML = ''

        if (rows.length === 0) {
            historyEl.appendChild(el('p', 'text-muted mb-0', 'Nobody has entered this workflow yet.'))

            return
        }

        const table = el('table', 'table table-sm align-middle mb-0')
        const head = el('tr')
        ;['Contact', 'Status', 'Started', 'Steps', ''].forEach((label) => head.appendChild(el('th', null, label)))
        table.appendChild(el('thead')).appendChild(head)

        const body = el('tbody')

        rows.forEach((row) => {
            const tr = el('tr')
            tr.appendChild(el('td', null, row.contact_label || 'A contact'))

            const status = el('td')
            status.appendChild(badge(row.status_label, STATUS_TONES[row.status]))

            if (row.exit_reason_label) {
                status.appendChild(el('div', 'small text-muted', row.exit_reason_label))
            }

            tr.appendChild(status)
            tr.appendChild(el('td', null, when(row.enrolled_at)))
            tr.appendChild(el('td', null, String(row.step_count ?? 0)))

            const action = el('td', 'text-end')
            const view = el('button', 'btn btn-sm btn-outline-secondary', 'View steps')
            view.type = 'button'
            view.dataset.role = 'wf-journey-view'
            view.addEventListener('click', () => openLogs(row))
            action.appendChild(view)
            tr.appendChild(action)

            body.appendChild(tr)
        })

        table.appendChild(body)
        historyEl.appendChild(table)
    }

    async function openLogs(row) {
        selectedUid = row.uid
        showLogsTab()
        note(logsEl, 'Loading…')

        try {
            const result = await api.enrollmentLogs(basePath, row.uid)

            if (selectedUid !== row.uid) {
                return
            }

            if (result.status !== 200) {
                note(logsEl, 'The steps could not be loaded right now.')

                return
            }

            renderLogs(row, (result.body && result.body.steps) || [])
        } catch (error) {
            note(logsEl, 'The steps could not be loaded right now.')
        }
    }

    function renderLogs(row, steps) {
        logsEl.innerHTML = ''
        logsEl.appendChild(el('h3', 'h6', `${row.contact_label || 'A contact'} · ${row.status_label}`))

        if (steps.length === 0) {
            logsEl.appendChild(el('p', 'text-muted mb-0', 'No steps have run for this contact yet.'))

            return
        }

        const list = el('ol', 'list-group list-group-numbered')

        steps.forEach((step) => {
            const item = el('li', 'list-group-item d-flex flex-column gap-1')
            const top = el('div', 'd-flex justify-content-between gap-2')
            top.appendChild(el('span', 'fw-semibold', step.node_label || NODE_LABELS[step.node_type] || step.node_type))
            top.appendChild(badge(STEP_WORDS[step.status] || step.status, step.status === 'failed' ? 'danger' : step.status === 'succeeded' ? 'success' : 'secondary'))
            item.appendChild(top)

            const detail = step.error_label || step.result

            if (detail) {
                item.appendChild(el('span', step.status === 'failed' ? 'small text-danger' : 'small text-muted', detail))
            }

            if (step.branch_taken) {
                item.appendChild(el('span', 'small text-muted', step.branch_taken === 'yes' ? 'Took the Yes path' : 'Took the No path'))
            }

            const time = when(step.completed_at || step.started_at)

            if (time) {
                item.appendChild(el('span', 'small text-muted', time))
            }

            list.appendChild(item)
        })

        logsEl.appendChild(list)
    }

    function showHistory() {
        if (!loadedHistory) {
            loadHistory()
        }
    }

    function showLogs() {
        if (selectedUid === null) {
            note(logsEl, 'Choose a contact in Enrollment history and pick “View steps” to see what happened, step by step.')
        }
    }

    return { showHistory, showLogs, refresh: loadHistory }
}
