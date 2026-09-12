// Automations V2 (contract §20.2, V2-D) — the fixed endpoint contract, built
// against as a client. V2-0 fixes these exact paths; V2-E supplies the real
// controllers. Every URL is composed from the `basePath` the server handed
// this page (never a named route() — this module has no Laravel route
// table to consult) so it starts working the moment V2-E's routes exist,
// with no change here.

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]')

    return meta ? meta.getAttribute('content') : ''
}

async function request(url, options) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: Object.assign(
            {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            (options && options.headers) || {},
        ),
        ...options,
    })

    let body = null

    try {
        body = await response.json()
    } catch (error) {
        body = null
    }

    return { status: response.status, body }
}

export function createApiClient(basePath) {
    return {
        create(payload) {
            return request(basePath, { method: 'POST', body: JSON.stringify(payload) })
        },
        saveDraft(workflowBasePath, definition, revision) {
            return request(`${workflowBasePath}/draft`, {
                method: 'PUT',
                body: JSON.stringify({ definition, definition_revision: revision }),
            })
        },
        publish(workflowBasePath) {
            return request(`${workflowBasePath}/publish`, { method: 'POST' })
        },
        discardDraft(workflowBasePath) {
            return request(`${workflowBasePath}/discard-draft`, { method: 'POST' })
        },
        simulate(workflowBasePath, contactUid) {
            return request(`${workflowBasePath}/simulate`, {
                method: 'POST',
                body: JSON.stringify({ contact_uid: contactUid }),
            })
        },
    }
}
