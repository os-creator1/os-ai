// Automations V2 (contract §14.4, V2-D) — turning the server's
// node_key-keyed errors into customer-facing feedback. The server is the
// only authority on what is valid (WorkflowDefinitionValidator); this
// module never re-derives a validation rule, it only places the server's
// own messages: document-level ones in a banner, per-step ones on their
// card (canvas-renderer.js reads the same `errors` map directly).
export const DOCUMENT_KEY = '_document'

export function hasErrors(errors) {
    return Object.keys(errors || {}).length > 0
}

export function countIssues(errors) {
    return Object.values(errors || {}).reduce((total, list) => total + list.length, 0)
}

export function renderDocumentBanner(bannerEl, errors, messageTemplate) {
    const documentMessages = (errors && errors[DOCUMENT_KEY]) || []
    const total = countIssues(errors)

    if (total === 0) {
        bannerEl.classList.add('d-none')
        bannerEl.innerHTML = ''

        return
    }

    bannerEl.classList.remove('d-none')
    bannerEl.innerHTML = ''

    const summary = document.createElement('p')
    summary.className = 'mb-1 fw-semibold'
    summary.textContent = messageTemplate.replace(':count', String(total))
    bannerEl.appendChild(summary)

    documentMessages.forEach((message) => {
        const p = document.createElement('p')
        p.className = 'mb-0'
        p.textContent = message
        bannerEl.appendChild(p)
    })
}
