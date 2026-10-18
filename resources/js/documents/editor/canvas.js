// Contract 17B — the printable page canvas.
//
// A structured vertical flow of block wrappers (never absolute positioning).
// Each wrapper carries a small floating action bar (drag handle, up, down,
// duplicate, delete) that only shows on hover / selection. Text and headings
// are contenteditable; everything else is drawn the way the server renderer
// draws it (same doc-* classes) so Preview and the recipient's page match.
//
// Drag and drop is native HTML5: blocks from the toolbox (data type
// text/x-de-tool) and existing blocks by their handle (text/x-de-block), with
// one visible drop indicator between blocks. The up/down buttons and
// Alt+Arrow keys are the non-drag path.

import { el, icon } from './dom';
import { domToRuns, runsToDom } from './serializer';
import { SINGLE, TYPE_LABELS } from './blocks';
import { renderPaymentTerms, renderProductBlock } from './product-block';

const TOOL = 'text/x-de-tool';
const BLOCK = 'text/x-de-block';

export function createCanvas(ctx) {
    const { store, actions, host, scroller } = ctx;
    const editable = () => store.editable;

    const indicator = el('div', { class: 'de-drop', 'data-role': 'drop-indicator', hidden: true, 'aria-hidden': 'true' });

    // ---- rendering -----------------------------------------------------------

    function render() {
        ctx.inline.detach();
        while (host.firstChild) {
            host.removeChild(host.firstChild);
        }

        if (store.blocks.length === 0) {
            host.appendChild(el('div', { class: 'de-empty', 'data-role': 'canvas-empty' }, [
                el('strong', { text: editable() ? 'Start building your document' : 'This document is empty' }),
                editable() ? el('p', { text: 'Drag a block from the left, or click one to add it here.' }) : null,
            ]));
        }

        store.blocks.forEach((block) => host.appendChild(wrapperFor(block)));
        host.appendChild(indicator);
    }

    function wrapperFor(block) {
        const wrapper = el('div', {
            class: 'de-block doc-block doc-block-' + block.type + (store.selectedId === block.id ? ' is-selected' : ''),
            'data-block-id': block.id,
            'data-block-type': block.type,
            tabindex: '0',
            'aria-label': TYPE_LABELS[block.type] || block.type,
        });

        wrapper.appendChild(el('div', { class: 'de-block__body' }, [content(block, wrapper)]));
        if (editable()) {
            wrapper.appendChild(actionBar(block));
        }

        wrapper.addEventListener('mousedown', () => select(block.id));
        wrapper.addEventListener('focus', (event) => {
            if (event.target === wrapper) {
                select(block.id);
            }
        });
        wrapper.addEventListener('keydown', (event) => {
            if (!editable() || event.target !== wrapper) {
                return;
            }
            if (event.altKey && event.key === 'ArrowUp') {
                event.preventDefault();
                actions.moveBy(block.id, -1);
            } else if (event.altKey && event.key === 'ArrowDown') {
                event.preventDefault();
                actions.moveBy(block.id, 1);
            }
        });

        return wrapper;
    }

    function actionBar(block) {
        const single = SINGLE.indexOf(block.type) !== -1;
        const index = store.indexOf(block.id);
        const btn = (name, label, fn, extra) => el('button', {
            type: 'button', class: 'de-mini-btn' + (extra ? ' ' + extra : ''), title: label, 'aria-label': label,
            onmousedown: (event) => event.stopPropagation(),
            onclick: (event) => { event.stopPropagation(); fn(); },
        }, [icon(name)]);

        const handle = el('span', { class: 'de-handle', draggable: 'true', title: 'Drag to reorder', role: 'img', 'aria-label': 'Drag to reorder' }, [icon('grip-vertical')]);
        handle.addEventListener('dragstart', (event) => {
            ctx.drag = { kind: 'block', id: block.id };
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData(BLOCK, block.id);
            event.dataTransfer.setData('text/plain', block.id);
            const node = host.querySelector('[data-block-id="' + block.id + '"]');
            if (node && event.dataTransfer.setDragImage) {
                event.dataTransfer.setDragImage(node, 16, 16);
            }
            if (node) {
                node.classList.add('is-dragging');
            }
        });
        handle.addEventListener('dragend', () => {
            ctx.drag = null;
            hideIndicator();
            const node = host.querySelector('.is-dragging');
            if (node) {
                node.classList.remove('is-dragging');
            }
        });

        return el('div', { class: 'de-block__bar', role: 'toolbar', 'aria-label': 'Block actions' }, [
            handle,
            index > 0 ? btn('chevron-up', 'Move up', () => actions.moveBy(block.id, -1)) : null,
            index < store.blocks.length - 1 ? btn('chevron-down', 'Move down', () => actions.moveBy(block.id, 1)) : null,
            single ? null : btn('copy', 'Duplicate', () => actions.duplicate(block.id)),
            btn('trash-2', 'Delete', () => actions.remove(block.id), 'de-mini-btn--danger'),
        ]);
    }

    function content(block, wrapper) {
        const data = block.data || {};
        const ops = ctx.productOps;

        switch (block.type) {
            case 'heading':
            case 'text':
                return textBlock(block, wrapper);
            case 'section':
                return sectionBlock(block);
            case 'divider':
                return el('hr', { class: 'doc-divider' });
            case 'spacer':
                return el('div', { class: 'de-spacer', style: 'height:' + Math.max(8, Math.min(120, parseInt(data.height, 10) || 24)) + 'px', title: 'Spacer', 'aria-label': 'Spacer' });
            case 'page_break':
                return el('div', { class: 'de-page-break', 'data-role': 'page-break' }, [el('span', { text: 'Page break' }), el('small', { text: 'A new page starts here when printed' })]);
            case 'image':
                return imageBlock(block);
            case 'business_details':
                return el('div', { class: 'doc-details' }, (data.show || []).map((field) => el('div', null, [
                    el('span', { class: 'doc-merge', title: 'Filled in from your business details', text: field === 'name' && store.business.name ? store.business.name : 'Business ' + field }),
                ])));
            case 'product_list':
                return renderProductBlock(block, { store, ops });
            case 'payment_terms':
                return renderPaymentTerms(block, { store, ops });
            case 'signature':
                return el('div', { class: 'doc-placeholder', 'data-role': 'signature-placeholder' }, [(data.label || 'Signature') + ' — the signer types their name here.']);
            default:
                return el('div', { class: 'doc-placeholder', text: block.type });
        }
    }

    function imageBlock(block) {
        const data = block.data || {};
        const image = store.images.find((candidate) => candidate.uid === data.catalog_image_uid);

        if (image) {
            return el('img', {
                class: 'doc-image', src: image.url, alt: data.alt || '', style: 'width:' + Math.max(10, Math.min(100, parseInt(data.width_pct, 10) || 100)) + '%',
            });
        }

        return el('div', { class: 'doc-placeholder de-image-empty', 'data-role': 'image-placeholder' }, [
            data.catalog_image_uid
                ? 'This image is no longer available.'
                : (store.images.length === 0
                    ? 'Add a picture to one of your packages or products first, then choose it here.'
                    : 'Choose one of your catalog images in the panel on the right.'),
        ]);
    }

    function sectionBlock(block) {
        const node = el('div', { class: 'doc-section', contenteditable: editable() ? 'true' : 'false', 'data-placeholder': 'Section title', spellcheck: 'true', role: 'textbox', 'aria-label': 'Section title' });
        node.textContent = (block.data && block.data.title) || '';
        node.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
        node.addEventListener('paste', (event) => pastePlain(event));
        node.addEventListener('input', () => {
            block.data.title = node.textContent.replace(/\s+/g, ' ').trim().slice(0, 200);
            ctx.autosave.markDirty();
        });

        return node;
    }

    function textBlock(block, wrapper) {
        const data = block.data || {};
        const isHeading = block.type === 'heading';
        const level = Math.max(1, Math.min(3, parseInt(data.level, 10) || 2));
        const align = ['left', 'center', 'right'].indexOf(data.align) !== -1 ? data.align : 'left';
        const node = el(isHeading ? 'h' + level : 'p', {
            class: (isHeading ? '' : 'doc-text ') + 'doc-align-' + align + ' de-editable',
            contenteditable: editable() ? 'true' : 'false',
            'data-placeholder': isHeading ? 'Heading' : 'Type your text, or insert a merge field',
            spellcheck: 'true',
            role: 'textbox',
            'aria-multiline': 'true',
            'aria-label': isHeading ? 'Heading text' : 'Text',
        });

        runsToDom(document, node, data.runs || [],
            (token) => store.mergePreview(token) || store.mergeLabel(token),
            (token) => 'Merge field: ' + store.mergeLabel(token));

        if (!editable()) {
            return node;
        }

        node.addEventListener('focus', () => {
            select(block.id);
            ctx.inline.attach(node, block, wrapper);
        });

        node.addEventListener('input', () => {
            if (node.textContent === '' && !node.querySelector('[data-token]')) {
                while (node.firstChild) {
                    node.removeChild(node.firstChild);
                }
            }
            block.data.runs = domToRuns(node);
            ctx.autosave.markDirty();
        });

        node.addEventListener('paste', (event) => pastePlain(event));

        node.addEventListener('drop', (event) => {
            const types = Array.prototype.slice.call((event.dataTransfer && event.dataTransfer.types) || []);
            if (types.indexOf(TOOL) === -1 && types.indexOf(BLOCK) === -1) {
                event.preventDefault(); // never accept dropped rich text / files
            }
        });

        node.addEventListener('click', (event) => {
            if (event.target.closest && event.target.closest('a')) {
                event.preventDefault();
            }
        });

        node.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                ctx.inline.openLink();
                return;
            }
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                if (caretAtEnd(node)) {
                    actions.insertAfter(block.id, 'text');
                } else {
                    document.execCommand('insertLineBreak');
                }
            } else if (event.key === 'Enter' && event.shiftKey) {
                event.preventDefault();
                document.execCommand('insertLineBreak');
            }
        });

        return node;
    }

    function caretAtEnd(node) {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || !selection.isCollapsed || !node.contains(selection.anchorNode)) {
            return false;
        }
        const range = document.createRange();
        range.selectNodeContents(node);
        range.setStart(selection.anchorNode, selection.anchorOffset);

        return range.toString().replace(/​/g, '') === '' && !range.cloneContents().querySelector('[data-token]');
    }

    function pastePlain(event) {
        event.preventDefault();
        const clipboard = event.clipboardData || window.clipboardData;
        const text = clipboard ? clipboard.getData('text/plain') : '';
        if (text) {
            document.execCommand('insertText', false, text.replace(/\r\n?/g, '\n'));
        }
    }

    // ---- selection ------------------------------------------------------------

    function select(id) {
        if (store.selectedId === id) {
            return;
        }
        ctx.inline.detach();
        store.selectedId = id;
        Array.prototype.forEach.call(host.querySelectorAll('.de-block'), (node) => {
            node.classList.toggle('is-selected', node.getAttribute('data-block-id') === id);
        });
        store.emit('selection');
    }

    function deselect() {
        if (store.selectedId === null) {
            return;
        }
        ctx.inline.detach();
        store.selectedId = null;
        Array.prototype.forEach.call(host.querySelectorAll('.de-block'), (node) => node.classList.remove('is-selected'));
        store.emit('selection');
    }

    scroller.addEventListener('mousedown', (event) => {
        if (event.target === scroller || event.target === host) {
            deselect();
        }
    });

    /** Redraw one block in place (not text blocks while typing). */
    function renderBlock(id) {
        const block = store.block(id);
        const old = host.querySelector('[data-block-id="' + id + '"]');
        if (!block || !old) {
            return;
        }
        old.replaceWith(wrapperFor(block));
    }

    function renderTypes(types) {
        store.blocks.forEach((block) => {
            if (types.indexOf(block.type) !== -1) {
                renderBlock(block.id);
            }
        });
    }

    function focusBlock(id, atEnd) {
        const node = host.querySelector('[data-block-id="' + id + '"]');
        if (!node) {
            return;
        }
        const field = node.querySelector('[contenteditable="true"]');
        if (field) {
            field.focus();
            if (atEnd !== false) {
                const range = document.createRange();
                range.selectNodeContents(field);
                range.collapse(false);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
            }
        } else {
            node.focus({ preventScroll: true });
        }
        node.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    // ---- drag and drop ----------------------------------------------------------

    function dragKind(event) {
        const types = Array.prototype.slice.call((event.dataTransfer && event.dataTransfer.types) || []);
        if (types.indexOf(TOOL) !== -1) {
            return 'tool';
        }
        if (types.indexOf(BLOCK) !== -1) {
            return 'block';
        }

        return null;
    }

    function wrappers() {
        return Array.prototype.slice.call(host.querySelectorAll(':scope > .de-block'));
    }

    function indexAt(clientY) {
        const list = wrappers();
        for (let i = 0; i < list.length; i += 1) {
            const rect = list[i].getBoundingClientRect();
            if (clientY < rect.top + rect.height / 2) {
                return i;
            }
        }

        return list.length;
    }

    function showIndicator(index) {
        const list = wrappers();
        let top = 0;
        if (list.length > 0) {
            top = index < list.length ? list[index].offsetTop - 8 : list[list.length - 1].offsetTop + list[list.length - 1].offsetHeight + 4;
        } else {
            top = 48;
        }
        indicator.style.top = top + 'px';
        indicator.hidden = false;
        indicator.setAttribute('data-index', String(index));
    }

    function hideIndicator() {
        indicator.hidden = true;
    }

    host.addEventListener('dragover', (event) => {
        if (!editable()) {
            return;
        }
        const kind = dragKind(event);
        if (!kind) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = kind === 'tool' ? 'copy' : 'move';
        showIndicator(indexAt(event.clientY));

        const rect = scroller.getBoundingClientRect();
        if (event.clientY < rect.top + 60) {
            scroller.scrollTop -= 14;
        } else if (event.clientY > rect.bottom - 60) {
            scroller.scrollTop += 14;
        }
    });

    host.addEventListener('dragleave', (event) => {
        if (!event.relatedTarget || !host.contains(event.relatedTarget)) {
            hideIndicator();
        }
    });

    host.addEventListener('drop', (event) => {
        if (!editable()) {
            return;
        }
        const kind = dragKind(event);
        if (!kind) {
            return;
        }
        event.preventDefault();
        const index = indexAt(event.clientY);
        hideIndicator();

        if (kind === 'tool') {
            actions.addTool(event.dataTransfer.getData(TOOL), index);
        } else {
            const id = event.dataTransfer.getData(BLOCK);
            const from = store.indexOf(id);
            if (from !== -1) {
                actions.moveBlock(id, index > from ? index - 1 : index);
            }
        }
        ctx.drag = null;
    });

    document.addEventListener('dragend', hideIndicator);

    // The structure the canvas shows changes with the commerce payload too.
    store.subscribe((topic) => {
        if (topic === 'commerce') {
            renderTypes(['product_list', 'payment_terms']);
        }
    });

    return { render, renderBlock, renderTypes, select, deselect, focusBlock, hideIndicator };
}

export const DRAG_TYPES = { TOOL, BLOCK };
