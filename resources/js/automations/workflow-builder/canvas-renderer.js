// Automations V2 (contract §5.1, §13.1, V2-D) — the canvas.
//
// Because the graph is a tree, it lays itself out: the trigger fixed at the
// top, then one vertical, connected column of step cards, with an If / Else
// opening two side-by-side lanes (contract §13.1). No layout engine, no
// edge-routing and no canvas library — nested DOM, built as real elements so
// every "+", move and delete closes over the exact array it acts on, the same
// array document-model.js splices.
//
// HONEST ENDINGS. The schema has no "merge": an If / Else is the last step of
// its path, and its Yes and No lanes never rejoin (WorkflowDefinitionValidator).
// So the canvas never draws lanes converging. Every path is capped with an
// explicit end marker instead — "End of path" under each lane, "Workflow ends"
// under the main column — which is exactly what the runtime does when a path
// runs out of steps.
import { NODE_LABELS, NODE_ICONS, closesSequence, isBranching, isTerminal } from './constants.js'
import { summarize } from './summaries.js'
import { el, icon } from './dom.js'

/**
 * @param {Object} doc the current document (mutated in place by callbacks)
 * @param {HTMLElement} rootEl the `<ol data-role="wf-canvas-root">` element
 * @param {Object} handlers
 *   onSelect(node)
 *   onRequestAdd(anchorEl, list, index, depth)
 *   onDelete(node, list)
 *   onMove(node, list, direction)     — direction is -1 or 1
 * @param {Object} state { errors, nodeCount, limits, selectedKey, readOnly, catalogs, path }
 *   path: null, or Map(node key → simulated step) while a test result is shown
 */
export function renderCanvas(doc, rootEl, handlers, state) {
    rootEl.innerHTML = ''
    rootEl.className = 'wf-flow wf-flow--main'

    const trigger = el('li', 'wf-flow__item')
    trigger.appendChild(renderCard(doc.root, null, 0, handlers, state))
    rootEl.appendChild(trigger)

    renderSequenceInto(rootEl, doc.root.next || [], handlers, state, 0, 'Workflow ends')
}

function onPath(state, key) {
    return Boolean(state.path && state.path.has(key))
}

/**
 * Append one sequence's steps to `listEl`: a link (line + "+") before each
 * step, a trailing link when the path is still open, and the end marker when
 * nothing closes the path on its own.
 */
function renderSequenceInto(listEl, list, handlers, state, depth, endLabel) {
    list.forEach((node, index) => {
        listEl.appendChild(renderLink(list, index, depth, handlers, state))

        const item = el('li', 'wf-flow__item')
        item.appendChild(renderCard(node, list, index, handlers, state))

        if (isBranching(node.type)) {
            item.appendChild(renderBranches(node, handlers, state, depth))
        } else if (!isTerminal(node.type) && Array.isArray(node.next) && node.next.length > 0) {
            // Every edit in this builder keeps a straight chain as one flat
            // sibling array (contract §5.3's own example). A document nesting a
            // continuation in a step's own `next` — loaded from elsewhere — is
            // still drawn in full rather than silently dropping steps; the
            // validator walks that shape too.
            const nested = el('ol', 'wf-flow')
            renderSequenceInto(nested, node.next, handlers, state, depth, endLabel)
            item.appendChild(nested)
        }

        listEl.appendChild(item)
    })

    const last = list[list.length - 1]

    if (last && closesSequence(last.type)) {
        return
    }

    listEl.appendChild(renderLink(list, list.length, depth, handlers, state))

    const end = el('li', 'wf-flow__item wf-end')
    end.dataset.role = 'wf-path-end'
    end.appendChild(icon('flag'))
    end.appendChild(el('span', null, endLabel))
    listEl.appendChild(end)
}

function renderLink(list, index, depth, handlers, state) {
    const link = el('li', 'wf-link')
    link.appendChild(el('span', 'wf-link__line'))

    if (state.readOnly) {
        return link
    }

    const atCapacity = typeof state.nodeCount === 'number' && state.limits && state.nodeCount >= state.limits.maxNodes

    const button = el('button', 'wf-add')
    button.type = 'button'
    button.dataset.role = 'wf-add-step'
    button.setAttribute('aria-haspopup', 'dialog')
    button.setAttribute('aria-expanded', 'false')
    button.setAttribute('aria-label', atCapacity ? 'This workflow has reached its step limit' : 'Add a step here')
    button.title = atCapacity ? 'This workflow has reached its step limit' : 'Add a step'
    button.disabled = atCapacity
    button.appendChild(icon('plus'))
    button.addEventListener('click', () => handlers.onRequestAdd(button, list, index, depth))

    link.appendChild(button)
    link.appendChild(el('span', 'wf-link__line'))

    return link
}

