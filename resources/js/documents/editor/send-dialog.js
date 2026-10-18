// Contract 17B §7 — the Send dialog. One Draft -> Sent transition delivered by
// Email and / or SMS (at least one). It first shows a friendly checklist of
// what a sendable proposal needs, prefilled recipient details (typed only when
// the document has none), an optional text-message wording, then reports each
// channel's outcome. Any pending autosave is flushed before sending.

import { el } from './dom';
import { openModal } from './modal';
import { errorMessage } from './api';

const SMS_REASONS = {
    contact_missing: 'The contact for this document could not be found.',
    contact_unsubscribed: 'This contact has not agreed to receive text messages.',
    phone_invalid: 'The phone number on file cannot be used for text messages.',
    no_business_sending_path: 'Text messaging is not set up for this business yet.',
    sender_rejected: 'The text messaging sender was rejected.',
    send_failed: 'Text messages cannot be sent right now.',
};

export function checklist(store) {
    const lines = (store.commerce.lines || []).length;
    const schedule = (store.commerce.schedule || []).length;
    const items = [];

    if (store.requiresSignature) {
        items.push({ id: 'signature', ok: store.hasType('signature'), text: 'A signature block', fix: 'Add a Signature block from the left.' });
    }
    items.push({ id: 'lines', ok: lines > 0, text: 'At least one product', fix: 'Use Add product to choose what you are selling.' });
    items.push({ id: 'schedule', ok: schedule > 0 && !store.commerce.plan_invalid, text: 'Payment terms', fix: store.commerce.plan_invalid ? 'Update the payment terms so they fit the total.' : 'Choose how this should be paid.' });

    return items;
}

