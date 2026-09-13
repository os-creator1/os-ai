// Automations V2 (contract §5.1, §13.1, V2-D) — the canvas.
//
// Because the graph is a tree, it lays itself out: a vertical list of step
// cards, with If/Else rendering its two lanes as side-by-side columns
// (contract §13.1). This module builds real DOM nodes rather than HTML
// strings specifically so every "+"/move/delete control can close over the
// actual array (and node) it acts on — the same array `document-model.js`'s
// helpers splice — with no second, string-based addressing scheme to keep
// in sync with the document.
import { NODE_LABELS, INSERTABLE_TYPES, isBranching, isTerminal } from './constants.js'

function el(tag, className, text) {
    const node = document.createElement(tag)

    if (className) {
        node.className = className
    }

    if (text !== undefined) {
        node.textContent = text
    }

    return node
}

/**
 * @param {Object} doc the current document (mutated in place by callbacks)
 * @param {HTMLElement} rootEl the `<ol data-role="wf-canvas-root">` element
 * @param {Object} handlers
 *   onSelect(node)                    — open the drawer for this node
 *   onAdd(list, index, depth, type)    — insert a step of this type here
 *   onDelete(node, list)
 *   onMove(node, list, direction)     — direction is -1 or 1
 * @param {Object} state { errors, nodeCount, limits, selectedKey }
 */
export function renderCanvas(doc, rootEl, handlers, state) {
    rootEl.innerHTML = ''
    rootEl.appendChild(renderTriggerCard(doc.root, handlers, state))
    rootEl.appendChild(connector())
    rootEl.appendChild(renderSequence(doc.root.next || [], handlers, state, 0))
}

function connector() {
    return el('div', 'wf-step-connector')
}

function renderTriggerCard(node, handlers, state) {
    const li = el('li', 'wf-step')
    const card = el('div', 'wf-step-card wf-step-card-trigger')
    card.dataset.nodeKey = node.key
    card.dataset.nodeType = node.type
    card.tabIndex = 0
    card.setAttribute('role', 'button')

    const label = el('span', null, NODE_LABELS.trigger)
    card.appendChild(label)

    if (state.errors && state.errors[node.key] && state.errors[node.key].length) {
        card.classList.add('has-error')
    }

    card.addEventListener('click', () => handlers.onSelect(node))
    card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            handlers.onSelect(node)
        }
    })

    li.appendChild(card)
    appendErrorText(li, node.key, state)

    return li
}

/**
 * Render one sequence (an array of sibling steps) as a `<ol class="wf-steps">`,
 * with a "+" before the first step, between every pair, and after the last
 * ONLY if the sequence does not already end in a branching or terminal step
 * — exactly the rule that keeps every insertion point structurally valid by
 * construction (contract: "Each plus control opens only node types that are
 * valid at that insertion point").
 */
function renderSequence(list, handlers, state, depth) {
    const ol = el('ol', 'wf-steps')

    ol.appendChild(renderAddControl(list, 0, depth, handlers, state))

    list.forEach((node, index) => {
        ol.appendChild(renderStep(node, list, index, handlers, state, depth))

        const isLast = index === list.length - 1
        const closesSequence = isBranching(node.type) || isTerminal(node.type)

        if (!isLast || !closesSequence) {
            ol.appendChild(connector())
            ol.appendChild(renderAddControl(list, index + 1, depth, handlers, state))
        }
    })

    if (list.length === 0) {
        // The empty-list case is handled by the single "+" already appended
        // above; nothing else to draw.
    }

    return ol
}

function renderStep(node, list, index, handlers, state, depth) {
    const li = el('li', 'wf-step')

    if (isBranching(node.type)) {
        li.appendChild(renderCard(node, list, index, handlers, state, depth))
        li.appendChild(connector())
        li.appendChild(renderBranches(node, handlers, state, depth))

        return li
    }

    li.appendChild(renderCard(node, list, index, handlers, state, depth))

    // Every insert/delete/move in this module keeps a straight chain as one
    // flat sibling array (matching contract §5.3's own example), so this
    // branch is normally empty. It stays here defensively: the validator
    // itself walks a non-branching node's OWN `next` too (WorkflowDefinitionValidator::walkBranches),
    // so a document nesting a continuation that way — loaded from
    // elsewhere, or a future producer — still renders completely rather
    // than silently dropping steps.
    if (!isTerminal(node.type) && Array.isArray(node.next) && node.next.length > 0) {
        li.appendChild(connector())
        li.appendChild(renderSequence(node.next, handlers, state, depth))
    }

    return li
}

