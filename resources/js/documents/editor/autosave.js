// Contract 17B — debounced autosave of blocks + title.
//
// markDirty() schedules one save ~800ms after the LAST edit. The save is queued
// behind any in-flight request, serialises the CURRENT blocks when its turn
// comes (so it is never stale) and uses the latest lock version. A 409 stops
// everything (api.js sets the conflict state); a 422 shows the field message and
// waits for the next edit; a network failure retries on its own.

import { errorMessage } from './api';
import { savePayload } from './blocks';

const DELAY = 800;
const RETRY = 5000;

export function createAutosave(store, api) {
    let timer = null;
    let retryTimer = null;

    function schedule(wait) {
        clearTimeout(timer);
        timer = setTimeout(save, wait);
    }

    async function save() {
        clearTimeout(timer);
        clearTimeout(retryTimer);

        if (!store.editable || store.save.state === 'conflict' || !store.dirty) {
            return;
        }

        const result = await api.mutate('PUT', store.urls.blocks, () => {
            if (!store.dirty) {
                return null; // a flush already saved this
            }
            store.dirty = false;
            store.setSave('saving');

            return { blocks: savePayload(store.blocks), title: store.title };
        });

        if (result.skipped) {
            if (store.save.state === 'saving') {
                store.setSave(store.dirty ? 'dirty' : 'saved');
            }
            return;
        }

        if (result.ok) {
            // An edit made while this request was in flight is still pending.
            store.setSave(store.dirty ? 'dirty' : 'saved');
            if (store.dirty) {
                schedule(DELAY);
            }
            return;
        }

        if (result.status === 409) {
            return;
        }

        store.dirty = true;
        if (result.network) {
            store.setSave('error', 'Could not save. Retrying...');
            retryTimer = setTimeout(save, RETRY);
        } else {
            store.setSave('error', errorMessage(result, 'This change could not be saved.'));
        }
    }

    return {
        markDirty() {
            if (!store.editable || store.save.state === 'conflict') {
                return;
            }
            store.dirty = true;
            if (store.save.state !== 'saving') {
                store.setSave('dirty');
            }
            schedule(DELAY);
        },

        /** Save now (Save button, Preview, Send); resolves when nothing is pending. */
        async flush() {
            if (store.dirty && store.save.state !== 'conflict') {
                await save();
            }
            await api.idle();
            // A save that was waiting behind another may have left more to do.
            if (store.dirty && store.save.state !== 'conflict' && store.save.state !== 'error') {
                await save();
                await api.idle();
            }

            return store.save.state === 'saved';
        },

        cancel() {
            clearTimeout(timer);
            clearTimeout(retryTimer);
        },
    };
}
