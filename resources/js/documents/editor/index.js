// Contract 17B — the Proposal / Contract visual editor, entry point.
//
// Architecture (vanilla ES modules, no framework):
//   state.js        the store: blocks, title, commerce payload, lock version, save state
//   api.js          fetch + ONE promise queue for every document mutation
//   autosave.js     ~800ms debounced PUT editor.blocks, flush for Save / Preview / Send
//   canvas.js       page canvas: block wrappers, selection, native drag and drop
//   inline-text.js  mini toolbar for contenteditable text (serializer.js: DOM <-> runs)
//   inspector.js    contextual right panel
//   toolbox.js      left toolbox (server-rendered buttons) + narrow-screen drawer
//   product-*.js    pricing block drawing, add-product wizard, custom line form
//   send-dialog.js  checklist + Email/SMS send
//
// All truth stays on the server: the editor sends blocks and plan INTENT,
// and renders whatever lines / totals / schedule the server answers with.

import { createApi, errorMessage } from './api';
import { createAutosave } from './autosave';
import { createCanvas } from './canvas';
import { el, icon } from './dom';
import { cloneBlock, newBlock, savePayload, SINGLE } from './blocks';
import { createInline } from './inline-text';
import { createInspector } from './inspector';
import { openCustomLineModal } from './line-modal';
import { openModal } from './modal';
import { openProductWizard } from './product-wizard';
import { openSendDialog } from './send-dialog';
import { createStore } from './state';
import { createToolbox } from './toolbox';

const SAVE_TEXT = {
    saved: 'All changes saved',
    dirty: 'Unsaved changes',
    saving: 'Saving...',
};

