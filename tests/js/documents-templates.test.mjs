// Contract 17B stage 5 — template mode of the ONE editor: the store, the merge
// samples and the autosave payload. No DOM needed.
//
//   node --test tests/js/documents-templates.test.mjs

import test from 'node:test';
import assert from 'node:assert/strict';
import { createStore } from '../../resources/js/documents/editor/state.js';
import { createAutosave } from '../../resources/js/documents/editor/autosave.js';

const templateBoot = () => ({
    mode: 'template',
    template: { uid: 't-1', name: 'Wedding layout', type: 'contract', status: 'active' },
    document: { uid: 't-1', title: 'Wedding layout', status: 'template', currency_code: 'USD' },
    editable: true,
    lock_version: 4,
    blocks: [{ id: 'a', type: 'text', data: { runs: [{ merge: 'contact.first_name' }] } }],
    merge_samples: { 'contact.first_name': 'Alex', 'business.name': 'Your Business' },
    toolbox: { merge_fields: [{ token: 'contact.first_name', label: 'Contact first name' }] },
    urls: { blocks: '/templates/t-1/blocks' },
});

test('template mode is flagged on the store and carries the template type', () => {
    const store = createStore(templateBoot());
    assert.equal(store.mode, 'template');
    assert.equal(store.isTemplate, true);
    assert.equal(store.templateType, 'contract');
    assert.equal(store.title, 'Wedding layout');
    assert.equal(store.lock, 4);
    assert.deepEqual(store.commerce.lines, []);
});

test('a document boot stays in document mode with no template type', () => {
    const store = createStore({ document: { uid: 'd-1', title: 'Proposal', status: 'draft' }, editable: true, lock_version: 2 });
    assert.equal(store.mode, 'document');
    assert.equal(store.isTemplate, false);
    assert.equal(store.templateType, null);
});

test('merge chips resolve to sample data in template mode and to the contact in document mode', () => {
    const template = createStore(templateBoot());
    assert.equal(template.mergePreview('contact.first_name'), 'Alex');
    assert.equal(template.mergePreview('business.name'), 'Your Business');
    assert.equal(template.mergePreview('contact.email'), '', 'no sample means the chip shows its label');

    const doc = createStore({ document: { uid: 'd', title: 'T', status: 'draft' }, contact: { name: 'Pat Rivera', email: 'pat@example.com' }, business: { name: 'Harbor' } });
    assert.equal(doc.mergePreview('contact.first_name'), 'Pat');
    assert.equal(doc.mergePreview('business.name'), 'Harbor');
});

function fakeApi() {
    const calls = [];
    return {
        calls,
        async mutate(method, url, build) {
            const body = typeof build === 'function' ? build() : build;
            calls.push({ method, url, body });
            return body === null ? { ok: true, status: 200, json: {}, skipped: true } : { ok: true, status: 200, json: { status: 'ok', lock_version: 5 } };
        },
        idle: async () => {},
        busy: () => false,
    };
}

test('template autosave sends name and template_type to the template blocks endpoint, never a document title', async () => {
    const store = createStore(templateBoot());
    const api = fakeApi();
    const autosave = createAutosave(store, api);
    store.title = 'Renamed layout';
    store.templateType = 'proposal';
    autosave.markDirty();
    autosave.cancel();
    await autosave.flush();

    assert.equal(api.calls.length, 1);
    assert.equal(api.calls[0].method, 'PUT');
    assert.equal(api.calls[0].url, '/templates/t-1/blocks');
    assert.deepEqual(Object.keys(api.calls[0].body).sort(), ['blocks', 'name', 'template_type']);
    assert.equal(api.calls[0].body.name, 'Renamed layout');
    assert.equal(api.calls[0].body.template_type, 'proposal');
    assert.equal(api.calls[0].body.blocks.length, 1);
});

test('document autosave still sends blocks and title only', async () => {
    const store = createStore({ document: { uid: 'd-1', title: 'Proposal', status: 'draft' }, editable: true, lock_version: 2, blocks: [], urls: { blocks: '/docs/d-1/editor/blocks' } });
    const api = fakeApi();
    const autosave = createAutosave(store, api);
    autosave.markDirty();
    autosave.cancel();
    await autosave.flush();

    assert.deepEqual(Object.keys(api.calls[0].body).sort(), ['blocks', 'title']);
    assert.equal(api.calls[0].url, '/docs/d-1/editor/blocks');
});
