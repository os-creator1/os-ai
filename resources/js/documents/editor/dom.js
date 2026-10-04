// Contract 17B — tiny DOM helpers shared by every editor module.

/**
 * el('button', {class: 'de-btn', 'data-role': 'x', onclick: fn}, ['text', node])
 * Attributes with a null/false/undefined value are skipped; `on*` are listeners.
 */
export function el(tag, attrs, children) {
    const node = document.createElement(tag);

    Object.keys(attrs || {}).forEach((key) => {
        const value = attrs[key];
        if (value === null || value === undefined || value === false) {
            return;
        }
        if (key.indexOf('on') === 0 && typeof value === 'function') {
            node.addEventListener(key.slice(2), value);
        } else if (key === 'class') {
            node.className = value;
        } else if (key === 'text') {
            node.textContent = value;
        } else if (key === 'value') {
            node.value = value;
        } else if (value === true) {
            node.setAttribute(key, '');
        } else {
            node.setAttribute(key, String(value));
        }
    });

    append(node, children);

    return node;
}

export function append(node, children) {
    (Array.isArray(children) ? children : [children]).forEach((child) => {
        if (child === null || child === undefined || child === false) {
            return;
        }
        node.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
    });

    return node;
}

export function clear(node) {
    while (node.firstChild) {
        node.removeChild(node.firstChild);
    }

    return node;
}

export function qs(root, selector) {
    return root.querySelector(selector);
}

export function qsa(root, selector) {
    return Array.prototype.slice.call(root.querySelectorAll(selector));
}

/** A fresh clone of a server-rendered icon (<template id="de-icon-NAME">). */
export function icon(name) {
    const template = document.getElementById('de-icon-' + name);
    if (template && template.content && template.content.firstElementChild) {
        const svg = template.content.firstElementChild.cloneNode(true);
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        return svg;
    }

    return document.createTextNode('');
}

export function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

export function debounce(fn, wait) {
    let timer = null;
    const wrapped = (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
    wrapped.cancel = () => clearTimeout(timer);

    return wrapped;
}