function renderCard(node, list, index, handlers, state, depth) {
    const wrapper = el('div', 'wf-step')
    const card = el('div', 'wf-step-card')
    card.dataset.nodeKey = node.key
    card.dataset.nodeType = node.type
    card.tabIndex = 0
    card.setAttribute('role', 'button')

    if (state.selectedKey === node.key) {
        card.classList.add('is-focused')
    }

    const hasErrors = state.errors && state.errors[node.key] && state.errors[node.key].length > 0

    if (hasErrors) {
        card.classList.add('has-error')
    }

    const label = el('span', null, NODE_LABELS[node.type] || node.type)
    card.appendChild(label)

    const menuWrap = el('div', 'dropdown')
    const menuButton = el('button', 'btn btn-flat-secondary btn-sm')
    menuButton.type = 'button'
    menuButton.setAttribute('data-bs-toggle', 'dropdown')
    menuButton.setAttribute('aria-label', 'Step options')
    menuButton.textContent = '⋮'
    menuWrap.appendChild(menuButton)

    const menu = el('ul', 'dropdown-menu dropdown-menu-end')
    menu.appendChild(menuItem('Move up', () => handlers.onMove(node, list, -1)))
    menu.appendChild(menuItem('Move down', () => handlers.onMove(node, list, 1)))
    menu.appendChild(menuItem('Delete step', () => handlers.onDelete(node, list)))
    menuWrap.appendChild(menu)

    card.appendChild(menuWrap)

    card.addEventListener('click', (event) => {
        if (event.target.closest('.dropdown')) {
            return
        }

        handlers.onSelect(node)
    })
    card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            handlers.onSelect(node)
        } else if (event.key === 'Delete') {
            handlers.onDelete(node, list)
        }
    })

    wrapper.appendChild(card)
    appendErrorText(wrapper, node.key, state)

    return wrapper
}

function menuItem(label, onClick) {
    const li = el('li')
    const button = el('button', 'dropdown-item', label)
    button.type = 'button'
    button.addEventListener('click', onClick)
    li.appendChild(button)

    return li
}

function appendErrorText(container, key, state) {
    const messages = state.errors && state.errors[key] ? state.errors[key] : []

    messages.forEach((message) => {
        container.appendChild(el('p', 'wf-step-error-text', message))
    })
}

function renderBranches(node, handlers, state, depth) {
    const wrap = el('div', 'wf-branches')

    const yes = el('div', 'wf-branch wf-branch-yes')
    yes.appendChild(el('span', 'wf-branch-label', 'Yes'))
    yes.appendChild(renderSequence(node.yes || [], handlers, state, depth + 1))
    appendPathEnd(yes, node.yes || [])

    const no = el('div', 'wf-branch wf-branch-no')
    no.appendChild(el('span', 'wf-branch-label', 'No'))
    no.appendChild(renderSequence(node.no || [], handlers, state, depth + 1))
    appendPathEnd(no, node.no || [])

    wrap.appendChild(yes)
    wrap.appendChild(no)

    return wrap
}

function appendPathEnd(container, list) {
    const last = list[list.length - 1]

    if (list.length === 0 || (!isBranching(last.type) && !isTerminal(last.type))) {
        container.appendChild(el('p', 'wf-path-end', 'Path ends here'))
    }
}

function renderAddControl(list, index, depth, handlers, state) {
    const wrap = el('div', 'dropdown wf-add-step-wrap')
    const button = el('button', 'wf-add-step')
    button.type = 'button'
    button.setAttribute('data-bs-toggle', 'dropdown')
    button.setAttribute('aria-label', 'Add a step')
    button.textContent = '+'

    const atCapacity = typeof state.nodeCount === 'number' && state.limits && state.nodeCount >= state.limits.maxNodes
    button.disabled = atCapacity

    const menu = el('ul', 'dropdown-menu')
    const isTrailing = index === list.length

    INSERTABLE_TYPES.forEach((type) => {
        if (type === 'if_else' && state.limits && depth >= state.limits.maxBranchDepth) {
            return
        }

        // End only makes sense as the LAST step in a sequence: something
        // already follows it at any other position, which the schema
        // forbids outright.
        if (type === 'end' && !isTrailing) {
            return
        }

        const li = el('li')
        const item = el('button', 'dropdown-item', NODE_LABELS[type])
        item.type = 'button'
        item.addEventListener('click', () => handlers.onAdd(list, index, depth, type))
        item.dataset.nodeType = type
        li.appendChild(item)
        menu.appendChild(li)
    })

    wrap.appendChild(button)
    wrap.appendChild(menu)

    return wrap
}
