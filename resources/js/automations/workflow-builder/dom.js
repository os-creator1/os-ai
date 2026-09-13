// Automations V2 (contract §13.1) — the two DOM helpers every builder module
// shares. Elements are built with textContent, never innerHTML, so nothing a
// person typed into a step can ever become markup.
//
// Icons come from the design system's single icon seam: the Blade view renders
// each Lucide icon the builder uses once, into a hidden <template
// id="wf-icon-{name}">, and this clones it. The builder never ships its own
// icon set and never adds a dependency to draw one.

export function el(tag, className, text) {
    const node = document.createElement(tag)

    if (className) {
        node.className = className
    }

    if (text !== undefined && text !== null) {
        node.textContent = text
    }

    return node
}

export function icon(name, className) {
    const wrap = el('span', `wf-icon${className ? ` ${className}` : ''}`)
    wrap.setAttribute('aria-hidden', 'true')

    const template = document.getElementById(`wf-icon-${name}`)

    if (template && template.content) {
        wrap.appendChild(template.content.cloneNode(true))
    }

    return wrap
}
