// Automations V2 (contract §13.1, V2-D) — client-side document-history
// undo/redo. Bounded to 50 snapshots, exactly as the contract specifies.
// Every entry is a full document snapshot rather than a diff/operation log:
// a snapshot can never be "half applied," so undo/redo can never produce an
// invalid document — restoring one is exactly the same code path as
// loading the draft in the first place.
//
// This is a plain module, not server-side event sourcing: nothing here is
// persisted: it lives only in this tab's memory and is gone on reload.

const MAX_ENTRIES = 50

export function createHistory(initialDocument) {
    const stack = [JSON.parse(JSON.stringify(initialDocument))]
    let cursor = 0

    function push(doc) {
        // A new edit after an undo discards the redo branch — the
        // conventional undo/redo contract, and the only one that keeps
        // "redo" meaning "the edit I just undid" rather than something
        // else entirely.
        stack.splice(cursor + 1)
        stack.push(JSON.parse(JSON.stringify(doc)))

        if (stack.length > MAX_ENTRIES) {
            stack.shift()
        }

        cursor = stack.length - 1
    }

    function canUndo() {
        return cursor > 0
    }

    function canRedo() {
        return cursor < stack.length - 1
    }

    function undo() {
        if (!canUndo()) {
            return null
        }

        cursor--

        return JSON.parse(JSON.stringify(stack[cursor]))
    }

    function redo() {
        if (!canRedo()) {
            return null
        }

        cursor++

        return JSON.parse(JSON.stringify(stack[cursor]))
    }

    return { push, undo, redo, canUndo, canRedo }
}
