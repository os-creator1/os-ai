// Automations V2 (contract §13.1 "Test workflow", §16) — try the workflow on
// one real contact, with no side effects.
//
// A person searches this Business's contacts by name or number and picks one;
// the page then asks WorkflowSimulator for the path that contact would take
// through the saved draft. Nothing is sent, changed or enrolled — the
// simulator's own guarantee — and this panel says so. The result is shown two
// ways: as a plain step-by-step story here, and as a highlighted path on the
// canvas (the caller's onPath), so a branch that goes the "wrong" way is
// obvious at a glance.
import { NODE_LABELS, NODE_ICONS } from './constants.js'
import { el, icon } from './dom.js'

const DID_WORDS = {
    started: 'Starts the workflow',
    would_run: 'Would run',
    would_wait: 'Would wait',
    branched: 'Checks the condition',
    ended: 'Ends the workflow',
    skipped: 'Would stop here',
    held: 'Would pause here',
}

const ENDED_WORDS = {
    path_end: 'The path runs out of steps, so the workflow finishes for this contact.',
    end_step: 'The workflow ends at an End step.',
    step_limit: 'The test stopped after the most steps one run can take.',
    stopped: 'The workflow would stop for this contact at the step above.',
}

const REFUSED_WORDS = {
    contact_belongs_to_another_business: 'That contact is not part of this business.',
    workflow_cannot_be_walked: 'This workflow can’t be tested until the highlighted problems are fixed.',
    version_has_no_business: 'This workflow can’t be tested right now.',
}

export function createTestPanel({ panelEl, api, basePath, beforeRun, onPath, onClose }) {
    const searchInput = panelEl.querySelector('[data-role="wf-test-search"]')
    const contactsEl = panelEl.querySelector('[data-role="wf-test-contacts"]')
    const resultEl = panelEl.querySelector('[data-role="wf-test-result"]')
    const pickEl = panelEl.querySelector('[data-role="wf-test-pick"]')
    const clearButton = panelEl.querySelector('[data-role="wf-test-clear"]')
    const closeButton = panelEl.querySelector('[data-role="wf-test-close"]')

    let searchTimer = null
    let searchSeq = 0

    function open() {
        panelEl.hidden = false
        showPicker()
        searchInput.focus({ preventScroll: true })
        search()
    }

    function close() {
        if (panelEl.hidden) {
            return
        }

        panelEl.hidden = true
        onPath(null)

        if (onClose) {
            onClose()
        }
    }

    function isOpen() {
        return !panelEl.hidden
    }

    function showPicker() {
        pickEl.hidden = false
        resultEl.hidden = true
        clearButton.hidden = true
        resultEl.innerHTML = ''
    }

    async function search() {
        const seq = ++searchSeq
        contactsEl.innerHTML = ''
        contactsEl.appendChild(el('p', 'wf-test__muted', 'Searching…'))

        let result

        try {
            result = await api.testContacts(basePath, searchInput.value.trim())
        } catch (error) {
            result = { status: 0, body: null }
        }

        if (seq !== searchSeq) {
            return
        }

        contactsEl.innerHTML = ''

        if (result.status === 401) {
            contactsEl.appendChild(el('p', 'wf-test__muted', 'You don’t have permission to view contacts.'))

            return
        }

        const contacts = (result.body && result.body.contacts) || []

        if (result.status !== 200) {
            contactsEl.appendChild(el('p', 'wf-test__muted', 'Contacts couldn’t be loaded. Try again.'))

            return
        }

        if (contacts.length === 0) {
            contactsEl.appendChild(el('p', 'wf-test__muted', searchInput.value.trim() ? 'No contact matches that search.' : 'This business has no contacts yet.'))

            return
        }

        contacts.forEach((contact) => {
            const button = el('button', 'wf-test__contact')
            button.type = 'button'
            button.dataset.role = 'wf-test-contact'
            button.appendChild(icon('user'))

            const text = el('span', 'wf-test__contact-text')
            text.appendChild(el('span', 'wf-test__contact-name', contact.name || contact.phone))

            if (contact.name) {
                text.appendChild(el('span', 'wf-test__contact-phone', contact.phone))
            }

            button.appendChild(text)
            button.addEventListener('click', () => run(contact))
            contactsEl.appendChild(button)
        })
    }

    async function run(contact) {
        pickEl.hidden = true
        resultEl.hidden = false
        clearButton.hidden = false
        resultEl.innerHTML = ''
        resultEl.appendChild(el('p', 'wf-test__muted', 'Running the test…'))

        await beforeRun()

        let result

        try {
            result = await api.simulate(basePath, contact.uid)
        } catch (error) {
            result = { status: 0, body: null }
        }

        resultEl.innerHTML = ''

        const heading = el('div', 'wf-test__subject')
        heading.appendChild(icon('user'))
        heading.appendChild(el('span', null, `Testing with ${contact.name || contact.phone}`))
        resultEl.appendChild(heading)

        const body = result.body || {}

        if (result.status !== 200 || body.refused) {
            resultEl.appendChild(el('p', 'wf-test__notice', REFUSED_WORDS[body.refused] || body.message || 'The test couldn’t run. Try again.'))
            onPath(null)

            return
        }

        if (body.validation && Object.keys(body.validation).length > 0) {
            resultEl.appendChild(el('p', 'wf-test__notice', 'This draft still has problems to fix before it can publish. The path below shows what would happen as it stands.'))
        }

        const steps = Array.isArray(body.path) ? body.path : []
        const timeline = el('ol', 'wf-test__timeline')

        steps.forEach((step) => {
            const item = el('li', 'wf-test__step')
            item.appendChild(icon(NODE_ICONS[step.type] || 'zap', `wf-tone wf-tone--${step.type}`))

            const text = el('div', 'wf-test__step-text')
            text.appendChild(el('span', 'wf-test__step-title', step.type === 'trigger' ? 'Trigger' : NODE_LABELS[step.type] || 'Step'))

            let line = DID_WORDS[step.did] || ''

            if (step.did === 'branched' && step.branch) {
                line = `Takes the ${step.branch === 'yes' ? 'Yes' : 'No'} path`
            }

            if (step.did === 'would_wait' && step.resume_at) {
                line = `Would wait until ${new Date(step.resume_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}`
            }

            text.appendChild(el('span', 'wf-test__step-did', line))

            // The simulator's detail explains what testing withheld ("Nothing
            // is sent while testing") or why a path stopped. For a start, a
            // branch or a timed wait the line above already says it.
            const explains = ['would_run', 'held', 'skipped'].includes(step.did) || (step.did === 'would_wait' && !step.resume_at)

            if (step.detail && explains) {
                text.appendChild(el('span', 'wf-test__step-detail', step.detail))
            }

            item.appendChild(text)
            timeline.appendChild(item)
        })

        resultEl.appendChild(timeline)

        if (body.ended && ENDED_WORDS[body.ended]) {
            const end = el('p', 'wf-test__end')
            end.appendChild(icon('flag'))
            end.appendChild(el('span', null, ENDED_WORDS[body.ended]))
            resultEl.appendChild(end)
        }

        onPath(new Map(steps.map((step) => [step.key, step])))
    }

    searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer)
        searchTimer = window.setTimeout(search, 250)
    })

    clearButton.addEventListener('click', () => {
        onPath(null)
        showPicker()
        searchInput.focus({ preventScroll: true })
    })

    closeButton.addEventListener('click', close)

    panelEl.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault()
            close()
        }
    })

    return { open, close, isOpen }
}
