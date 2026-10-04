// Contract 17B — the contextual right panel. It exists only while the selected
// block has something worth setting beyond its text: image (catalog picture,
// alt text, width), spacer height, signature label, business details,
// heading level / alignment, and the pricing block's two display switches.
// Text blocks are formatted from the mini toolbar instead, so selecting one
// leaves the panel closed.

import { el, icon } from './dom';
import { TYPE_LABELS } from './blocks';

const WITH_PANEL = ['heading', 'image', 'spacer', 'signature', 'business_details', 'product_list'];

export function createInspector(ctx) {
    const { store, actions, root } = ctx;
    const panel = root.querySelector('[data-role="inspector"]');

    if (!panel) {
        return { refresh() {} };
    }

    function field(label, control, hint) {
        return el('label', { class: 'de-field' }, [el('span', { class: 'de-field__label', text: label }), control, hint ? el('small', { class: 'de-field__hint', text: hint }) : null]);
    }

    function segmented(options, current, onPick, label) {
        return el('div', { class: 'de-seg', role: 'group', 'aria-label': label }, options.map((option) => el('button', {
            type: 'button',
            class: 'de-seg__btn' + (option.value === current ? ' is-active' : ''),
            'aria-pressed': option.value === current ? 'true' : 'false',
            onclick: () => onPick(option.value),
        }, [option.icon ? icon(option.icon) : null, option.text ? el('span', { text: option.text }) : null])));
    }

    function body(block) {
        const data = block.data || {};
        const readOnly = !store.editable;
        const set = (patch, rerender) => actions.updateBlock(block.id, patch, { rerender: rerender !== false });

        switch (block.type) {
            case 'heading':
                return [
                    field('Heading level', segmented([{ value: 1, text: 'H1' }, { value: 2, text: 'H2' }, { value: 3, text: 'H3' }], data.level || 2, (value) => { set({ level: value }); refreshSoon(); }, 'Heading level')),
                    field('Alignment', segmented([{ value: 'left', icon: 'align-left' }, { value: 'center', icon: 'align-center' }, { value: 'right', icon: 'align-right' }], data.align || 'left', (value) => { set({ align: value }); refreshSoon(); }, 'Alignment')),
                ];
            case 'spacer': {
                const number = el('input', { type: 'number', class: 'de-input', min: '8', max: '120', step: '1', value: String(data.height || 24), disabled: readOnly });
                const range = el('input', { type: 'range', min: '8', max: '120', step: '4', value: String(data.height || 24), disabled: readOnly, 'aria-label': 'Spacer height' });
                const apply = (raw, source) => {
                    const value = Math.max(8, Math.min(120, parseInt(raw, 10) || 24));
                    if (source !== number) number.value = String(value);
                    if (source !== range) range.value = String(value);
                    set({ height: value });
                };
                number.addEventListener('input', () => apply(number.value, number));
                range.addEventListener('input', () => apply(range.value, range));

                return [field('Height (pixels)', el('div', { class: 'de-row' }, [range, number]))];
            }
            case 'signature': {
                const input = el('input', { type: 'text', class: 'de-input', maxlength: '100', value: data.label || 'Signature', disabled: readOnly });
                input.addEventListener('input', () => set({ label: input.value.trim() === '' ? 'Signature' : input.value }));

                return [field('Label', input, 'Shown above the signer’s typed name.')];
            }
            case 'business_details': {
                const show = data.show || [];

                return [el('fieldset', { class: 'de-field' }, [
                    el('legend', { class: 'de-field__label', text: 'Show' }),
                    ...[['name', 'Business name'], ['phone', 'Phone'], ['email', 'Email'], ['website', 'Website']].map(([key, text]) => {
                        const box = el('input', { type: 'checkbox', checked: show.indexOf(key) !== -1, disabled: readOnly });
                        box.addEventListener('change', () => {
                            const next = ['name', 'phone', 'email', 'website'].filter((candidate) => (candidate === key ? box.checked : show.indexOf(candidate) !== -1));
                            set({ show: next });
                            refreshSoon();
                        });

                        return el('label', { class: 'de-check' }, [box, el('span', { text })]);
                    }),
                ])];
            }
            case 'product_list': {
                const toggle = (key, text) => {
                    const box = el('input', { type: 'checkbox', checked: data[key] !== false, disabled: readOnly });
                    box.addEventListener('change', () => set({ [key]: box.checked }));

                    return el('label', { class: 'de-check' }, [box, el('span', { text })]);
                };

                return [el('fieldset', { class: 'de-field' }, [
                    el('legend', { class: 'de-field__label', text: 'Show to the recipient' }),
                    toggle('show_description', 'Product descriptions'),
                    toggle('show_quantity', 'Quantities'),
                ])];
            }
            case 'image':
                return imagePanel(block, set, readOnly);
            default:
                return [];
        }
    }

    function imagePanel(block, set, readOnly) {
        const data = block.data || {};
        const children = [];

        if (store.images.length === 0) {
            children.push(el('p', { class: 'de-muted', text: 'You have no pictures yet. Add one to a package or product in your catalog, then reload to choose it here.' }));
        } else {
            children.push(el('div', { class: 'de-field' }, [
                el('span', { class: 'de-field__label', text: 'Picture' }),
                el('div', { class: 'de-images', 'data-role': 'image-picker' }, store.images.map((image) => el('button', {
                    type: 'button',
                    class: 'de-images__item' + (image.uid === data.catalog_image_uid ? ' is-active' : ''),
                    title: image.name || 'Picture',
                    'aria-label': (image.name || 'Picture') + (image.uid === data.catalog_image_uid ? ' (selected)' : ''),
                    'aria-pressed': image.uid === data.catalog_image_uid ? 'true' : 'false',
                    disabled: readOnly,
                    'data-image-uid': image.uid,
                    onclick: () => {
                        set({ catalog_image_uid: image.uid, alt: data.alt || image.alt || image.name || '' });
                        refreshSoon();
                    },
                }, [el('img', { src: image.url, alt: '' })]))),
            ]));
        }

        const alt = el('input', { type: 'text', class: 'de-input', maxlength: '200', value: data.alt || '', placeholder: 'Describe the picture', disabled: readOnly });
        alt.addEventListener('input', () => set({ alt: alt.value }, false));
        children.push(field('Alt text', alt, 'Read aloud by screen readers.'));

        const width = el('input', { type: 'range', min: '10', max: '100', step: '5', value: String(data.width_pct || 100), disabled: readOnly });
        const out = el('output', { text: (data.width_pct || 100) + '%' });
        width.addEventListener('input', () => {
            out.textContent = width.value + '%';
            set({ width_pct: parseInt(width.value, 10) });
        });
        children.push(field('Width', el('div', { class: 'de-row' }, [width, out])));

        return children;
    }

    let soon = null;
    function refreshSoon() {
        clearTimeout(soon);
        soon = setTimeout(refresh, 0);
    }

    function refresh() {
        const block = store.selectedId ? store.block(store.selectedId) : null;
        const open = !!block && WITH_PANEL.indexOf(block.type) !== -1;

        panel.hidden = !open;
        root.classList.toggle('has-inspector', open);
        while (panel.firstChild) {
            panel.removeChild(panel.firstChild);
        }
        if (!open) {
            return;
        }

        panel.appendChild(el('div', { class: 'de-inspector__head' }, [
            el('strong', { text: TYPE_LABELS[block.type] || block.type }),
            el('button', { type: 'button', class: 'de-iconbtn de-only-narrow', 'aria-label': 'Close settings', onclick: () => ctx.canvas.deselect() }, [icon('x')]),
        ]));
        body(block).forEach((node) => panel.appendChild(node));
    }

    store.subscribe((topic) => {
        if (topic === 'selection') {
            refresh();
        }
    });

    return { refresh };
}
