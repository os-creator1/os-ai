// Contract 17B — how the single product block and the payment-terms block draw
// themselves on the canvas. The markup and class names mirror
// resources/views/documents/blocks/types/{product_list,payment_terms}.blade.php
// (the one server renderer) so the canvas looks exactly like Preview and the
// recipient's page; the only additions are the small editing controls, which
// only exist while the document is editable.
//
// Lines, totals and the schedule are the server's: they arrive in
// store.commerce after every line / plan call. Nothing is calculated here.

import { el, icon } from './dom';

function iconButton(name, label, onclick, extra) {
    return el('button', { type: 'button', class: 'de-mini-btn' + (extra ? ' ' + extra : ''), title: label, 'aria-label': label, onclick }, [icon(name)]);
}

/**
 * @param {object} block  a product_list block
 * @param {object} ctx    { store, ops }
 */
export function renderProductBlock(block, ctx) {
    const { store, ops } = ctx;
    const commerce = store.commerce;
    const editable = store.editable;

    // A template holds the product AREA only: a generic placeholder, no wizard, no lines (17B §6).
    if (store.isTemplate) {
        return el('div', { class: 'de-product', 'data-role': 'product-block' }, [
            el('div', { class: 'doc-placeholder', 'data-role': 'product-placeholder' }, [
                el('div', { text: 'Product / pricing block \u2014 you choose the package when you use this template.' }),
            ]),
        ]);
    }
    const data = block.data || {};
    const lines = commerce.lines || [];
    const wrap = el('div', { class: 'de-product', 'data-role': 'product-block' });

    if (commerce.plan_invalid) {
        wrap.appendChild(el('div', { class: 'de-alert de-alert--warn', 'data-role': 'plan-invalid', role: 'alert' }, [
            el('strong', { text: 'The payment terms need attention. ' }),
            el('span', { text: commerce.plan_error || 'The payment terms no longer fit the total.' }),
            editable ? el('button', { type: 'button', class: 'de-link', onclick: () => ops.editTerms(), text: 'Update payment terms' }) : null,
        ]));
    }

    if (lines.length === 0) {
        wrap.appendChild(el('div', { class: 'doc-placeholder', 'data-role': 'product-placeholder' }, [
            el('div', { text: 'Pricing: the products you add appear here, with totals and payment terms.' }),
            editable ? el('div', { class: 'de-product__empty-actions' }, [
                el('button', { type: 'button', class: 'de-btn de-btn--primary', onclick: () => ops.addProduct(), 'data-role': 'product-add-first' }, [icon('plus'), el('span', { text: 'Add product' })]),
                el('button', { type: 'button', class: 'de-btn', onclick: () => ops.customLine() }, [el('span', { text: 'Custom line item' })]),
            ]) : null,
        ]));

        return wrap;
    }

    const showQty = data.show_quantity !== false;
    const showDesc = data.show_description !== false;
    const span = 3;
    const subtotal = commerce.totals.subtotal_minor;
    const total = commerce.totals.total_minor;

    const head = el('tr', null, [
        el('th', { text: 'Item' }),
        el('th', { class: 'num' + (showQty ? '' : ' de-dim'), text: 'Qty' }),
        el('th', { class: 'num', text: 'Unit price' }),
        el('th', { class: 'num', text: 'Total' }),
        editable ? el('th', { class: 'de-col-actions', 'aria-label': 'Line actions' }) : null,
    ]);

    const rows = lines.map((line, index) => {
        const qty = editable
            ? el('input', {
                type: 'number', min: '1', step: '1', class: 'de-qty', value: String(line.quantity), 'aria-label': 'Quantity for ' + line.name,
                onchange: (event) => {
                    const value = parseInt(event.target.value, 10);
                    if (!Number.isFinite(value) || value < 1) {
                        event.target.value = String(line.quantity);
                        return;
                    }
                    if (value !== line.quantity) {
                        ops.setQuantity(line.uid, value);
                    }
                },
            })
            : document.createTextNode(String(line.quantity));

        return el('tr', { 'data-role': 'line', 'data-line-uid': line.uid }, [
            el('td', null, [line.name, showDesc && line.description ? el('div', { class: 'doc-muted', text: line.description }) : null]),
            el('td', { class: 'num' + (showQty ? '' : ' de-dim') }, [qty]),
            el('td', { class: 'num', text: line.unit_price_formatted }),
            el('td', { class: 'num', 'data-role': 'line-total', text: line.line_total_formatted }),
            editable ? el('td', { class: 'de-col-actions' }, [el('div', { class: 'de-line-actions' }, [
                index > 0 ? iconButton('chevron-up', 'Move line up', () => ops.moveLine(line.uid, -1)) : null,
                index < lines.length - 1 ? iconButton('chevron-down', 'Move line down', () => ops.moveLine(line.uid, 1)) : null,
                iconButton('pencil', 'Change product', () => ops.changeLine(line)),
                iconButton('trash-2', 'Remove line', () => ops.removeLine(line), 'de-mini-btn--danger'),
            ])]) : null,
        ]);
    });

    const foot = [];
    if (subtotal !== undefined && subtotal !== total) {
        foot.push(el('tr', null, [el('td', { colspan: span, text: 'Subtotal' }), el('td', { class: 'num', text: commerce.totals.subtotal_formatted }), editable ? el('td') : null]));
    }
    foot.push(el('tr', { class: 'doc-total' }, [el('td', { colspan: span, text: 'Total' }), el('td', { class: 'num', 'data-role': 'total', text: commerce.totals.total_formatted }), editable ? el('td') : null]));

    // A payment-terms block, when present, owns the deposit/balance rows (same rule as the server renderer).
    if ((commerce.schedule || []).length === 2 && !store.hasType('payment_terms')) {
        commerce.schedule.forEach((item) => {
            foot.push(el('tr', { 'data-role': 'schedule-row', 'data-kind': item.kind }, [
                el('td', { colspan: span }, [item.kind === 'deposit' ? 'Deposit ' : 'Balance ', el('span', { class: 'doc-muted', text: '— ' + item.due_label })]),
                el('td', { class: 'num', text: item.amount_formatted }),
                editable ? el('td') : null,
            ]));
        });
    }

    wrap.appendChild(el('table', { class: 'doc-table', 'data-role': 'lines' }, [
        el('thead', null, [head]),
        el('tbody', null, rows),
        el('tfoot', null, foot),
    ]));

    if (editable) {
        wrap.appendChild(el('div', { class: 'de-product__actions' }, [
            el('button', { type: 'button', class: 'de-link', onclick: () => ops.addProduct(), 'data-role': 'product-add-another' }, [icon('plus'), el('span', { text: 'Add another product' })]),
            el('button', { type: 'button', class: 'de-link', onclick: () => ops.createProduct(), 'data-role': 'product-create' }, [el('span', { text: 'Create custom product' })]),
            el('button', { type: 'button', class: 'de-link', onclick: () => ops.customLine(), 'data-role': 'product-custom-line' }, [el('span', { text: 'Custom line item' })]),
            el('button', { type: 'button', class: 'de-link', onclick: () => ops.editTerms(), 'data-role': 'product-edit-terms' }, [icon('wallet'), el('span', { text: (commerce.schedule || []).length > 0 ? 'Change payment terms' : 'Set payment terms' })]),
        ]));
    }

    return wrap;
}

