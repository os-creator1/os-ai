// Automations V2 (contract §13.1) — the step picker behind every "+".
//
// A searchable list of the steps that are VALID at that exact spot. The canvas
// decides which types those are (an End only closes a path; an If / Else is
// withheld at the nesting limit), so the picker can never offer a shape the
// document validator would refuse. Search matches the customer label, the
// description and a few everyday words ("delay", "sms", "branch").
//
// Keyboard: typing filters, ↑/↓ move, Enter adds, Escape closes.
import { STEP_CATALOG, NODE_LABELS } from './constants.js'
import { el, icon } from './dom.js'

export function createStepPicker(hostEl) {
    const panel = el('div', 'wf-picker')
    panel.dataset.role = 'wf-step-picker'
    panel.setAttribute('role', 'dialog')
    panel.setAttribute('aria-label', 'Add a step')
    panel.hidden = true

    const searchWrap = el('div', 'wf-picker__search')
    searchWrap.appendChild(icon('search'))
    const search = el('input', 'wf-picker__input')
    search.type = 'search'
    search.placeholder = 'Search steps'
    search.setAttribute('aria-label', 'Search steps')
    search.dataset.role = 'wf-step-search'
    searchWrap.appendChild(search)

    const list = el('div', 'wf-picker__list')
    list.setAttribute('role', 'listbox')

    const note = el('p', 'wf-picker__note')
    note.hidden = true

    panel.appendChild(searchWrap)
    panel.appendChild(list)
    panel.appendChild(note)
    hostEl.appendChild(panel)

    let options = null
    let anchor = null
    let activeIndex = 0
    let visible = []

    function matches(entry, query) {
        if (query === '') {
            return true
        }

        const haystack = [NODE_LABELS[entry.type], entry.description, entry.group, ...entry.keywords].join(' ').toLowerCase()

        return query
            .split(/\s+/)
            .filter(Boolean)
            .every((word) => haystack.includes(word))
    }

    function render() {
        const query = search.value.trim().toLowerCase()
        list.innerHTML = ''
        visible = STEP_CATALOG.filter((entry) => options.types.includes(entry.type) && matches(entry, query))

        if (activeIndex >= visible.length) {
            activeIndex = Math.max(0, visible.length - 1)
        }

        if (visible.length === 0) {
            list.appendChild(el('p', 'wf-picker__empty', 'No step matches that search.'))
        }

        let lastGroup = null

        visible.forEach((entry, index) => {
            if (entry.group !== lastGroup) {
                list.appendChild(el('p', 'wf-picker__group', entry.group))
                lastGroup = entry.group
            }

            const option = el('button', 'wf-picker__option')
            option.type = 'button'
            option.setAttribute('role', 'option')
            option.dataset.nodeType = entry.type
            option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false')
            option.classList.toggle('is-active', index === activeIndex)

            option.appendChild(icon(entry.icon, `wf-tone wf-tone--${entry.type}`))

            const text = el('span', 'wf-picker__text')
            text.appendChild(el('span', 'wf-picker__label', NODE_LABELS[entry.type]))
            text.appendChild(el('span', 'wf-picker__description', (options.hints && options.hints[entry.type]) || entry.description))
            option.appendChild(text)

            option.addEventListener('mouseenter', () => {
                activeIndex = index
                syncActive()
            })
            option.addEventListener('click', () => choose(entry.type))

            list.appendChild(option)
        })
    }

    function syncActive() {
        list.querySelectorAll('.wf-picker__option').forEach((option, index) => {
            const active = index === activeIndex
            option.classList.toggle('is-active', active)
            option.setAttribute('aria-selected', active ? 'true' : 'false')

            if (active) {
                option.scrollIntoView({ block: 'nearest' })
            }
        })
    }

    function position() {
        const rect = anchor.getBoundingClientRect()
        const width = panel.offsetWidth || 340
        const height = panel.offsetHeight || 380
        const left = Math.min(Math.max(12, rect.left + rect.width / 2 - width / 2), window.innerWidth - width - 12)
        const below = rect.bottom + 8
        const top = below + height > window.innerHeight - 12 ? Math.max(12, rect.top - height - 8) : below

        panel.style.left = `${left}px`
        panel.style.top = `${top}px`
    }

    function choose(type) {
        const chosen = options
        close()
        chosen.onPick(type)
    }

    function open(anchorEl, pickerOptions) {
        anchor = anchorEl
        options = pickerOptions
        activeIndex = 0
        search.value = ''
        note.hidden = !pickerOptions.note
        note.textContent = pickerOptions.note || ''
        panel.hidden = false
        anchor.setAttribute('aria-expanded', 'true')
        render()
        position()
        search.focus()
    }

    function close() {
        if (panel.hidden) {
            return
        }

        panel.hidden = true

        if (anchor) {
            anchor.setAttribute('aria-expanded', 'false')
            anchor.focus({ preventScroll: true })
        }

        anchor = null
        options = null
    }

    search.addEventListener('input', () => {
        activeIndex = 0
        render()
    })

    panel.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault()
            activeIndex = Math.min(visible.length - 1, activeIndex + 1)
            syncActive()
        } else if (event.key === 'ArrowUp') {
            event.preventDefault()
            activeIndex = Math.max(0, activeIndex - 1)
            syncActive()
        } else if (event.key === 'Enter') {
            event.preventDefault()

            if (visible[activeIndex]) {
                choose(visible[activeIndex].type)
            }
        } else if (event.key === 'Escape') {
            event.preventDefault()
            close()
        }
    })

    document.addEventListener('mousedown', (event) => {
        if (!panel.hidden && !panel.contains(event.target) && event.target !== anchor && !(anchor && anchor.contains(event.target))) {
            close()
        }
    })

    const reposition = () => {
        if (!panel.hidden && anchor && anchor.isConnected) {
            position()
        }
    }

    window.addEventListener('resize', reposition)
    // Capture phase: the canvas scrolls inside its own viewport, not the window.
    window.addEventListener('scroll', reposition, true)

    return { open, close, isOpen: () => !panel.hidden }
}