function renderCard(node, list, index, handlers, state) {
    const isTrigger = list === null
    const { title, summary, incomplete } = summarize(node, state.catalogs || {})
    const messages = (state.errors && state.errors[node.key]) || []
    const hasErrors = messages.length > 0

    const wrap = el('div', 'wf-card-wrap')

    const card = el('div', `wf-card wf-card--${node.type}`)
    card.dataset.nodeKey = node.key
    card.dataset.nodeType = node.type
    card.dataset.role = 'wf-step-card'
    card.tabIndex = 0
    card.setAttribute('role', 'button')

    const label = title || NODE_LABELS[node.type] || 'Step'
    card.setAttribute('aria-label', `${isTrigger ? 'Trigger: ' : ''}${label}. ${summary}${hasErrors ? '. Needs attention.' : ''}`)

    if (state.selectedKey === node.key) {
        card.classList.add('is-selected')
    }

    if (hasErrors) {
        card.classList.add('has-error')
    }

    if (state.path) {
        card.classList.add(onPath(state, node.key) ? 'is-on-path' : 'is-off-path')
    }

    card.appendChild(icon(NODE_ICONS[node.type] || 'circle-stop', `wf-tone wf-tone--${node.type}`))

    const body = el('div', 'wf-card__body')
    body.appendChild(el('span', 'wf-card__eyebrow', isTrigger ? 'Trigger' : NODE_LABELS[node.type]))

    if (isTrigger) {
        body.appendChild(el('span', 'wf-card__title', label))
    }

    body.appendChild(el('span', `wf-card__summary${incomplete ? ' is-incomplete' : ''}`, summary))
    card.appendChild(body)

    if (hasErrors) {
        const flag = icon('triangle-alert', 'wf-card__flag')
        flag.title = 'Needs attention'
        card.appendChild(flag)
    }

    if (!isTrigger && !state.readOnly) {
        card.appendChild(renderMenu(node, list, index, handlers))
    }

    card.addEventListener('click', (event) => {
        if (event.target.closest('.wf-card__menu')) {
            return
        }

        handlers.onSelect(node)
    })

    card.addEventListener('keydown', (event) => {
        if (event.target !== card) {
            return
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault()
            handlers.onSelect(node)
        } else if (event.key === 'Delete' && !isTrigger && !state.readOnly) {
            event.preventDefault()
            handlers.onDelete(node, list)
        }
    })

    wrap.appendChild(card)

    if (hasErrors) {
        const errorList = el('ul', 'wf-card__errors')
        messages.forEach((message) => errorList.appendChild(el('li', null, message)))
        wrap.appendChild(errorList)
    }

    return wrap
}

function renderMenu(node, list, index, handlers) {
    const menuWrap = el('div', 'dropdown wf-card__menu')
    const button = el('button', 'wf-card__menu-button')
    button.type = 'button'
    button.setAttribute('data-bs-toggle', 'dropdown')
    button.setAttribute('aria-expanded', 'false')
    button.setAttribute('aria-label', 'Step options')
    button.appendChild(icon('ellipsis-vertical'))
    menuWrap.appendChild(button)

    const menu = el('ul', 'dropdown-menu dropdown-menu-end')

    // Moves are offered only where the result is still a valid path: a step
    // that closes its path (If / Else, End) must stay last, so it never moves
    // up, and nothing moves down past one.
    const next = list[index + 1]
    const canMoveUp = index > 0 && !closesSequence(node.type)
    const canMoveDown = index < list.length - 1 && !closesSequence(next.type)

    menu.appendChild(menuItem('Move up', 'chevron-up', canMoveUp, () => handlers.onMove(node, list, -1)))
    menu.appendChild(menuItem('Move down', 'chevron-down', canMoveDown, () => handlers.onMove(node, list, 1)))
    menu.appendChild(menuItem('Delete step', 'trash-2', true, () => handlers.onDelete(node, list), 'text-danger'))
    menuWrap.appendChild(menu)

    return menuWrap
}

function menuItem(label, iconName, enabled, onClick, extraClass) {
    const li = el('li')
    const button = el('button', `dropdown-item d-flex align-items-center gap-2${extraClass ? ` ${extraClass}` : ''}`)
    button.type = 'button'
    button.disabled = !enabled
    button.appendChild(icon(iconName))
    button.appendChild(el('span', null, label))
    button.addEventListener('click', onClick)
    li.appendChild(button)

    return li
}

function renderBranches(node, handlers, state, depth) {
    const split = el('div', 'wf-split')
    split.dataset.role = 'wf-branches'

    const taken = state.path && state.path.has(node.key) ? state.path.get(node.key).branch : null

    ;[
        ['yes', 'Yes', node.yes || (node.yes = [])],
        ['no', 'No', node.no || (node.no = [])],
    ].forEach(([lane, label, list]) => {
        const column = el('div', `wf-lane wf-lane--${lane}`)
        column.dataset.role = `wf-lane-${lane}`

        if (taken) {
            column.classList.add(taken === lane ? 'is-taken' : 'is-not-taken')
        }

        const head = el('div', 'wf-lane__head')
        head.appendChild(el('span', 'wf-lane__line'))
        head.appendChild(el('span', `wf-lane__label wf-lane__label--${lane}`, label))
        column.appendChild(head)

        const sequence = el('ol', 'wf-flow')
        renderSequenceInto(sequence, list, handlers, state, depth + 1, 'End of path')
        column.appendChild(sequence)

        split.appendChild(column)
    })

    return split
}