export function openSendDialog(ctx) {
    const { store, api, autosave } = ctx;
    const delivery = store.delivery || {};
    const modal = openModal(ctx.modalRoot, { title: 'Send for signature', size: 'md', className: 'de-send' });

    const smsReason = delivery.sms_unavailable_reason && delivery.sms_unavailable_reason !== 'phone_missing' ? delivery.sms_unavailable_reason : null;
    const smsMax = delivery.sms_max_message || 320;
    const state = { email: true, sms: false, busy: false, sent: false };

    const emailBox = el('input', { type: 'checkbox', checked: true, 'data-role': 'send-email-channel' });
    const smsBox = el('input', { type: 'checkbox', disabled: smsReason !== null, 'data-role': 'send-sms-channel' });
    const emailInput = el('input', { type: 'email', class: 'de-input', value: delivery.email || '', readonly: !!delivery.email, placeholder: 'name@example.com', 'data-role': 'send-email', 'aria-label': 'Recipient email' });
    const phoneInput = el('input', { type: 'tel', class: 'de-input', value: delivery.phone || '', readonly: !!delivery.phone, placeholder: '+1 555 010 0100', 'data-role': 'send-phone', 'aria-label': 'Recipient phone' });
    const message = el('textarea', { class: 'de-input', rows: '3', maxlength: String(smsMax), 'data-role': 'send-message', 'aria-label': 'Text message' });
    message.value = delivery.sms_default_message || '';
    const counter = el('small', { class: 'de-field__hint' });
    const checklistEl = el('ul', { class: 'de-check-list', 'data-role': 'send-checklist' });
    const error = el('div', { class: 'de-wizard__error', role: 'alert', 'data-role': 'send-error', hidden: true });
    const sendBtn = el('button', { type: 'button', class: 'de-btn de-btn--primary', 'data-role': 'send-submit', text: 'Send' });
    const emailRow = el('div', { class: 'de-send__detail' });
    const smsRow = el('div', { class: 'de-send__detail', hidden: true });

    emailRow.appendChild(el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Email address' }), emailInput, delivery.email ? el('small', { class: 'de-field__hint', text: 'From this contact.' }) : el('small', { class: 'de-field__hint', text: 'This contact has no email yet. Enter one to send to.' })]));
    smsRow.appendChild(el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Phone number' }), phoneInput, delivery.phone ? el('small', { class: 'de-field__hint', text: 'From this contact.' }) : el('small', { class: 'de-field__hint', text: 'This contact has no phone number yet. Enter one to send to.' })]));
    smsRow.appendChild(el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: 'Message' }), message, counter, el('small', { class: 'de-field__hint', text: 'A link to the document is added to the end automatically.' })]));

    modal.body.appendChild(el('div', { class: 'de-field__label', text: 'Before you send' }));
    modal.body.appendChild(checklistEl);
    modal.body.appendChild(el('div', { class: 'de-field__label', text: 'Send by' }));
    modal.body.appendChild(el('label', { class: 'de-check de-check--box' }, [emailBox, el('span', { text: 'Email' })]));
    modal.body.appendChild(emailRow);
    modal.body.appendChild(el('label', { class: 'de-check de-check--box' + (smsReason ? ' is-disabled' : '') }, [smsBox, el('span', { text: 'Text message' })]));
    if (smsReason) {
        modal.body.appendChild(el('small', { class: 'de-field__hint', 'data-role': 'sms-unavailable', text: SMS_REASONS[smsReason] || SMS_REASONS.send_failed }));
    }
    modal.body.appendChild(smsRow);

    modal.footer.appendChild(error);
    modal.footer.appendChild(el('button', { type: 'button', class: 'de-btn', onclick: () => modal.close(), text: 'Cancel' }));
    modal.footer.appendChild(sendBtn);

    const emailLooksValid = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());

    function problems() {
        const found = checklist(store).filter((item) => !item.ok);
        if (!emailBox.checked && !smsBox.checked) {
            found.push({ id: 'channel', fix: 'Choose email, text message or both.' });
        }
        if (emailBox.checked && !emailLooksValid(emailInput.value)) {
            found.push({ id: 'email', fix: 'Enter a valid email address.' });
        }
        if (smsBox.checked && phoneInput.value.trim().length < 5) {
            found.push({ id: 'phone', fix: 'Enter a phone number for the text message.' });
        }

        return found;
    }

    function paint() {
        while (checklistEl.firstChild) checklistEl.removeChild(checklistEl.firstChild);
        checklist(store).forEach((item) => {
            checklistEl.appendChild(el('li', { class: item.ok ? 'is-ok' : 'is-missing', 'data-check': item.id, 'data-ok': item.ok ? '1' : '0' }, [
                el('span', { class: 'de-check-list__mark', 'aria-hidden': 'true', text: item.ok ? '✓' : '!' }),
                el('span', null, [item.text, item.ok ? null : el('small', { text: ' ' + item.fix })]),
            ]));
        });
        smsRow.hidden = !smsBox.checked;
        counter.textContent = message.value.length + ' / ' + smsMax;
        sendBtn.disabled = state.busy || problems().length > 0;
    }

    [emailBox, smsBox, emailInput, phoneInput, message].forEach((node) => node.addEventListener('input', paint));
    [emailBox, smsBox].forEach((node) => node.addEventListener('change', paint));
    store.subscribe(() => { if (!state.sent) paint(); });

    const show = (text) => {
        error.textContent = text || '';
        error.hidden = !text;
    };

    function showResult(json) {
        while (modal.body.firstChild) modal.body.removeChild(modal.body.firstChild);
        while (modal.footer.firstChild) modal.footer.removeChild(modal.footer.firstChild);
        modal.setTitle('Sent');

        const results = json.delivery || {};
        const list = el('ul', { class: 'de-result', 'data-role': 'send-result' });
        [['email', 'Email'], ['sms', 'Text message']].forEach(([key, label]) => {
            const result = results[key];
            if (!result) return;
            const ok = result.status === 'queued';
            list.appendChild(el('li', { class: ok ? 'is-ok' : 'is-failed', 'data-channel': key, 'data-status': result.status }, [
                el('strong', { text: label }),
                el('span', { text: ok ? ' is on its way.' : ' could not be sent. ' + (result.message || 'Try again from the document page.') }),
            ]));
        });
        modal.body.appendChild(el('p', { text: 'The document is now with your client and can no longer be edited here.' }));
        modal.body.appendChild(list);
        modal.footer.appendChild(el('button', { type: 'button', class: 'de-btn de-btn--primary', 'data-role': 'send-done', text: 'Done', onclick: () => window.location.reload() }));
    }

    sendBtn.addEventListener('click', async () => {
        if (problems().length > 0 || state.busy) return;
        state.busy = true;
        show('');
        paint();

        try {
            const saved = await autosave.flush();
            if (!saved) {
                show(store.save.state === 'conflict' ? 'This document was changed in another tab. Reload before sending.' : 'Your latest changes could not be saved, so the document was not sent.');
                return;
            }

            const channels = [];
            if (emailBox.checked) channels.push('email');
            if (smsBox.checked) channels.push('sms');
            const body = { channels };
            if (emailBox.checked && !delivery.email) body.recipient_email = emailInput.value.trim();
            if (smsBox.checked && !delivery.phone) body.recipient_phone = phoneInput.value.trim();
            if (smsBox.checked && message.value.trim() !== '' && message.value !== (delivery.sms_default_message || '')) body.message = message.value.trim();

            const result = await api.mutate('POST', store.urls.send, body);
            if (result.ok) {
                state.sent = true;
                store.editable = false;
                store.dirty = false;
                showResult(result.json);
                return;
            }
            if (result.status === 409) {
                modal.close();
                return;
            }
            show(errorMessage(result, 'The document could not be sent.'));
        } finally {
            state.busy = false;
            if (!state.sent) paint();
        }
    });

    paint();

    return modal;
}
