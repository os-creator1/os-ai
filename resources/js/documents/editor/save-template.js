// Contract 17B §6 — the "Save as template" dialog (document mode only).
//
// A template saves the LAYOUT: text, headings, your images, merge fields, where
// the signature goes and a generic product area. It never saves the product,
// the contact, prices, payment terms or anything about sending / signing. The
// server builds the template from the document's saved blocks only
// (DocumentTemplateService::saveFromDocument); this just collects a name, a
// type and an optional description.

import { errorMessage } from './api';
import { el } from './dom';
import { openModal } from './modal';

export function openSaveTemplateDialog(ctx) {
    const { store, api, autosave, modalRoot, notify } = ctx;

    const modal = openModal(modalRoot, { title: 'Save as template', size: 'sm', className: 'de-save-template' });

    const name = el('input', { class: 'de-input', type: 'text', maxlength: '191', required: true, value: store.title, 'data-role': 'template-name', 'aria-label': 'Template name' });
    const type = el('select', { class: 'de-input', 'data-role': 'template-type-select', 'aria-label': 'Template type' }, [
        el('option', { value: 'proposal', text: 'Proposal' }),
        el('option', { value: 'contract', text: 'Contract' }),
    ]);
    const description = el('textarea', { class: 'de-input', rows: '2', maxlength: '1000', 'data-role': 'template-description', 'aria-label': 'Description (optional)', placeholder: 'Optional: when to use this template' });
    const error = el('p', { class: 'de-field__error', role: 'alert', hidden: true, 'data-role': 'template-error' });

    modal.body.appendChild(el('p', { class: 'de-muted', 'data-role': 'template-explainer', text: 'This saves the layout, not the product or contact. When you use the template you choose the contact and add the product again.' }));
    modal.body.appendChild(el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Template name' }), name]));
    modal.body.appendChild(el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Type' }), type]));
    modal.body.appendChild(el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Description (optional)' }), description]));
    modal.body.appendChild(error);

    const save = el('button', { type: 'button', class: 'de-btn de-btn--primary', 'data-role': 'template-save', text: 'Save template' });
    modal.footer.appendChild(el('button', { type: 'button', class: 'de-btn', text: 'Cancel', onclick: () => modal.close() }));
    modal.footer.appendChild(save);

    const show = (text) => {
        error.textContent = text || '';
        error.hidden = !text;
    };

    let busy = false;

    save.addEventListener('click', async () => {
        if (busy) {
            return;
        }
        if (name.value.trim() === '') {
            show('Give the template a name.');
            name.focus();
            return;
        }
        busy = true;
        save.disabled = true;
        show('');

        try {
            // The template is built from the SAVED blocks, so push any pending edit first.
            const saved = await autosave.flush();
            if (!saved && store.editable) {
                show(store.save.state === 'conflict' ? 'This document was changed in another tab. Reload before saving a template.' : 'Your latest changes could not be saved, so the template was not created.');
                return;
            }

            const result = await api.post(store.urls.save_template, {
                name: name.value.trim(),
                template_type: type.value,
                description: description.value.trim() === '' ? null : description.value.trim(),
            });

            if (!result.ok) {
                show(errorMessage(result, 'The template could not be saved.'));
                return;
            }

            modal.close();
            notify('Template saved.', 'success', { href: result.json.library_url || store.urls.template_library, text: 'Open template library' });
        } finally {
            busy = false;
            save.disabled = false;
        }
    });

    return modal;
}
