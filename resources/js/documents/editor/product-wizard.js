// Contract 17B — the "Add product" wizard.
//
//   Step 1  choose (or create) a product / package from the Business catalog
//   Step 2  how should this be paid?  Full payment | Deposit + balance
//   Step 3  (deposit only) deposit amount, live remaining balance, balance due
//
// Modes: `first` / `add` / `replace` / `terms`. `add` and `replace` stop after
// step 1 when the document already has payment terms (the server re-applies
// the stored plan after a line change); `terms` reopens steps 2-3 only.
//
// Money: shown through Intl.NumberFormat in the document currency, typed as a
// plain decimal, sent to the API as a decimal string ("250.00"). Integer minor
// units exist only inside money.js to do exact arithmetic and are never shown.
// The server is the authority on the schedule; this only collects intent.

import { el, icon, debounce } from './dom';
import { openModal } from './modal';
import { errorMessage } from './api';
import { depositState, formatDate, toDecimalString, toMinor } from './money';

export function openProductWizard(ctx, options) {
    const { store, api } = ctx;
    const existingPlan = store.commerce.plan;
    const hasTerms = !!existingPlan || (store.commerce.schedule || []).length > 0;

    const state = {
        mode: options.mode || 'first',
        step: options.mode === 'terms' ? 2 : 1,
        item: null,
        quantity: '1',
        priceInput: '',
        search: '',
        results: [],
        loading: false,
        creating: !!options.startCreate,
        createError: '',
        replaceLine: options.line || null,
        itemAdded: false,
        busy: false,
        error: '',
        dates: null,
        plan: {
            structure: existingPlan && existingPlan.structure === 'deposit' ? 'deposit' : 'full',
            full_due: (existingPlan && existingPlan.full_due) || 'on_signing',
            full_due_date: (existingPlan && existingPlan.full_due_date) || '',
            balance_due: (existingPlan && existingPlan.balance_due) || 'after_deposit',
            balance_due_date: (existingPlan && existingPlan.balance_due_date) || '',
            deposit: (existingPlan && existingPlan.deposit_input) || '',
        },
    };

    const needsTerms = state.mode === 'first' || state.mode === 'terms' || ((state.mode === 'add' || state.mode === 'replace') && !hasTerms);

    const modal = openModal(ctx.modalRoot, { title: titleFor(), size: 'md', className: 'de-wizard' });
    const stepLabel = el('div', { class: 'de-wizard__step', 'data-role': 'wizard-step' });
    modal.el.querySelector('.de-modal__head').insertBefore(stepLabel, modal.el.querySelector('.de-modal__title').nextSibling);

    function titleFor() {
        return { first: 'Add a product', add: 'Add another product', replace: 'Change product', terms: 'Payment terms' }[state.mode];
    }

    // ---- derived values ------------------------------------------------------

    const exponent = store.exponent;
    const qty = () => {
        const n = parseInt(state.quantity, 10);
        return Number.isFinite(n) && n >= 1 && String(n) === String(state.quantity).trim() ? n : null;
    };

    function unitMinor() {
        if (!state.item) return null;
        if (state.item.quote_only) return toMinor(state.priceInput, exponent);

        return state.item.price_minor;
    }

    /** The document total the payment terms will apply to. */
    function totalMinor() {
        const current = store.commerce.totals.total_minor || 0;
        if (state.mode === 'terms' || state.itemAdded) return current;
        const unit = unitMinor();
        const count = qty();
        if (unit === null || count === null) return null;
        const removed = state.mode === 'replace' && state.replaceLine ? state.replaceLine.line_total_minor : 0;

        return current - removed + unit * count;
    }

    function totalSteps() {
        if (!needsTerms) return 1;
        const base = state.mode === 'terms' ? 1 : 2;

        return state.plan.structure === 'deposit' ? base + 1 : base;
    }

    function stepNumber() {
        return state.mode === 'terms' ? state.step - 1 : state.step;
    }

    // ---- shared pieces -------------------------------------------------------

    function radio(name, value, label, checked, onchange, hint) {
        const input = el('input', { type: 'radio', name, value, checked, onchange: () => onchange(value) });

        return el('label', { class: 'de-radio' + (checked ? ' is-checked' : '') }, [input, el('span', null, [el('strong', { text: label }), hint ? el('small', { text: hint }) : null])]);
    }

    function footer(buttons) {
        while (modal.footer.firstChild) modal.footer.removeChild(modal.footer.firstChild);
        modal.footer.appendChild(el('div', { class: 'de-wizard__error', 'data-role': 'wizard-error', role: 'alert', text: state.error, hidden: state.error === '' }));
        buttons.forEach((b) => modal.footer.appendChild(b));
    }

    function button(text, kind, onclick, disabled) {
        return el('button', { type: 'button', class: 'de-btn' + (kind ? ' de-btn--' + kind : ''), disabled: !!disabled, onclick, text });
    }

    function setError(message) {
        state.error = message || '';
        const node = modal.footer.querySelector('[data-role="wizard-error"]');
        if (node) {
            node.textContent = state.error;
            node.hidden = state.error === '';
        }
    }

    function draw() {
        stepLabel.textContent = needsTerms || state.step === 1 ? 'Step ' + stepNumber() + ' of ' + totalSteps() : '';
        while (modal.body.firstChild) modal.body.removeChild(modal.body.firstChild);
        modal.setTitle(state.step === 1 ? titleFor() : (state.step === 2 ? 'How should this be paid?' : 'Deposit and balance'));
        if (state.step === 1) drawChoose();
        else if (state.step === 2) drawPayment();
        else drawDeposit();
    }

    // ---- step 1: choose ------------------------------------------------------

    async function loadResults() {
        const token = ++loadResults.token;
        state.loading = true;
        paintResults();
        const result = await api.get(store.urls.catalog_search + '?q=' + encodeURIComponent(state.search));
        if (token !== loadResults.token) return;
        state.loading = false;
        state.results = result.ok ? result.json.items || [] : [];
        if (!result.ok) {
            setError('Your products could not be loaded.');
        }
        paintResults();
    }
    loadResults.token = 0;

    let resultsEl = null;
    let continueBtn = null;
    let quoteField = null;

    function paintResults() {
        if (!resultsEl) return;
        while (resultsEl.firstChild) resultsEl.removeChild(resultsEl.firstChild);
        if (state.loading) {
            resultsEl.appendChild(el('div', { class: 'de-muted', text: 'Loading...' }));
            return;
        }
        if (state.results.length === 0) {
            resultsEl.appendChild(el('div', { class: 'de-muted', 'data-role': 'wizard-no-results', text: state.search ? 'Nothing matches that search.' : 'You have no products or packages yet. Create one below.' }));
            return;
        }
        state.results.forEach((item) => {
            const selected = state.item && state.item.uid === item.uid;
            resultsEl.appendChild(el('button', {
                type: 'button', class: 'de-pick' + (selected ? ' is-active' : ''), 'data-item-uid': item.uid, 'aria-pressed': selected ? 'true' : 'false',
                disabled: item.addable === false,
                onclick: () => choose(item),
            }, [
                el('span', { class: 'de-pick__main' }, [el('strong', { text: item.name }), item.description ? el('small', { text: item.description.length > 90 ? item.description.slice(0, 90) + '…' : item.description }) : null]),
                el('span', { class: 'de-pick__price', text: item.addable === false ? 'Other currency' : (item.price_formatted || 'Quote only') }),
            ]));
        });
    }

    function choose(item) {
        state.item = item;
        setError('');
        paintResults();
        paintQuote();
        paintContinue();
    }

    function paintQuote() {
        if (!quoteField) return;
        while (quoteField.firstChild) quoteField.removeChild(quoteField.firstChild);
        quoteField.hidden = !(state.item && state.item.quote_only);
        if (quoteField.hidden) return;
        const input = el('input', { type: 'text', inputmode: 'decimal', class: 'de-input', value: state.priceInput, placeholder: '0.00', 'data-role': 'quote-price', 'aria-label': 'Price' });
        input.addEventListener('input', () => { state.priceInput = input.value; paintContinue(); });
        quoteField.appendChild(el('label', { class: 'de-field' }, [
            el('span', { class: 'de-field__label', text: 'Price (' + store.currency + ')' }),
            input,
            el('small', { class: 'de-field__hint', text: 'This item has no fixed price, so enter the price for this document.' }),
        ]));
    }

    function canContinue() {
        if (!state.item) return false;
        if (qty() === null) return false;
        if (state.item.quote_only) {
            const minor = toMinor(state.priceInput, exponent);
            if (minor === null || minor <= 0) return false;
        }

        return true;
    }

    function paintContinue() {
        if (!continueBtn) return;
        continueBtn.disabled = !canContinue() || state.busy;
    }

    function drawChoose() {
        const search = el('input', { type: 'search', class: 'de-input', placeholder: 'Search your products and packages', value: state.search, 'aria-label': 'Search products', 'data-role': 'wizard-search' });
        const run = debounce(() => { state.search = search.value.trim(); loadResults(); }, 250);
        search.addEventListener('input', run);

        resultsEl = el('div', { class: 'de-picklist', 'data-role': 'wizard-results' });
        quoteField = el('div', { 'data-role': 'wizard-quote' });

        const qtyInput = el('input', { type: 'number', class: 'de-input de-input--qty', min: '1', step: '1', value: state.quantity, 'aria-label': 'Quantity', 'data-role': 'wizard-quantity' });
        qtyInput.addEventListener('input', () => { state.quantity = qtyInput.value; paintContinue(); });

        modal.body.appendChild(el('div', { class: 'de-wizard__search' }, [icon('search'), search]));
        modal.body.appendChild(resultsEl);
        modal.body.appendChild(drawCreate());
        modal.body.appendChild(quoteField);
        modal.body.appendChild(el('label', { class: 'de-field de-field--inline' }, [el('span', { class: 'de-field__label', text: 'Quantity' }), qtyInput]));

        continueBtn = button(needsTerms ? 'Continue' : (state.mode === 'replace' ? 'Replace product' : 'Add product'), 'primary', onChosen, true);
        continueBtn.setAttribute('data-role', 'wizard-continue');
        footer([button('Cancel', '', () => modal.close()), continueBtn]);

        paintResults();
        paintQuote();
        paintContinue();
        if (state.results.length === 0) loadResults();
    }

    function drawCreate() {
        const holder = el('div', { class: 'de-create', 'data-role': 'wizard-create' });

        const paint = () => {
            while (holder.firstChild) holder.removeChild(holder.firstChild);
            if (!state.creating) {
                holder.appendChild(el('button', { type: 'button', class: 'de-link', 'data-role': 'wizard-create-open', onclick: () => { state.creating = true; paint(); }}, [icon('plus'), el('span', { text: 'Create custom package/product' })]));
                return;
            }
            const name = el('input', { type: 'text', class: 'de-input', maxlength: '200', placeholder: 'e.g. Wedding photography package', 'data-role': 'create-name', 'aria-label': 'Name' });
            const description = el('textarea', { class: 'de-input', rows: '2', maxlength: '2000', placeholder: 'Optional', 'data-role': 'create-description', 'aria-label': 'Description' });
            const price = el('input', { type: 'text', inputmode: 'decimal', class: 'de-input', placeholder: '0.00', 'data-role': 'create-price', 'aria-label': 'Price' });
            const error = el('div', { class: 'de-field__error', 'data-role': 'create-error', role: 'alert', hidden: true });
            const submit = el('button', { type: 'button', class: 'de-btn de-btn--primary de-btn--sm', 'data-role': 'create-submit', text: 'Create and select' });

            submit.addEventListener('click', async () => {
                error.hidden = true;
                const minor = toMinor(price.value, exponent);
                if (name.value.trim() === '') { error.textContent = 'Give it a name.'; error.hidden = false; return; }
                if (minor === null || minor <= 0) { error.textContent = 'Enter a price greater than zero, for example 250.00.'; error.hidden = false; return; }
                submit.disabled = true;
                const result = await api.post(store.urls.catalog_store, {
                    type: 'product', name: name.value.trim(), description: description.value.trim() || null, price: toDecimalString(minor, exponent),
                });
                submit.disabled = false;
                if (!result.ok) {
                    error.textContent = result.status === 403 || result.status === 404
                        ? 'You do not have permission to create products. Choose an existing one instead.'
                        : errorMessage(result, 'The product could not be created.');
                    error.hidden = false;
                    return;
                }
                const item = result.json.item;
                state.results = [item].concat(state.results.filter((r) => r.uid !== item.uid));
                state.creating = false;
                paint();
                choose(item);
            });

            holder.appendChild(el('div', { class: 'de-create__form' }, [
                el('div', { class: 'de-create__title' }, [el('strong', { text: 'New package/product' }), el('button', { type: 'button', class: 'de-link', onclick: () => { state.creating = false; paint(); }, text: 'Cancel' })]),
                el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Name' }), name]),
                el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Description (optional)' }), description]),
                el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Price' }), el('div', { class: 'de-money' }, [el('span', { class: 'de-money__cur', 'data-role': 'create-currency', title: 'Your business currency', text: store.currency }), price])]),
                error,
                submit,
            ]));
            setTimeout(() => name.focus(), 0);
        };
        paint();

        return holder;
    }

    function onChosen() {
        if (!canContinue()) return;
        setError('');
        if (needsTerms) {
            state.step = 2;
            draw();
        } else {
            finish();
        }
    }

    // ---- dates -----------------------------------------------------------------

    async function loadDates() {
        if (state.dates !== null) return;
        state.dates = [];
        const result = await api.get(store.urls.contact_dates);
        if (!result.ok) return;
        state.dates = result.json.dates || [];
        const first = state.dates[0];
        if (first) {
            if (!state.plan.full_due_date) state.plan.full_due_date = first.date;
            if (!state.plan.balance_due_date) state.plan.balance_due_date = first.date;
        }
        if (state.step === 2 || state.step === 3) draw();
    }

    function dateLabel(candidate) {
        const when = formatDate(candidate.date, store.locale);

        return candidate.source === 'appointment' ? 'From appointment ' + when : 'From ' + candidate.label + ' ' + when;
    }

    /** Date input + source label + quick picks. `key` is the plan field it fills. */
    function datePicker(key, dueKey) {
        const input = el('input', { type: 'date', class: 'de-input de-input--date', value: state.plan[key], 'data-role': 'date-' + key, 'aria-label': 'Date' });
        input.addEventListener('input', () => {
            state.plan[key] = input.value;
            if (input.value && state.plan[dueKey] !== 'date') {
                state.plan[dueKey] = 'date'; // the person chose a date: that is their choice of timing
                draw();
                return;
            }
            validate();
        });
        const dates = state.dates || [];
        const current = dates.find((d) => d.date === state.plan[key]);
        const picks = dates.filter((d) => d.date !== state.plan[key]);

        return el('div', { class: 'de-datepick' }, [
            input,
            current ? el('small', { class: 'de-datepick__source', 'data-role': 'date-source', text: dateLabel(current) }) : null,
            picks.length > 0 ? el('div', { class: 'de-datepick__quick' }, picks.map((d) => el('button', {
                type: 'button', class: 'de-chipbtn', 'data-role': 'date-quick', title: 'Use this date',
                onclick: () => { state.plan[key] = d.date; state.plan[dueKey] = 'date'; draw(); },
                text: dateLabel(d),
            }))) : null,
        ]);
    }

    // ---- step 2: how paid ------------------------------------------------------

    let doneBtn = null;

    function dueFull() {
        const wrap = el('div', { class: 'de-due', 'data-role': 'full-due' }, [
            radio('full_due', 'on_signing', 'Immediately after signing', state.plan.full_due === 'on_signing', (v) => { state.plan.full_due = v; draw(); }),
            radio('full_due', 'date', 'On a date', state.plan.full_due === 'date', (v) => { state.plan.full_due = v; draw(); }),
        ]);
        wrap.appendChild(datePicker('full_due_date', 'full_due'));

        return wrap;
    }

    function drawPayment() {
        loadDates();
        const total = totalMinor();

        modal.body.appendChild(el('div', { class: 'de-radios' }, [
            radio('structure', 'full', 'Full payment', state.plan.structure === 'full', (v) => { state.plan.structure = v; draw(); }, 'The whole amount is paid in one go.'),
            radio('structure', 'deposit', 'Deposit + remaining balance', state.plan.structure === 'deposit', (v) => { state.plan.structure = v; draw(); }, 'Collect a deposit first, then the rest.'),
        ]));

        if (state.plan.structure === 'full') {
            modal.body.appendChild(el('div', { class: 'de-summary', 'data-role': 'wizard-total' }, [el('span', { text: 'Total' }), el('strong', { text: total === null ? '' : store.money(total) })]));
            modal.body.appendChild(el('div', { class: 'de-field__label', text: 'When is it due?' }));
            modal.body.appendChild(dueFull());
        }

        const back = state.mode === 'terms' ? button('Cancel', '', () => modal.close()) : button('Back', '', () => { state.step = 1; setError(''); draw(); });
        if (state.plan.structure === 'deposit') {
            doneBtn = button('Next', 'primary', () => { state.step = 3; setError(''); draw(); });
        } else {
            doneBtn = button('Done', 'primary', finish);
        }
        doneBtn.setAttribute('data-role', 'wizard-done');
        footer([back, doneBtn]);
        validate();
    }

    // ---- step 3: deposit -------------------------------------------------------

    let balanceEl = null;
    let depositErr = null;

    function drawDeposit() {
        loadDates();
        const total = totalMinor();

        const deposit = el('input', { type: 'text', inputmode: 'decimal', class: 'de-input', value: state.plan.deposit, placeholder: '0.00', 'data-role': 'deposit-input', 'aria-label': 'Deposit amount' });
        deposit.addEventListener('input', () => { state.plan.deposit = deposit.value; validate(); });
        balanceEl = el('strong', { 'data-role': 'balance-amount' });
        depositErr = el('div', { class: 'de-field__error', 'data-role': 'deposit-error', role: 'alert', hidden: true });

        modal.body.appendChild(el('div', { class: 'de-summary', 'data-role': 'wizard-total' }, [el('span', { text: 'Product total' }), el('strong', { text: total === null ? '' : store.money(total) })]));
        modal.body.appendChild(el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Deposit amount' }), el('div', { class: 'de-money' }, [el('span', { class: 'de-money__cur', title: 'Your business currency', text: store.currency }), deposit]), depositErr]));
        modal.body.appendChild(el('div', { class: 'de-summary', 'data-role': 'wizard-balance' }, [el('span', { text: 'Remaining balance' }), balanceEl]));
        modal.body.appendChild(el('div', { class: 'de-field__label', text: 'When is the balance due?' }));
        modal.body.appendChild(el('div', { class: 'de-due', 'data-role': 'balance-due' }, [
            radio('balance_due', 'after_deposit', 'Immediately after deposit', state.plan.balance_due === 'after_deposit', (v) => { state.plan.balance_due = v; draw(); }),
            radio('balance_due', 'date', 'On a date', state.plan.balance_due === 'date', (v) => { state.plan.balance_due = v; draw(); }),
            datePicker('balance_due_date', 'balance_due'),
        ]));

        doneBtn = button('Done', 'primary', finish);
        doneBtn.setAttribute('data-role', 'wizard-done');
        footer([button('Back', '', () => { state.step = 2; setError(''); draw(); }), doneBtn]);
        validate();
        setTimeout(() => deposit.focus(), 0);
    }

    function validate() {
        let message = null;

        if (state.step === 3) {
            const total = totalMinor() || 0;
            const result = depositState(total, state.plan.deposit, exponent);
            if (balanceEl) balanceEl.textContent = result.balanceMinor !== null && result.error === null ? store.money(result.balanceMinor) : '—';
            if (depositErr) {
                const show = result.error !== null && String(state.plan.deposit).trim() !== '';
                depositErr.textContent = show ? result.error : '';
                depositErr.hidden = !show;
            }
            message = result.error;
            if (message === null && state.plan.balance_due === 'date' && !validDate(state.plan.balance_due_date)) message = 'Choose the date the balance is due.';
        } else if (state.step === 2 && state.plan.structure === 'full') {
            if (state.plan.full_due === 'date' && !validDate(state.plan.full_due_date)) message = 'Choose the date the payment is due.';
        }

        if (doneBtn && (state.plan.structure !== 'deposit' || state.step === 3)) {
            doneBtn.disabled = message !== null || state.busy;
        }
        if (doneBtn && state.step === 2 && state.plan.structure === 'deposit') {
            doneBtn.disabled = false;
        }

        return message === null;
    }

    function validDate(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
    }

    // ---- finish ----------------------------------------------------------------

    function planBody() {
        const plan = state.plan;
        if (plan.structure === 'deposit') {
            const result = depositState(totalMinor() || 0, plan.deposit, exponent);
            const body = { structure: 'deposit', deposit: toDecimalString(result.depositMinor, exponent), full_due: 'on_signing', balance_due: plan.balance_due };
            if (plan.balance_due === 'date') body.balance_due_date = plan.balance_due_date;

            return body;
        }
        const body = { structure: 'full', full_due: plan.full_due };
        if (plan.full_due === 'date') body.full_due_date = plan.full_due_date;

        return body;
    }

    async function finish() {
        if (state.busy) return;
        if (needsTerms && !validate() && !(state.step === 2 && state.plan.structure === 'deposit')) return;
        state.busy = true;
        setError('');
        if (doneBtn) doneBtn.disabled = true;
        if (continueBtn) continueBtn.disabled = true;

        try {
            if (state.item && !state.itemAdded) {
                const body = { catalog_item_uid: state.item.uid, quantity: qty() };
                if (state.item.quote_only) {
                    body.price = toDecimalString(toMinor(state.priceInput, exponent), exponent);
                }
                const added = await api.mutate('POST', store.urls.lines_catalog, body);
                if (!added.ok) {
                    if (added.status !== 409) setError(errorMessage(added, 'The product could not be added.'));
                    else modal.close();
                    return;
                }
                state.itemAdded = true;
                store.adoptCommerce(added.json);

                if (state.mode === 'replace' && state.replaceLine) {
                    const removed = await api.mutate('DELETE', store.urls.line_template.replace('__LINE__', state.replaceLine.uid), {});
                    if (removed.ok) store.adoptCommerce(removed.json);
                    else if (removed.status !== 409) setError(errorMessage(removed, 'The old line could not be removed.'));
                }
            }

            if (needsTerms) {
                const saved = await api.mutate('PUT', store.urls.plan, planBody());
                if (!saved.ok) {
                    if (saved.status !== 409) setError(errorMessage(saved, 'The payment terms could not be saved.'));
                    else modal.close();
                    return;
                }
                store.adoptCommerce(saved.json);
            }

            modal.close();
            if (ctx.notify) ctx.notify(state.mode === 'terms' ? 'Payment terms updated.' : 'Product added.', 'success');
        } finally {
            state.busy = false;
            if (doneBtn) doneBtn.disabled = false;
            paintContinue();
        }
    }

    draw();

    return modal;
}
