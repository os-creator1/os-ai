// Contract 17B — the left block toolbox. The buttons are server-rendered (so
// they exist without the script); this wires them: click adds after the
// selected block (or at the end), drag drops where the indicator shows,
// Enter/Space work because they are real buttons. On narrow screens the
// toolbox is a drawer opened from the header.

import { DRAG_TYPES } from './canvas';

export function createToolbox(ctx) {
    const { store, actions, root } = ctx;
    const box = root.querySelector('[data-role="toolbox"]');
    const scrim = root.querySelector('[data-role="toolbox-scrim"]');
    const toggle = root.querySelector('[data-role="toolbox-toggle"]');
    const close = root.querySelector('[data-role="toolbox-close"]');

    if (!box) {
        return { refresh() {} };
    }

    const tools = Array.prototype.slice.call(box.querySelectorAll('[data-tool]'));

    function setDrawer(open) {
        box.classList.toggle('is-open', open);
        if (scrim) {
            scrim.hidden = !open;
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    tools.forEach((button) => {
        const id = button.getAttribute('data-tool');

        button.addEventListener('click', () => {
            if (!store.editable) {
                return;
            }
            actions.addTool(id, null);
            setDrawer(false);
        });

        button.addEventListener('dragstart', (event) => {
            if (!store.editable || button.disabled) {
                event.preventDefault();
                return;
            }
            ctx.drag = { kind: 'tool', id };
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData(DRAG_TYPES.TOOL, id);
            event.dataTransfer.setData('text/plain', id);
        });

        button.addEventListener('dragend', () => {
            ctx.drag = null;
            ctx.canvas.hideIndicator();
        });
    });

    if (toggle) {
        toggle.addEventListener('click', () => setDrawer(!box.classList.contains('is-open')));
    }
    if (close) {
        close.addEventListener('click', () => setDrawer(false));
    }
    if (scrim) {
        scrim.addEventListener('click', () => setDrawer(false));
    }

    /** The signature can be placed once; everything else stays available. */
    function refresh() {
        tools.forEach((button) => {
            const id = button.getAttribute('data-tool');
            const taken = id === 'signature' && store.hasType('signature');
            const full = store.blocks.length >= (store.toolbox.limits ? store.toolbox.limits.max_blocks : 200) && button.getAttribute('data-block-type') !== 'product_list';
            button.disabled = !store.editable || taken || full;
            button.title = taken ? 'A document has one signature' : button.querySelector('span').textContent;
            button.classList.toggle('is-used', taken);
        });
    }

    refresh();

    return { refresh, setDrawer };
}
