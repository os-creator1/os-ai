// Contract 17B — the mini toolbar for inline text editing. Deliberately small:
// bold, italic, underline, link (http/https/mailto/tel), alignment, paragraph /
// heading level, and "Insert merge field". No colours, no fonts, no word
// processor. Text is pasted as plain text only (handled by the canvas).
//
// Formatting uses the browser's own editing commands on the contenteditable;
// the canvas turns the resulting DOM into BlockSchema runs with
// serializer.domToRuns on every `input`. The toolbar lives INSIDE the selected
// block's wrapper so it travels with the block and never needs positioning.

import { el, icon } from './dom';
import { normalizeHref } from './serializer';

export function createInline(ctx) {
    const { store, actions } = ctx;
    let editable = null;
    let block = null;
    let savedRange = null;

    const buttons = {};
    const toolbar = el('div', { class: 'de-mini', role: 'toolbar', 'aria-label': 'Text formatting', 'data-role': 'mini-toolbar' });

    // Keep the text selection: nothing in the toolbar may take focus on mousedown.
    toolbar.addEventListener('mousedown', (event) => {
        if (event.target.tagName !== 'INPUT') {
            event.preventDefault();
        }
    });

    function button(key, iconName, label, onclick, text) {
        const node = el('button', { type: 'button', class: 'de-mini-btn', title: label, 'aria-label': label, 'data-cmd': key, onclick }, [iconName ? icon(iconName) : null, text ? el('span', { text }) : null]);
        buttons[key] = node;

        return node;
    }

    const group = (children) => el('div', { class: 'de-mini__group' }, children);

    const linkInput = el('input', { type: 'text', class: 'de-input de-input--sm', placeholder: 'https://, mailto: or tel:', 'aria-label': 'Link address', 'data-role': 'link-input' });
    const linkError = el('div', { class: 'de-mini__error', hidden: true });
    const linkPanel = el('div', { class: 'de-mini__panel', 'data-role': 'link-panel', hidden: true }, [
        el('div', { class: 'de-mini__row' }, [
            linkInput,
            el('button', { type: 'button', class: 'de-btn de-btn--sm de-btn--primary', onclick: () => applyLink(), text: 'Apply' }),
            el('button', { type: 'button', class: 'de-btn de-btn--sm', onclick: () => removeLink(), text: 'Remove' }),
        ]),
        linkError,
    ]);
    linkInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyLink();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closePanels();
            restoreSelection();
        }
    });

    const mergePanel = el('div', { class: 'de-mini__panel de-mini__panel--list', 'data-role': 'merge-panel', hidden: true });

    toolbar.appendChild(group([
        button('bold', 'bold', 'Bold', () => command('bold')),
        button('italic', 'italic', 'Italic', () => command('italic')),
        button('underline', 'underline', 'Underline', () => command('underline')),
        button('link', 'link', 'Link', () => toggleLink()),
    ]));
    toolbar.appendChild(group([
        button('left', 'align-left', 'Align left', () => align('left')),
        button('center', 'align-center', 'Align center', () => align('center')),
        button('right', 'align-right', 'Align right', () => align('right')),
    ]));
    toolbar.appendChild(group([
        button('p', null, 'Paragraph', () => level(0), 'P'),
        button('h1', null, 'Heading 1', () => level(1), 'H1'),
        button('h2', null, 'Heading 2', () => level(2), 'H2'),
        button('h3', null, 'Heading 3', () => level(3), 'H3'),
    ]));
    toolbar.appendChild(group([
        button('merge', null, 'Insert merge field', () => toggleMerge(), 'Insert merge field'),
    ]));
    toolbar.appendChild(linkPanel);
    toolbar.appendChild(mergePanel);

    // ---- selection helpers -------------------------------------------------

    function rememberSelection() {
        const selection = window.getSelection();
        if (selection && selection.rangeCount > 0 && editable && editable.contains(selection.anchorNode)) {
            savedRange = selection.getRangeAt(0).cloneRange();
        }
    }

    function restoreSelection() {
        if (!editable) {
            return;
        }
        editable.focus();
        if (savedRange) {
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(savedRange);
        }
    }

    function changed() {
        if (editable) {
            editable.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function closePanels() {
        linkPanel.hidden = true;
        mergePanel.hidden = true;
        linkError.hidden = true;
    }

    // ---- commands ------------------------------------------------------------

    function command(name) {
        if (!editable) {
            return;
        }
        editable.focus();
        document.execCommand('styleWithCSS', false, false);
        document.execCommand(name, false, null);
        refresh();
    }

    function anchorAtSelection() {
        const selection = window.getSelection();
        let node = selection && selection.anchorNode;
        while (node && node !== editable) {
            if (node.nodeType === 1 && node.nodeName === 'A') {
                return node;
            }
            node = node.parentNode;
        }

        return null;
    }

    function toggleLink() {
        if (!linkPanel.hidden) {
            closePanels();
            return;
        }
        rememberSelection();
        mergePanel.hidden = true;
        const anchor = anchorAtSelection();
        linkInput.value = anchor ? anchor.getAttribute('href') || '' : '';
        linkError.hidden = true;
        linkPanel.hidden = false;
        linkInput.focus();
    }

    function applyLink() {
        const href = normalizeHref(linkInput.value);
        if (linkInput.value.trim() === '') {
            removeLink();
            return;
        }
        if (href === null) {
            linkError.textContent = 'Use an http(s) address, an email address or a phone number.';
            linkError.hidden = false;
            return;
        }

        restoreSelection();
        const anchor = anchorAtSelection();
        const selection = window.getSelection();

        if (selection.isCollapsed && anchor) {
            const range = document.createRange();
            range.selectNodeContents(anchor);
            selection.removeAllRanges();
            selection.addRange(range);
        } else if (selection.isCollapsed) {
            linkPanel.hidden = false;
            linkError.textContent = 'Select the words you want to link first.';
            linkError.hidden = false;
            return;
        }

        document.execCommand('createLink', false, href);
        closePanels();
        changed();
        refresh();
    }

    function removeLink() {
        restoreSelection();
        const anchor = anchorAtSelection();
        const selection = window.getSelection();
        if (selection.isCollapsed && anchor) {
            const range = document.createRange();
            range.selectNodeContents(anchor);
            selection.removeAllRanges();
            selection.addRange(range);
        }
        document.execCommand('unlink', false, null);
        closePanels();
        changed();
        refresh();
    }

    function toggleMerge() {
        if (!mergePanel.hidden) {
            closePanels();
            return;
        }
        rememberSelection();
        linkPanel.hidden = true;
        while (mergePanel.firstChild) {
            mergePanel.removeChild(mergePanel.firstChild);
        }
        let current = null;
        (store.toolbox.merge_fields || []).forEach((field) => {
            if (field.group !== current) {
                current = field.group;
                mergePanel.appendChild(el('div', { class: 'de-mini__heading', text: field.group }));
            }
            const preview = store.mergePreview(field.token);
            mergePanel.appendChild(el('button', { type: 'button', class: 'de-mini__item', 'data-token': field.token, onclick: () => insertMerge(field.token) }, [
                el('span', { text: field.label }),
                preview ? el('small', { text: preview }) : null,
            ]));
        });
        mergePanel.hidden = false;
    }

    function insertMerge(token) {
        restoreSelection();
        const selection = window.getSelection();
        const range = selection.rangeCount > 0 && editable.contains(selection.anchorNode) ? selection.getRangeAt(0) : null;
        const chip = el('span', { class: 'doc-merge', 'data-token': token, contenteditable: 'false', title: 'Merge field: ' + store.mergeLabel(token) });
        chip.textContent = store.mergePreview(token) || store.mergeLabel(token);

        const space = document.createTextNode(' ');
        if (range) {
            range.deleteContents();
            range.insertNode(space);
            range.insertNode(chip);
            range.setStartAfter(space);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
        } else {
            editable.appendChild(chip);
            editable.appendChild(space);
        }
        closePanels();
        changed();
    }

    function align(value) {
        if (block) {
            actions.updateBlock(block.id, { align: value }, { rerender: false });
            editable.className = editable.className.replace(/doc-align-(left|center|right)/, 'doc-align-' + value);
            refresh();
        }
    }

    function level(value) {
        if (!block) {
            return;
        }
        actions.convertText(block.id, value);
    }

    // ---- state ---------------------------------------------------------------

    function refresh() {
        if (!block || !editable) {
            return;
        }
        ['bold', 'italic', 'underline'].forEach((cmd) => {
            let on = false;
            try {
                on = document.queryCommandState(cmd);
            } catch (e) {
                on = false;
            }
            buttons[cmd].classList.toggle('is-active', on);
        });
        buttons.link.classList.toggle('is-active', !!anchorAtSelection());
        ['left', 'center', 'right'].forEach((a) => buttons[a].classList.toggle('is-active', (block.data.align || 'left') === a));
        const current = block.type === 'heading' ? 'h' + (block.data.level || 2) : 'p';
        ['p', 'h1', 'h2', 'h3'].forEach((k) => buttons[k].classList.toggle('is-active', k === current));
    }

    document.addEventListener('selectionchange', () => {
        if (editable && document.activeElement === editable) {
            refresh();
        }
    });

    return {
        attach(node, target, wrapper) {
            editable = node;
            block = target;
            savedRange = null;
            closePanels();
            wrapper.insertBefore(toolbar, wrapper.firstChild);
            toolbar.hidden = false;
            refresh();
        },

        detach() {
            editable = null;
            block = null;
            closePanels();
            if (toolbar.parentNode) {
                toolbar.parentNode.removeChild(toolbar);
            }
        },

        openLink() {
            toggleLink();
        },

        refresh,
        get attached() {
            return editable;
        },
    };
}
