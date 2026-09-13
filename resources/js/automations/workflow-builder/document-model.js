// Automations V2 (contract §5.3, V2-D) — pure functions over the editor's
// document. THE DOCUMENT IS A NESTED LIST, and that is the whole tree
// guarantee: a node's continuation is its own `next` array, or for
// `if_else`, its `yes`/`no` arrays. There is nowhere in this shape to write
// "go back to" or "both branches continue here" — the same structural
// guarantee the server's WorkflowDefinitionValidator relies on (it is not
// duplicated here; this module only prevents the browser from ever being
// ASKED to build a shape the schema cannot express).
//
// Nothing here talks to the network, the DOM or the undo stack — every
// function takes a document (or a list within one) and returns a new node,
// a new key, or mutates the list it was handed. Callers (canvas-renderer.js,
// drawer.js) own snapshotting for undo and scheduling the autosave.

function uuid() {
    if (window.crypto && window.crypto.randomUUID) {
        return window.crypto.randomUUID()
    }

    // Fallback for a non-secure-context test/browser environment.
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0
        const v = c === 'x' ? r : (r & 0x3) | 0x8
        return v.toString(16)
    })
}

export function newNode(type, config) {
    const node = { key: uuid(), type, config: config || {} }

    if (type === 'if_else') {
        node.yes = []
        node.no = []
    } else if (type !== 'end') {
        node.next = []
    }

    return node
}

export function cloneDocument(doc) {
    return JSON.parse(JSON.stringify(doc))
}

/**
 * Depth of a node's OWN body relative to the root (root's `next` = depth 0).
 * Every step inside an `if_else`'s `yes`/`no` lane is one deeper than the
 * `if_else` itself — matches the server's `depth` column exactly, so the
 * client can refuse to offer "If / Else" once nesting is already at the
 * limit rather than let the customer build something the compiler will
 * only reject after a round trip.
 */
export function sequenceDepthOf(node, root) {
    let found = -1

    function walk(list, depth) {
        if (found !== -1) {
            return
        }

        for (const step of list) {
            if (step === node) {
                found = depth
                return
            }

            if (step.type === 'if_else') {
                walk(step.yes, depth + 1)
                walk(step.no, depth + 1)
            } else if (Array.isArray(step.next)) {
                walk(step.next, depth)
            }

            if (found !== -1) {
                return
            }
        }
    }

    walk(root.next, 0)

    return found
}

/**
 * The list a "+" between `before` and `after` (either may be null at an
 * end) belongs to, i.e. exactly what array.splice() target and index would
 * insert a node in the right spot. Callers already hold this array by
 * reference while rendering (canvas-renderer.js), so this helper exists
 * only for tests and for code paths that address a list by the node
 * bordering it rather than by the array itself.
 */
export function insertAt(list, index, node) {
    list.splice(index, 0, node)

    return node
}

export function removeFrom(list, node) {
    const index = list.indexOf(node)

    if (index !== -1) {
        list.splice(index, 1)
    }

    return index
}

export function moveWithin(list, node, direction) {
    const index = list.indexOf(node)

    if (index === -1) {
        return false
    }

    const target = index + direction

    if (target < 0 || target >= list.length) {
        return false
    }

    const [item] = list.splice(index, 1)
    list.splice(target, 0, item)

    return true
}

/** Count every node in the document — mirrors the server's own walk. */
export function countNodes(doc) {
    let count = 1 // the root

    function walkList(list) {
        for (const node of list) {
            count++

            if (node.type === 'if_else') {
                walkList(node.yes || [])
                walkList(node.no || [])
            } else {
                walkList(node.next || [])
            }
        }
    }

    walkList(doc.root.next || [])

    return count
}
