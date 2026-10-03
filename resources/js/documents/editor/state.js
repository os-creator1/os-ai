// Contract 17B — the editor's single store. Plain object + subscribers; no
// framework. The lock version lives here and is the ONLY source every request
// reads (api.js), so two operations in one tab can never send a stale one.

import { currencyExponent, formatMinor } from './money';

export function createStore(boot) {
    const listeners = [];
    const document_ = boot.document || {};
    const currency = boot.currency_code || document_.currency_code || 'USD';
    const locale = (typeof navigator !== 'undefined' && navigator.language) || 'en';

    const store = {
        boot,
        urls: boot.urls || {},
        toolbox: boot.toolbox || {},
        images: boot.images || [],
        contact: boot.contact || { name: '', email: '' },
        business: boot.business || { name: '' },
        delivery: boot.delivery || {},
        docUid: document_.uid,
        status: document_.status,
        requiresSignature: !!document_.requires_signature,
        editable: !!boot.editable,
        title: document_.title || '',
        blocks: Array.isArray(boot.blocks) ? boot.blocks.map((b) => JSON.parse(JSON.stringify(b))) : [],
        lock: boot.lock_version === undefined ? null : boot.lock_version,
        commerce: {
            lines: boot.lines || [],
            totals: boot.totals || {},
            schedule: boot.schedule || [],
            plan: boot.plan || null,
            plan_invalid: !!boot.plan_invalid,
            plan_error: boot.plan_error || null,
        },
        currency,
        locale,
        exponent: currencyExponent(currency, locale),
        selectedId: null,
        // saved | dirty | saving | error | conflict
        save: { state: 'saved', message: '' },
        dirty: false,

        money(minor) {
            return formatMinor(minor, currency, locale);
        },

        subscribe(fn) {
            listeners.push(fn);
        },

        emit(topic) {
            listeners.forEach((fn) => fn(topic, store));
        },

        setSave(state, message) {
            store.save = { state, message: message || '' };
            store.emit('save');
        },

        /** Adopt the commerce payload every line / plan endpoint answers with. */
        adoptCommerce(payload) {
            if (!payload) {
                return;
            }
            ['lines', 'totals', 'schedule', 'plan', 'plan_invalid', 'plan_error'].forEach((key) => {
                if (payload[key] !== undefined) {
                    store.commerce[key] = payload[key];
                }
            });
            if (payload.lock_version !== undefined && payload.lock_version !== null) {
                store.lock = payload.lock_version;
            }
            store.emit('commerce');
        },

        block(id) {
            return store.blocks.find((b) => b.id === id) || null;
        },

        indexOf(id) {
            return store.blocks.findIndex((b) => b.id === id);
        },

        hasType(type) {
            return store.blocks.some((b) => b.type === type);
        },

        /** Merge-chip text: the live preview value when we know it, else the field's label. */
        mergeLabel(token) {
            const found = (store.toolbox.merge_fields || []).find((f) => f.token === token);
            return found ? found.label : token;
        },

        mergePreview(token) {
            const name = (store.contact.name || '').trim();
            switch (token) {
                case 'contact.full_name': return name;
                case 'contact.first_name': return name.split(/\s+/)[0] || '';
                case 'contact.email': return store.contact.email || '';
                case 'business.name': return store.business.name || '';
                case 'document.title': return store.title || '';
                default: return '';
            }
        },
    };

    return store;
}
