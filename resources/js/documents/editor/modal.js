// Contract 17B — a small, dependency-free modal used by the product wizard,
// the custom-line form, the send dialog and the preview. It traps Tab focus,
// closes on Escape / backdrop and restores focus. Normal blocks never use it.

import { el, icon } from './dom';

let counter = 0;

/**
 * @param {HTMLElement} root  the editor's `.de-modal-root`
 * @param {{title: string, size?: 'sm'|'md'|'lg'|'xl', className?: string, onClose?: Function}} options
 * @returns {{el: HTMLElement, body: HTMLElement, footer: HTMLElement, setTitle: Function, close: Function}}
 */
export function openModal(root, options) {
    const previous = document.activeElement;
    const id = 'de-modal-' + ++counter;
    const title = el('h2', { class: 'de-modal__title', id: id + '-title', text: options.title || '' });
    const body = el('div', { class: 'de-modal__body' });
    const footer = el('div', { class: 'de-modal__footer' });
    const closeButton = el('button', { type: 'button', class: 'de-iconbtn', 'aria-label': 'Close', 'data-role': 'modal-close' }, [icon('x')]);
    const dialog = el('div', {
        class: 'de-modal__dialog de-modal__dialog--' + (options.size || 'md') + (options.className ? ' ' + options.className : ''),
        role: 'dialog',
        'aria-modal': 'true',
        'aria-labelledby': id + '-title',
        tabindex: '-1',
    }, [el('div', { class: 'de-modal__head' }, [title, closeButton]), body, footer]);
    const backdrop = el('div', { class: 'de-modal', 'data-role': 'modal' }, [dialog]);

    let closed = false;

    const close = () => {
        if (closed) {
            return;
        }
        closed = true;
        document.removeEventListener('keydown', onKey, true);
        backdrop.remove();
        if (previous && typeof previous.focus === 'function' && document.contains(previous)) {
            previous.focus();
        }
        if (typeof options.onClose === 'function') {
            options.onClose();
        }
    };

    const focusable = () => Array.prototype.slice.call(dialog.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'))
        .filter((node) => node.offsetParent !== null);

    const onKey = (event) => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close();
            return;
        }
        if (event.key === 'Tab') {
            const nodes = focusable();
            if (nodes.length === 0) {
                return;
            }
            const first = nodes[0];
            const last = nodes[nodes.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    };

    closeButton.addEventListener('click', close);
    backdrop.addEventListener('mousedown', (event) => {
        if (event.target === backdrop) {
            close();
        }
    });
    document.addEventListener('keydown', onKey, true);
    root.appendChild(backdrop);
    setTimeout(() => {
        const first = focusable().find((node) => node !== closeButton);
        (first || dialog).focus();
    }, 0);

    return { el: dialog, body, footer, close, setTitle: (text) => { title.textContent = text; } };
}
