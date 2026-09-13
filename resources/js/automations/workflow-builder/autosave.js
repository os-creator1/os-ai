// Automations V2 (contract §14.4, §20.2, V2-D) — autosave.
//
// Debounced 1.5s PUT of the whole document with `definition_revision`
// (§14.4). The server's `WorkflowDraftService::autosave()` is the real
// concurrency authority: a conditional `UPDATE ... WHERE definition_revision
// = ?` that 409s on a stale revision. Two things layer on top of that here,
// both required by the task's own test list:
//
//   1. STALE-RESPONSE GUARD (test #15). If a save is somehow still in
//      flight when the debounce fires again (slow network outrunning 1.5s),
//      the OLDER request's response must never overwrite state a NEWER
//      request's response already applied. A monotonically increasing
//      `token` does this: a response is only applied if it is still the
//      most recently ISSUED request when it arrives, regardless of arrival
//      order.
//   2. RETRY-ABLE FAILURE (test #16). A network failure or non-2xx/409
//      response leaves the local edit exactly as the customer left it —
//      nothing is discarded — and flips to an "error" state the next
//      successful save (or an explicit retry) clears.
//
// Never publishes anything: this module's only write is the draft PUT.
export function createAutosave({ api, workflowBasePath, initialRevision, onStateChange, onSaved, onConflict }) {
    let revision = initialRevision
    let timer = null
    let token = 0
    let pendingDocument = null

    function schedule(doc) {
        pendingDocument = doc

        if (timer) {
            window.clearTimeout(timer)
        }

        timer = window.setTimeout(flush, 1500)
    }

    function flushNow(doc) {
        if (timer) {
            window.clearTimeout(timer)
            timer = null
        }

        pendingDocument = doc

        return flush()
    }

    async function flush() {
        if (pendingDocument === null) {
            return
        }

        const doc = pendingDocument
        pendingDocument = null
        const mySeq = ++token

        onStateChange('saving')

        let result

        try {
            result = await api.saveDraft(workflowBasePath, doc, revision)
        } catch (error) {
            if (mySeq !== token) {
                return
            }

            onStateChange('error')
            // The edit stays visible locally (it was never discarded) and
            // the next debounce — or an explicit retry — tries again.
            schedule(doc)

            return
        }

        // A newer save already superseded this one; applying this
        // response now would move state backwards.
        if (mySeq !== token) {
            return
        }

        if (result.status === 409) {
            onStateChange('error')
            onConflict(result.body)

            return
        }

        if (result.status < 200 || result.status >= 300) {
            onStateChange('error')
            schedule(doc)

            return
        }

        revision = result.body && result.body.revision !== undefined ? result.body.revision : revision + 1
        onStateChange('saved')
        onSaved(result.body || {})
    }

    return {
        schedule,
        flushNow,
        getRevision: () => revision,
    }
}
