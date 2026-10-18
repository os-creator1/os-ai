// Contract 17B — every request the editor makes. All document MUTATIONS go
// through one promise queue so two saves / line operations in the same tab can
// never race, and each one reads store.lock at the moment it actually runs
// (the previous response has already updated it). Reads (search, dates) bypass
// the queue.
//
// Answers follow the controller's contract:
//   200 {status:'ok', lock_version, ...}   409 {status:'conflict', lock_version}
//   422 {status:'invalid', message, errors}   404 {status:'error'}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

export function createApi(store) {
    let chain = Promise.resolve();
    let pending = 0;

    async function send(method, url, body) {
        const options = {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        };
        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        let response;
        try {
            response = await fetch(url, options);
        } catch (error) {
            return { ok: false, status: 0, network: true, json: { status: 'error', message: 'You appear to be offline.' } };
        }

        let json = null;
        try {
            json = await response.json();
        } catch (error) {
            json = { status: 'error', message: response.status === 419 ? 'Your session expired. Reload the page to continue.' : 'Something went wrong.' };
        }

        return { ok: response.ok && json && json.status === 'ok', status: response.status, json: json || {} };
    }

    const api = {
        /** A read: not queued, no lock. */
        get(url) {
            return send('GET', url);
        },

        /** A write that is not a document mutation (e.g. create a catalog item). */
        post(url, body) {
            return send('POST', url, body);
        },

        /**
         * A document mutation. `build` may be a body object or a function
         * returning one; it runs when its turn comes. `expected_lock_version`
         * is added from the store. On 200 the new lock version is adopted; on
         * 409 the whole editor flips to the conflict state (never retried, never
         * overwritten).
         */
        mutate(method, url, build) {
            pending += 1;
            const run = async () => {
                if (store.save.state === 'conflict') {
                    return { ok: false, status: 409, json: { status: 'conflict' }, skipped: true };
                }
                const body = typeof build === 'function' ? build() : build;
                if (body === null) {
                    return { ok: true, status: 200, json: {}, skipped: true };
                }
                const result = await send(method, url, { ...(body || {}), expected_lock_version: store.lock });
                if (result.status === 409) {
                    store.setSave('conflict', 'This document was changed in another tab.');
                    store.emit('conflict');
                } else if (result.ok && result.json.lock_version !== undefined && result.json.lock_version !== null) {
                    store.lock = result.json.lock_version;
                }

                return result;
            };

            const task = chain.then(run, run);
            chain = task.then(() => { pending -= 1; }, () => { pending -= 1; });

            return task;
        },

        /** Resolves once every queued mutation has finished. */
        idle() {
            return chain;
        },

        busy() {
            return pending > 0;
        },
    };

    return api;
}

/** The first human-readable message in an error answer. */
export function errorMessage(result, fallback) {
    const json = (result && result.json) || {};
    if (json.errors && typeof json.errors === 'object') {
        const keys = Object.keys(json.errors);
        if (keys.length > 0) {
            const first = json.errors[keys[0]];
            return Array.isArray(first) ? first[0] : String(first);
        }
    }

    return json.message || fallback || 'Something went wrong.';
}
