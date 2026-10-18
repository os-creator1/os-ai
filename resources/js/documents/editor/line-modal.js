// Contract 17B — the small "Custom line item" form: a one-off line (name,
// optional description, quantity, price) that is NOT added to the catalog.
// Posts to editor.lines.custom; the server re-applies the stored payment plan.

import { el } from './dom';
import { openModal } from './modal';
import { errorMessage } from './api';
import { toDecimalString, toMinor } from './money';

export function openCustomLineModal(ctx) {
    const { store, api } = ctx;
    const modal = openModal(ctx.modalRoot, { title: 'Custom line item', size: 'sm', className: 'de-customline' });

    const name = el('input', { type: 'text', class: 'de-input', maxlength: '200', 'data-role': 'line-name', 'aria-label': 'Name', placeholder: 'e.g. Travel and setup' });
    const description = el('textarea', { class: 'de-input', rows: '2', maxlength: '2000', 'data-role': 'line-description', 'aria-label': 'Description', placeholder: 'Optional' });
    const quantity = el('input', { type: 'number', class: 'de-input de-input--qty', min: '1', step: '1', value: '1', 'data-role': 'line-quantity', 'aria-label': 'Quantity' });
    const price = el('input', { type: 'text', inputmode: 'decimal', class: 'de-input', placeholder: '0.00', 'data-role': 'line-price', 'aria-label': 'Price' });
    const error = el('div', { class: 'de-wizard__error', role: 'alert', 'data-role': 'line-error', hidden: true });
    const add = el('button', { type: 'button', class: 'de-btn de-btn--primary', 'data-role': 'line-submit', text: 'Add line' });

    modal.body.appendChild(el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Name' }), name]));
    modal.body.appendChild(el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Description (optional)' }), description]));
    modal.body.appendChild(el('div', { class: 'de-row de-row--fields' }, [
        el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Quantity' }), quantity]),
        el('label', { class: 'de-field de-field--grow' }, [el('span', { class: 'de-field__label', text: 'Unit price' }), el('div', { class: 'de-money' }, [el('span', { class: 'de-money__cur', title: 'Your business currency', text: store.currency }), price])]),
    ]));
    modal.footer.appendChild(error);
    modal.footer.appendChild(el('button', { type: 'button', class: 'de-btn', onclick: () => modal.close(), text: 'Cancel' }));
    modal.footer.appendChild(add);

    const show = (message) => {
        error.textContent = message;
        error.hidden = message === '';
    };

    add.addEventListener('click', async () => {
        show('');
        const minor = toMinor(price.value, store.exponent);
        const count = parseInt(quantity.value, 10);
        if (name.value.trim() === '') return show('Give the line a name.');
        if (!Number.isFinite(count) || count < 1) return show('Quantity must be at least 1.');
        if (minor === null || minor <= 0) return show('Enter a price greater than zero, for example 250.00.');

        add.disabled = true;
        const result = await api.mutate('POST', store.urls.lines_custom, {
            name: name.value.trim(), description: description.value.trim() || null, quantity: count, price: toDecimalString(minor, store.exponent),
        });
        add.disabled = false;

        if (!result.ok) {
            if (result.status === 409) {
                modal.close();
            } else {
                show(errorMessage(result, 'The line could not be added.'));
            }
            return;
        }
        store.adoptCommerce(result.json);
        modal.close();
        if (ctx.notify) ctx.notify('Line added.', 'success');
    });

    return modal;
}