/** The canonical schedule, as the server draws it. */
export function renderPaymentTerms(block, ctx) {
    const { store, ops } = ctx;

    if (store.isTemplate) {
        return el('div', { class: 'doc-placeholder', 'data-role': 'payment-placeholder' }, [
            el('div', { text: 'Payment terms \u2014 the deposit, balance and due dates are set when you use this template.' }),
        ]);
    }
    const schedule = store.commerce.schedule || [];

    if (schedule.length === 0) {
        return el('div', { class: 'doc-placeholder', 'data-role': 'payment-placeholder' }, [
            el('div', { text: 'Payment terms: the deposit, balance and due dates you set appear here.' }),
            store.editable ? el('div', { class: 'de-product__empty-actions' }, [
                el('button', { type: 'button', class: 'de-btn', onclick: () => ops.editTerms(), text: 'Set payment terms' }),
            ]) : null,
        ]);
    }

    return el('div', null, [
        el('table', { class: 'doc-table', 'data-role': 'schedule' }, [el('tbody', null, schedule.map((item) => el('tr', { 'data-role': 'schedule-item', 'data-kind': item.kind }, [
            el('td', { text: item.kind.charAt(0).toUpperCase() + item.kind.slice(1) }),
            el('td', { text: item.due_label }),
            el('td', { class: 'num', text: item.amount_formatted }),
        ])))]),
        store.editable ? el('div', { class: 'de-product__actions' }, [
            el('button', { type: 'button', class: 'de-link', onclick: () => ops.editTerms(), text: 'Change payment terms' }),
        ]) : null,
    ]);
}