function init(root) {
    if (!root || root.getAttribute('data-initialized') === '1') {
        return;
    }
    root.setAttribute('data-initialized', '1');

    const bootNode = document.getElementById('document-editor-bootstrap');
    if (!bootNode) {
        return;
    }
    const store = createStore(JSON.parse(bootNode.textContent));
    const api = createApi(store);
    const banners = root.querySelector('[data-role="editor-banners"]');
    const modalRoot = root.querySelector('[data-role="modal-root"]');

    // ---- toasts ---------------------------------------------------------------

    const toasts = el('div', { class: 'de-toasts', 'aria-live': 'polite' });
    root.appendChild(toasts);

    function notify(message, kind) {
        const toast = el('div', { class: 'de-toast de-toast--' + (kind || 'info'), role: 'status', text: message });
        toasts.appendChild(toast);
        setTimeout(() => toast.remove(), kind === 'error' ? 7000 : 3500);
    }

    // ---- legacy: only the upgrade prompt ----------------------------------------

    if (root.getAttribute('data-legacy') === '1') {
        initLegacy();
        return;
    }

    const autosave = createAutosave(store, api);
    const canvasHost = root.querySelector('[data-role="editor-canvas"]');

    const ctx = {
        root, store, api, autosave, notify, modalRoot,
        host: canvasHost,
        scroller: root.querySelector('.de-canvas-scroll'),
        drag: null,
        actions: {},
        productOps: {},
    };
    ctx.inline = createInline(ctx);
    ctx.canvas = createCanvas(ctx);
    ctx.toolbox = createToolbox(ctx);
    ctx.inspector = createInspector(ctx);

    const { actions, canvas } = ctx;

    // ---- block operations ---------------------------------------------------------

    function limit() {
        return (store.toolbox.limits && store.toolbox.limits.max_blocks) || 200;
    }

    function defaultIndex() {
        const selected = store.selectedId ? store.indexOf(store.selectedId) : -1;
        if (selected !== -1) {
            return selected + 1;
        }
        const last = store.blocks[store.blocks.length - 1];

        return last && last.type === 'signature' ? store.blocks.length - 1 : store.blocks.length;
    }

    function changed(focusId) {
        store.selectedId = focusId !== undefined ? focusId : store.selectedId;
        canvas.render();
        ctx.toolbox.refresh();
        store.emit('selection');
        autosave.markDirty();
    }

    function insertBlock(block, index) {
        if (!block) {
            return null;
        }
        if (store.blocks.length >= limit()) {
            notify('A document can have at most ' + limit() + ' blocks.', 'error');
            return null;
        }
        const at = index === null || index === undefined ? defaultIndex() : Math.max(0, Math.min(index, store.blocks.length));
        store.blocks.splice(at, 0, block);
        changed(block.id);
        canvas.focusBlock(block.id);

        return block;
    }

    function ensureProductBlock(index) {
        const existing = store.blocks.find((b) => b.type === 'product_list');
        if (existing) {
            if (store.selectedId !== existing.id) {
                canvas.select(existing.id);
            }
            return existing;
        }

        return insertBlock(newBlock('product'), index);
    }

    actions.addTool = (toolId, index) => {
        if (!store.editable) {
            return;
        }
        switch (toolId) {
            case 'product':
                if (ensureProductBlock(index)) {
                    ctx.productOps.addProduct();
                }
                return;
            case 'custom_line':
                if (ensureProductBlock(index)) {
                    ctx.productOps.customLine();
                }
                return;
            case 'signature': {
                const existing = store.blocks.find((b) => b.type === 'signature');
                if (existing) {
                    notify('A document has one signature block.', 'info');
                    canvas.select(existing.id);
                    canvas.focusBlock(existing.id);
                    return;
                }
                insertBlock(newBlock('signature'), index);
                return;
            }
            case 'payment_terms':
                if (store.hasType('payment_terms')) {
                    notify('Payment terms are already on this document.', 'info');
                    return;
                }
                insertBlock(newBlock('payment_terms'), index);
                return;
            default:
                insertBlock(newBlock(toolId), index);
        }
    };

    actions.moveBlock = (id, toIndex) => {
        const from = store.indexOf(id);
        if (from === -1 || from === toIndex) {
            return;
        }
        const [block] = store.blocks.splice(from, 1);
        store.blocks.splice(Math.max(0, Math.min(toIndex, store.blocks.length)), 0, block);
        changed(id);
    };

    actions.moveBy = (id, delta) => {
        const from = store.indexOf(id);
        const to = from + delta;
        if (from === -1 || to < 0 || to >= store.blocks.length) {
            return;
        }
        actions.moveBlock(id, to);
        const node = canvasHost.querySelector('[data-block-id="' + id + '"]');
        if (node) {
            node.focus({ preventScroll: true });
            node.scrollIntoView({ block: 'nearest' });
        }
    };

    actions.duplicate = (id) => {
        const block = store.block(id);
        if (!block || SINGLE.indexOf(block.type) !== -1) {
            return;
        }
        insertBlock(cloneBlock(block), store.indexOf(id) + 1);
    };

    actions.insertAfter = (id, toolId) => {
        insertBlock(newBlock(toolId), store.indexOf(id) + 1);
    };

    actions.remove = async (id) => {
        const block = store.block(id);
        if (!block) {
            return;
        }
        if (block.type === 'product_list' && (store.commerce.lines || []).length > 0) {
            const ok = await confirmDialog('Remove the pricing block?', 'This also removes its ' + store.commerce.lines.length + ' line(s) from the document.', 'Remove pricing');
            if (!ok) {
                return;
            }
            for (const line of store.commerce.lines.slice()) {
                const result = await api.mutate('DELETE', store.urls.line_template.replace('__LINE__', line.uid), {});
                if (result.ok) {
                    store.adoptCommerce(result.json);
                } else {
                    if (result.status !== 409) {
                        notify(errorMessage(result, 'A line could not be removed.'), 'error');
                    }
                    return;
                }
            }
        }
        const index = store.indexOf(id);
        if (index === -1) {
            return;
        }
        store.blocks.splice(index, 1);
        changed(store.selectedId === id ? null : store.selectedId);
    };

    actions.updateBlock = (id, patch, options) => {
        const block = store.block(id);
        if (!block || !store.editable) {
            return;
        }
        Object.assign(block.data, patch);
        autosave.markDirty();
        if (!options || options.rerender !== false) {
            canvas.renderBlock(id);
        }
    };

    actions.convertText = (id, level) => {
        const block = store.block(id);
        if (!block || (block.type !== 'text' && block.type !== 'heading')) {
            return;
        }
        const data = { align: block.data.align || 'left', runs: block.data.runs || [] };
        if (level === 0) {
            block.type = 'text';
            block.data = data;
        } else {
            block.type = 'heading';
            block.data = { level, ...data };
        }
        changed(id);
        canvas.focusBlock(id);
    };

    // ---- product operations -----------------------------------------------------------

    function lineUrl(uid) {
        return store.urls.line_template.replace('__LINE__', uid);
    }

    function handle(result, failure) {
        if (result.ok) {
            store.adoptCommerce(result.json);
        } else {
            if (result.status !== 409) {
                notify(errorMessage(result, failure), 'error');
            }
            store.emit('commerce');
        }
    }

    const hasTerms = () => !!store.commerce.plan || (store.commerce.schedule || []).length > 0;

    Object.assign(ctx.productOps, {
        addProduct() {
            ensureProductBlock();
            openProductWizard(ctx, { mode: (store.commerce.lines || []).length === 0 && !hasTerms() ? 'first' : 'add' });
        },
        createProduct() {
            ensureProductBlock();
            openProductWizard(ctx, { mode: (store.commerce.lines || []).length === 0 && !hasTerms() ? 'first' : 'add', startCreate: true });
        },
        customLine() {
            ensureProductBlock();
            openCustomLineModal(ctx);
        },
        editTerms() {
            if ((store.commerce.lines || []).length === 0) {
                ctx.productOps.addProduct();
                return;
            }
            openProductWizard(ctx, { mode: 'terms' });
        },
        changeLine(line) {
            openProductWizard(ctx, { mode: 'replace', line });
        },
        async setQuantity(uid, quantity) {
            handle(await api.mutate('PATCH', lineUrl(uid), { quantity }), 'The quantity could not be changed.');
        },
        async removeLine(line) {
            handle(await api.mutate('DELETE', lineUrl(line.uid), {}), 'The line could not be removed.');
        },
        async moveLine(uid, delta) {
            const uids = (store.commerce.lines || []).map((line) => line.uid);
            const from = uids.indexOf(uid);
            const to = from + delta;
            if (from === -1 || to < 0 || to >= uids.length) {
                return;
            }
            uids.splice(to, 0, uids.splice(from, 1)[0]);
            handle(await api.mutate('PUT', store.urls.lines_order, { line_uids: uids }), 'The lines could not be reordered.');
        },
    });

    // ---- confirm ---------------------------------------------------------------------------

    function confirmDialog(title, text, confirmLabel) {
        return new Promise((resolve) => {
            let answered = false;
            const modal = openModal(modalRoot, { title, size: 'sm', onClose: () => { if (!answered) resolve(false); } });
            modal.body.appendChild(el('p', { text }));
            modal.footer.appendChild(el('button', { type: 'button', class: 'de-btn', text: 'Cancel', onclick: () => modal.close() }));
            modal.footer.appendChild(el('button', { type: 'button', class: 'de-btn de-btn--danger', 'data-role': 'confirm-yes', text: confirmLabel, onclick: () => { answered = true; resolve(true); modal.close(); } }));
        });
    }

    // ---- header ------------------------------------------------------------------------------

    const indicator = root.querySelector('[data-role="save-indicator"]');
    const titleInput = root.querySelector('[data-role="editor-title"]');
    let errorBanner = null;
    let conflictBanner = null;

    function paintSave() {
        const { state, message } = store.save;
        if (indicator) {
            indicator.setAttribute('data-state', state);
            indicator.textContent = state === 'error' ? 'Not saved' : (state === 'conflict' ? 'Out of date' : SAVE_TEXT[state] || '');
            indicator.title = message || '';
        }

        if (errorBanner && state !== 'error') {
            errorBanner.remove();
            errorBanner = null;
        }
        if (state === 'error' && banners) {
            if (!errorBanner) {
                errorBanner = el('div', { class: 'de-banner de-banner--danger', 'data-role': 'save-error-banner' });
                banners.appendChild(errorBanner);
            }
            errorBanner.textContent = message || 'This change could not be saved.';
        }
        if (state === 'conflict' && banners && !conflictBanner) {
            conflictBanner = el('div', { class: 'de-banner de-banner--danger', 'data-role': 'conflict-banner' }, [
                el('span', { text: 'This document was changed in another tab. Your recent edits here were not saved. ' }),
                el('button', { type: 'button', class: 'de-btn de-btn--sm', 'data-role': 'conflict-reload', text: 'Reload', onclick: () => window.location.reload() }),
            ]);
            banners.appendChild(conflictBanner);
        }
    }

    store.subscribe((topic) => {
        if (topic === 'save') {
            paintSave();
            if (store.save.state === 'conflict') {
                setBusyControls();
            }
        }
        if (topic === 'selection' || topic === 'commerce') {
            ctx.toolbox.refresh();
        }
    });

    function setBusyControls() {
        // Stop editing: nothing more can be saved from this stale copy, and nothing is overwritten.
        root.classList.add('is-conflict');
        store.editable = false;
        canvasHost.querySelectorAll('[contenteditable="true"]').forEach((node) => node.setAttribute('contenteditable', 'false'));
        ctx.inline.detach();
        ctx.toolbox.refresh();
        root.querySelectorAll('[data-role="action-send"], [data-role="action-save"]').forEach((node) => { node.disabled = true; });
    }

    if (titleInput && store.editable) {
        titleInput.addEventListener('input', () => {
            const value = titleInput.value.trim();
            if (value === '' || value === store.title) {
                return;
            }
            store.title = value;
            autosave.markDirty();
        });
        titleInput.addEventListener('blur', () => {
            if (titleInput.value.trim() === '') {
                titleInput.value = store.title;
            }
        });
        titleInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                titleInput.blur();
            }
        });
    }

    const saveButton = root.querySelector('[data-role="action-save"]');
    if (saveButton) {
        saveButton.addEventListener('click', async () => {
            const ok = await autosave.flush();
            if (ok) {
                notify('Saved.', 'success');
            }
        });
    }

    const previewButton = root.querySelector('[data-role="action-preview"]');
    if (previewButton) {
        previewButton.addEventListener('click', async () => {
            if (store.editable) {
                const ok = await autosave.flush();
                if (!ok && store.save.state !== 'conflict') {
                    notify('Preview shows your last saved version.', 'info');
                }
            }
            const modal = openModal(modalRoot, { title: 'Preview', size: 'xl', className: 'de-preview' });
            modal.body.appendChild(el('iframe', { class: 'de-preview__frame', src: store.urls.preview, title: 'Document preview', 'data-role': 'preview-frame' }));
            modal.footer.appendChild(el('a', { class: 'de-btn', href: store.urls.preview, target: '_blank', rel: 'noopener', text: 'Open in new tab' }));
            modal.footer.appendChild(el('button', { type: 'button', class: 'de-btn de-btn--primary', text: 'Close', onclick: () => modal.close() }));
        });
    }

    const sendButton = root.querySelector('[data-role="action-send"]');
    if (sendButton) {
        sendButton.addEventListener('click', () => openSendDialog(ctx));
    }

    const moreButton = root.querySelector('[data-role="action-more"]');
    const moreMenu = root.querySelector('[data-role="more-menu"]');
    if (moreButton && moreMenu) {
        const setMenu = (open) => {
            moreMenu.hidden = !open;
            moreButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        moreButton.addEventListener('click', (event) => {
            event.stopPropagation();
            setMenu(moreMenu.hidden);
        });
        document.addEventListener('click', () => setMenu(false));
        moreMenu.addEventListener('click', (event) => event.stopPropagation());
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setMenu(false);
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && store.editable) {
            event.preventDefault();
            autosave.flush();
        }
    });

    // The guard stays on while anything is unsaved or in flight.
    window.addEventListener('beforeunload', (event) => {
        if (store.editable && (store.save.state === 'dirty' || store.save.state === 'saving' || store.dirty || api.busy())) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // ---- go ----------------------------------------------------------------------------------------

    canvas.render();
    ctx.toolbox.refresh();
    paintSave();

    // Handy for tests and support; not part of the page contract.
    root.__documentEditor = { store, api, autosave, canvas, actions, ctx };

    // ---- legacy ------------------------------------------------------------------------------------

    function initLegacy() {
        const button = root.querySelector('[data-role="legacy-upgrade-button"]');
        const message = root.querySelector('[data-role="legacy-error"]');
        if (!button) {
            return;
        }
        button.addEventListener('click', async () => {
            button.disabled = true;
            message.hidden = true;
            const result = await api.mutate('POST', store.urls.upgrade, {});
            if (result.ok) {
                window.location.reload();
                return;
            }
            button.disabled = false;
            message.textContent = result.status === 409
                ? 'This document was changed in another tab. Reload the page and try again.'
                : errorMessage(result, 'The document could not be upgraded.');
            message.hidden = false;
        });
    }
}

window.DocumentEditor = { init };

export { init, icon, savePayload };
