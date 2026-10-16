/*
 * Home — Ask MotionGrove conversation client.
 *
 * Everything shown is built with textContent / DOM nodes (never HTML strings), so nothing a model, a record or a customer
 * wrote can become markup. Links are accepted only when they are same-site paths or same-origin URLs.
 *
 * Honest by construction: the only "progress" shown is a request that is really in flight; an action is reported
 * done only from the server's own answer for that proposal; nothing is retried automatically except by the customer
 * pressing a button, and a resent question reuses its client id so the server answers it once.
 */
(function () {
    var section = document.querySelector('[data-role="home-ask"]');
    if (!section || section.dataset.assistantWired === '1') { return; }
    section.dataset.assistantWired = '1';

    var cfg = {
        show: section.getAttribute('data-assistant-show'),
        message: section.getAttribute('data-assistant-message'),
        approve: section.getAttribute('data-assistant-approve'),
        decline: section.getAttribute('data-assistant-decline'),
        remove: section.getAttribute('data-assistant-delete')
    };
    var deleteBtn = section.querySelector('[data-role="ask-delete"]');
    var form = section.querySelector('[data-role="ask-form"]');
    var input = section.querySelector('[data-role="ask-input"]');
    var submitBtn = section.querySelector('[data-role="ask-submit"]');
    var panel = section.querySelector('[data-role="ask-conversation"]');
    var thread = section.querySelector('[data-role="ask-thread"]');
    var banner = section.querySelector('[data-role="ask-banner"]');
    var working = section.querySelector('[data-role="ask-working"]');
    var resumeBtn = section.querySelector('[data-role="ask-resume"]');
    var minimizeBtn = section.querySelector('[data-role="ask-minimize"]');
    var newBtn = section.querySelector('[data-role="ask-new"]');
    if (!cfg.message || !form || !input || !panel || !thread) { return; }
    input.setAttribute('data-placeholder', input.getAttribute('placeholder') || '');

    var state = { uid: null, startNew: false, busy: false, failed: {}, loaded: false, firstUse: false, notice: null, stick: true };
    var narrow = window.matchMedia ? window.matchMedia('(max-width: 767.98px)') : { matches: false };

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) { return meta.content; }
        var el = form.querySelector('input[name="_token"]');
        return el ? el.value : '';
    }

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function safeUrl(u) {
        if (typeof u !== 'string' || u === '') { return null; }
        if (u.charAt(0) === '/' && u.charAt(1) !== '/') { return u; }
        return u.indexOf(window.location.origin + '/') === 0 ? u : null;
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function request(url, opts) {
        opts = opts || {};
        var headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' };
        if (opts.body) { headers['Content-Type'] = 'application/json'; }
        return fetch(url, { method: opts.method || 'GET', credentials: 'same-origin', headers: headers, body: opts.body ? JSON.stringify(opts.body) : undefined })
            .then(function (r) {
                return r.json().catch(function () { return null; }).then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
            });
    }

    // ── open / close ───────────────────────────────────────────────────
    // The Ask card GROWS into the conversation: the same element, so a View Transition morphs it from its Home position to the
    // full conversation (and back). Without that API, or with reduced motion, the state simply switches (CSS fades the content).
    // Opening, closing, scrolling and the history entry never call the assistant; only an intentional send does.
    var root = section.closest('[data-role="command-center"]') || document.body;
    var reduceMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
    var opener = null;
    var savedScroll = 0;

    function isOpen() { return !panel.hidden; }

    function apply(open) {
        panel.hidden = !open;
        section.classList.toggle('is-conversation-open', open);
        root.classList.toggle('is-chat-open', open);
        var ph = open ? input.getAttribute('data-placeholder-open') : input.getAttribute('data-placeholder');
        if (ph) { input.placeholder = ph; }
        if (resumeBtn) { resumeBtn.hidden = open || !resumeBtn.getAttribute('data-has-conversation'); }
        if (narrow.matches) { document.body.style.overflow = open ? 'hidden' : ''; }
        if (open) { window.scrollTo(0, 0); scrollToEnd(true); } else { window.scrollTo(0, savedScroll); }
    }

    function transition(open, done) {
        var ran = false;
        var run = function () { if (!ran) { ran = true; apply(open); } };
        if (document.startViewTransition && !reduceMotion.matches && !document.hidden) {
            var vt = document.startViewTransition(run);
            // The browser runs the callback on its next paint. If painting is throttled (a covered or background tab) the state
            // must still change, so the same switch is made after a short bound; whichever comes first wins, the other is a no-op.
            window.setTimeout(run, 350);
            if (done) {
                var fired = false;
                var once = function () { if (!fired) { fired = true; done(); } };
                vt.finished.then(once, once);
                window.setTimeout(once, 700);
            }
        } else {
            run();
            if (done) { done(); }
        }
    }

    function setOpen(open) {
        if (open === isOpen()) { return; }
        if (open) {
            savedScroll = window.pageYOffset || 0;
            var a = document.activeElement;
            opener = a && a !== document.body && a !== input && section.contains(a) === false ? a : null;
            if (window.location.hash !== '#ask') { window.history.pushState({ ask: 1 }, '', '#ask'); }
            transition(true, function () { input.focus({ preventScroll: true }); });
        } else {
            // Closing through the page's own history entry keeps Back/Forward honest; the popstate handler does the work.
            if (window.history.state && window.history.state.ask) { window.history.back(); return; }
            if (window.location.hash === '#ask') { window.history.replaceState(null, '', window.location.pathname + window.location.search); }
            closeNow();
        }
    }

    function closeNow() {
        transition(false, function () {
            var back = opener && document.contains(opener) ? opener : input;
            back.focus({ preventScroll: true });
            opener = null;
        });
    }

    window.addEventListener('popstate', function () {
        var wantsOpen = !!(window.history.state && window.history.state.ask) || window.location.hash === '#ask';
        if (!wantsOpen && isOpen()) { closeNow(); }
        if (wantsOpen && !isOpen()) { transition(true, function () { input.focus({ preventScroll: true }); }); }
    });

    function nearBottom() { return thread.scrollHeight - thread.scrollTop - thread.clientHeight < 120; }

    // The view follows new messages only while the customer is already at the bottom (or just sent something).
    function scrollToEnd(force) {
        if (force || state.stick) { thread.scrollTop = thread.scrollHeight; }
    }
    // ── rendering ──────────────────────────────────────────────────────
    function linkList(links, cls) {
        var ul = el('ul', cls || 'home-ask-links list-unstyled mb-0');
        (links || []).forEach(function (l) {
            var u = safeUrl(l.url);
            if (!u) { return; }
            var li = el('li');
            var a = el('a', null, l.label);
            a.href = u;
            li.appendChild(a);
            ul.appendChild(li);
        });
        return ul.children.length ? ul : null;
    }

    function renderEvidence(box, payload) {
        if (payload.facts && payload.facts.length) {
            var wrap = el('div', 'home-ask-facts');
            wrap.appendChild(el('p', 'home-ask-label mb-25', 'Based on'));
            var dl = el('dl', 'mb-0');
            payload.facts.forEach(function (f) {
                var row = el('div', 'home-ask-fact');
                row.appendChild(el('dt', null, f.label));
                row.appendChild(el('dd', null, f.value));
                dl.appendChild(row);
            });
            wrap.appendChild(dl);
            box.appendChild(wrap);
        }
        if (payload.limitations && payload.limitations.length) {
            var lim = el('div', 'home-ask-limits');
            lim.appendChild(el('p', 'home-ask-label mb-25', 'Worth knowing'));
            var ul = el('ul', 'mb-0 ps-1');
            payload.limitations.forEach(function (t) { ul.appendChild(el('li', null, t)); });
            lim.appendChild(ul);
            box.appendChild(lim);
        }
        var links = linkList(payload.links);
        if (links) {
            var l = el('div', 'home-ask-linkbox');
            l.appendChild(el('p', 'home-ask-label mb-25', 'Open'));
            l.appendChild(links);
            box.appendChild(l);
        }
    }

    function chips(list, onPick) {
        var ul = el('ul', 'list-unstyled d-flex flex-wrap gap-50 mt-50 mb-0');
        list.forEach(function (text) {
            var li = el('li');
            var b = el('button', 'home-chip', text);
            b.type = 'button';
            b.addEventListener('click', function () { onPick(text); });
            li.appendChild(b);
            ul.appendChild(li);
        });
        return ul;
    }

    function proposalCard(p, msgUid) {
        var card = el('div', 'home-ask-proposal');
        card.setAttribute('data-role', 'ask-proposal');
        card.setAttribute('data-proposal', p.uid);
        card.setAttribute('data-status', p.status);
        card.appendChild(el('p', 'home-ask-title mb-50', p.preview.title));

        var dl = el('dl', 'mb-50');
        (p.preview.lines || []).forEach(function (l) {
            var row = el('div', 'home-ask-fact');
            row.appendChild(el('dt', null, l.label));
            row.appendChild(el('dd', null, l.value));
            dl.appendChild(row);
        });
        card.appendChild(dl);

        if (p.preview.recipients && p.preview.recipients.length) {
            card.appendChild(el('p', 'mb-25', 'Who will be contacted: ' + p.preview.recipients.join(', ')));
        }
        if (p.preview.cost) { card.appendChild(el('p', 'mb-25', 'Cost: ' + p.preview.cost)); }
        if (p.preview.effects && p.preview.effects.length) {
            var ul = el('ul', 'mb-50 ps-1');
            p.preview.effects.forEach(function (t) { ul.appendChild(el('li', null, t)); });
            card.appendChild(ul);
        }

        var result = el('div', 'home-ask-result');
        result.setAttribute('role', 'status');
        card.appendChild(result);

        var actions = el('div', 'd-flex flex-wrap gap-50');
        if (p.canConfirm) {
            var ok = el('button', 'btn btn-primary btn-sm', p.retry ? 'Try again' : (p.preview.confirmLabel || 'Confirm'));
            ok.type = 'button';
            ok.setAttribute('data-role', 'ask-confirm');
            ok.addEventListener('click', function () { decide(p.uid, 'approve', card, ok, no); });
            var no = el('button', 'btn btn-outline-secondary btn-sm', 'Not now');
            no.type = 'button';
            no.setAttribute('data-role', 'ask-decline');
            no.addEventListener('click', function () { decide(p.uid, 'decline', card, ok, no); });
            actions.appendChild(ok);
            actions.appendChild(no);
        }
        card.appendChild(actions);
        showProposalState(card, p);
        return card;
    }

    var STATE_TEXT = {
        completed: ['success', 'Done.'],
        handed_off: ['success', 'Ready for your review.'],
        declined: ['muted', 'Okay — nothing was done.'],
        expired: ['muted', 'This expired before it was confirmed. Nothing was done — ask me again.'],
        stale: ['error', 'Something changed, so nothing was done.'],
        failed: ['error', 'That did not go through. Nothing was changed.'],
        executing: ['muted', 'Working on it…']
    };

    function showProposalState(card, p) {
        var box = card.querySelector('.home-ask-result');
        box.textContent = '';
        card.setAttribute('data-status', p.status);
        var t = STATE_TEXT[p.status];
        if (!t) { return; }
        box.setAttribute('data-kind', t[0]);
        box.appendChild(el('span', null, p.resultMessage || t[1]));
        var u = safeUrl(p.resultUrl);
        if (u && (p.status === 'completed')) {
            box.appendChild(document.createTextNode(' '));
            var a = el('a', null, 'View it');
            a.href = u;
            box.appendChild(a);
        }
        if (u && p.status === 'handed_off') {
            box.appendChild(document.createTextNode(' '));
            var b = el('button', 'btn btn-primary btn-sm', 'Open Follow-ups');
            b.type = 'button';
            b.addEventListener('click', function () {
                var root = document.querySelector('[data-role="command-center"]');
                if (root) { root.dispatchEvent(new CustomEvent('home:open-drawer', { detail: { url: u, title: 'Review follow-ups', full: null, from: b } })); }
            });
            box.appendChild(b);
        }
        if (!p.canConfirm) {
            var acts = card.querySelector('.d-flex');
            if (acts) { acts.textContent = ''; }
        }
    }

    function decide(uid, verb, card, ok, no) {
        var url = (verb === 'approve' ? cfg.approve : cfg.decline).replace('__UID__', encodeURIComponent(uid));
        ok.disabled = true; no.disabled = true;
        var box = card.querySelector('.home-ask-result');
        box.textContent = verb === 'approve' ? 'Working on it…' : '';
        box.setAttribute('data-kind', 'muted');

        request(url, { method: 'POST', body: {} }).then(function (res) {
            if (res.status === 409) {
                box.textContent = 'This is already being handled — check again in a moment.';
                ok.disabled = false; no.disabled = false;
                return;
            }
            if (res.status === 401 || res.status === 403 || res.status === 404) {
                box.textContent = 'You no longer have access to do this. Nothing was changed.';
                box.setAttribute('data-kind', 'error');
                return;
            }
            if (!res.ok || !res.body || !res.body.proposal) { throw new Error('bad'); }
            var p = res.body.proposal;
            var fresh = proposalCard(p);
            card.parentNode.replaceChild(fresh, card);
            announce();
        }).catch(function () {
            box.textContent = "We couldn't reach MotionGrove. Nothing was confirmed — please try again.";
            box.setAttribute('data-kind', 'error');
            ok.disabled = false; no.disabled = false;
        });
    }

    function bubble(m) {
        var row = el('div', 'home-ask-msg');
        row.setAttribute('data-role', 'ask-message');
        row.setAttribute('data-kind', m.kind);
        row.setAttribute('data-from', m.role);
        var box = el('div', 'home-ask-bubble');
        var p = m.payload || {};

        if (m.role === 'user') {
            box.appendChild(el('p', 'mb-0', m.body));
            row.appendChild(box);
            return row;
        }

        if (m.kind === 'notice') {
            box.classList.add('is-notice');
            box.appendChild(el('p', 'home-ask-title mb-25', p.title || 'How Ask MotionGrove uses AI'));
            box.appendChild(el('p', 'text-caption mb-0', m.body));
            row.appendChild(box);
            return row;
        }

        if (m.kind === 'limit') {
            box.classList.add('is-limit');
            box.appendChild(el('p', 'home-ask-title mb-50', p.title || ''));
            (m.body || '').split(/\n\n+/).forEach(function (para) { box.appendChild(el('p', 'mb-50', para)); });
            if (p.resolved) {
                box.appendChild(el('p', 'text-caption mb-0', 'Your AI assistant is available again.'));
            } else if (p.upgrade && safeUrl(p.upgrade.url)) {
                var a = el('a', 'btn btn-primary btn-sm', p.upgrade.label || 'Upgrade plan');
                a.href = safeUrl(p.upgrade.url);
                a.setAttribute('data-role', 'ask-upgrade');
                box.appendChild(a);
            } else if (p.ownerOnly) {
                box.appendChild(el('p', 'text-caption mb-0', 'Ask your account owner or an admin to upgrade the plan — they manage the subscription.'));
            }
            row.appendChild(box);
            return row;
        }

        if (m.kind === 'error') {
            box.classList.add('is-error');
            box.appendChild(el('p', 'home-ask-title mb-25', p.title || "I couldn't complete that"));
            box.appendChild(el('p', 'mb-50', m.body));
            if (p.retryable && p.attempt) {
                // Bound to THIS error's own attempt (question, client id, conversation target): a newer question never changes it.
                var attempt = p.attempt;
                var retry = el('button', 'btn btn-outline-secondary btn-sm', 'Try again');
                retry.type = 'button';
                retry.setAttribute('data-role', 'ask-retry');
                retry.addEventListener('click', function () {
                    if (state.busy || retry.disabled) { return; }
                    retry.disabled = true; // one retry per error bubble; a new failure gets its own button
                    send(attempt.q, attempt);
                });
                box.appendChild(retry);
            }
            row.appendChild(box);
            return row;
        }

        (m.body || '').split(/\n/).forEach(function (line, i, all) {
            var para = el('p', i === all.length - 1 ? 'mb-0' : 'mb-25', line);
            box.appendChild(para);
        });

        if (m.kind === 'clarify') {
            box.classList.add('is-clarify');
            if (p.options && p.options.length) { box.appendChild(chips(p.options, function (t) { send(t); })); }
        } else if (m.kind === 'answer' || m.kind === 'unsupported' || m.kind === 'navigate') {
            renderEvidence(box, p);
            if (p.notice) { box.appendChild(el('p', 'text-caption text-muted mt-50 mb-0', p.notice)); }
            if (p.suggestions && p.suggestions.length) { box.appendChild(chips(p.suggestions, function (t) { send(t); })); }
        }

        if (m.proposal) {
            box.appendChild(proposalCard(m.proposal, m.uid));
        }

        row.appendChild(box);
        if (m.kind === 'answer' && (m.body || '') !== '') { row.appendChild(copyAction(m.body)); }
        return row;
    }

    function announce() { scrollToEnd(); }

    // A conversation with nothing in it yet: a short invitation and the same suggestions the card offers (each is the literal question).
    function emptyState() {
        var wrap = el('div', 'home-ask-empty');
        wrap.setAttribute('data-role', 'ask-empty');
        wrap.appendChild(el('p', 'home-ask-title mb-0', 'Ask anything about your business.'));
        var list = [];
        section.querySelectorAll('[data-role="ask-suggestion"]').forEach(function (c) { list.push(c.getAttribute('data-q')); });
        if (list.length) { wrap.appendChild(chips(list, function (t) { send(t); })); }
        return wrap;
    }

    // Copy is a plain client-side action on an answer's own text; there is no feedback endpoint, so none is offered.
    function copyAction(text) {
        var bar = el('div', 'home-ask-actions');
        var copy = el('button', 'home-ask-action', 'Copy');
        copy.type = 'button';
        copy.setAttribute('data-role', 'ask-copy');
        copy.setAttribute('aria-label', 'Copy this answer');
        copy.addEventListener('click', function () {
            var done = function (ok) {
                copy.textContent = ok ? 'Copied' : 'Press Ctrl+C to copy';
                window.setTimeout(function () { copy.textContent = 'Copy'; }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
            } else { done(false); }
        });
        bar.appendChild(copy);
        return bar;
    }

    function render(data) {
        thread.textContent = '';
        var asked = '';
        (data.messages || []).forEach(function (m) {
            if (m.kind === 'outcome') { return; } // shown on its proposal card
            if (m.role === 'user') { asked = m.body; }
            if (m.kind === 'error' && m.payload && m.payload.retryable && !m.payload.attempt && asked) {
                // A stored failed turn is rebuilt from the server: bind its retry to the question asked just before IT, never to a
                // newer one. The turn was answered (with an error), so the retry is a new request and takes a fresh client id.
                m.payload.attempt = { q: asked, id: null, uid: data.uid, startNew: false };
            }
            thread.appendChild(bubble(m));
        });
        state.uid = data.uid || state.uid;
        if (deleteBtn) { deleteBtn.hidden = !state.uid; }
        if (!(data.messages || []).length && data.notice) {
            thread.appendChild(bubble({ role: 'assistant', kind: 'notice', body: data.notice.body, payload: { title: data.notice.title } }));
        } else if (!(data.messages || []).length) {
            thread.appendChild(emptyState());
        }
        renderAllowance(data.allowance);
        if (resumeBtn) { resumeBtn.setAttribute('data-has-conversation', (data.messages || []).length ? '1' : ''); }
        scrollToEnd();
    }

    function renderAllowance(a) {
        if (!banner) { return; }
        banner.textContent = '';
        banner.hidden = true;
        if (!a) { return; }
        if (a.blocked) {
            banner.hidden = false;
            banner.setAttribute('data-kind', 'limit');
            banner.appendChild(el('strong', null, a.blocked.title + ' '));
            if (a.blocked.upgrade && safeUrl(a.blocked.upgrade.url)) {
                var link = el('a', null, a.blocked.upgrade.label);
                link.href = safeUrl(a.blocked.upgrade.url);
                banner.appendChild(link);
            } else if (a.blocked.ownerOnly) {
                banner.appendChild(document.createTextNode('Ask your account owner or an admin to upgrade.'));
            }
        } else if (a.nearing) {
            banner.hidden = false;
            banner.setAttribute('data-kind', 'nearing');
            banner.textContent = 'You have used most of your included AI. Everything else keeps working.';
        }
    }

    function setBusy(b) {
        state.busy = b;
        if (submitBtn) { submitBtn.disabled = b; submitBtn.setAttribute('aria-label', b ? 'Working' : 'Ask'); }
        section.classList.toggle('is-busy', b);
        if (working) { working.hidden = !b; }
        panel.setAttribute('aria-busy', b ? 'true' : 'false');
    }

    // ── sending ────────────────────────────────────────────────────────
    function send(question, retryOf) {
        question = (question || '').trim();
        if (question === '' || state.busy) { return; }

        // The saved conversation (and, on first use, the AI-data notice) is read eagerly. A question sent before that has finished
        // waits for it: if this turns out to be first use the notice is shown first and the customer sends again, so nothing reaches
        // the AI provider before the disclosure was on screen.
        if (!retryOf && !state.loaded) {
            input.value = question;
            loadSaved().then(function () {
                if (!state.loaded) {
                    setOpen(true);
                    thread.appendChild(bubble({ role: 'assistant', kind: 'error', body: "We couldn't load Ask MotionGrove just now. Nothing was sent. Please try again.", payload: { title: "I couldn't start", retryable: false } }));
                    return;
                }
                if (state.firstUse) { input.focus(); return; }
                send(question);
            });
            return;
        }
        setOpen(true);

        // One logical request = one attempt record. A retry reuses the ORIGINAL client id and conversation target, so the server
        // answers (and charges) it exactly once even if the first response was lost; a newer question has its own record.
        // Typing the same question again after it failed is the same logical request too.
        var attempt = retryOf || state.failed[question] || { q: question, id: uuid(), uid: state.uid, startNew: state.startNew };
        if (!attempt.id) { attempt.id = uuid(); }
        state.failed[question] = attempt;

        state.stick = true;
        thread.appendChild(bubble({ role: 'user', kind: 'text', body: question, payload: {} }));
        scrollToEnd(true);
        input.value = '';
        autosize();
        saveDraft();
        setBusy(true);

        var body = { q: question, client_message_id: attempt.id };
        if (attempt.uid && !attempt.startNew) { body.conversation = attempt.uid; }
        if (attempt.startNew) { body.new = true; }

        request(cfg.message, { method: 'POST', body: body }).then(function (res) {
            if (res.status === 409) {
                thread.appendChild(bubble({ role: 'assistant', kind: 'error', body: 'I am still working on your last message. Try again in a moment.', payload: { title: 'One at a time', retryable: true, attempt: attempt } }));
                return;
            }
            if (res.status === 422) {
                delete state.failed[question];
                var first = res.body && res.body.errors ? res.body.errors[Object.keys(res.body.errors)[0]][0] : 'That could not be sent.';
                thread.appendChild(bubble({ role: 'assistant', kind: 'error', body: first, payload: { title: "That couldn't be sent", retryable: false } }));
                return;
            }
            if (res.status === 429) {
                thread.appendChild(bubble({ role: 'assistant', kind: 'error', body: 'You are asking quickly. Wait a moment and try again.', payload: { title: 'Slow down a little', retryable: true, attempt: attempt } }));
                return;
            }
            if (!res.ok || !res.body) { throw new Error('status ' + res.status); }
            // Only the conversation that was current when this request was sent may be cleared by its answer.
            delete state.failed[question];
            if (state.firstUse) { state.firstUse = false; showNotice(null); }
            if (attempt.startNew && state.startNew) { state.startNew = false; }
            render(res.body);
        }).catch(function () {
            thread.appendChild(bubble({ role: 'assistant', kind: 'error', body: "We couldn't reach MotionGrove. Nothing was changed. Please try again.", payload: { title: "I couldn't complete that", retryable: true, attempt: attempt } }));
        }).then(function () { setBusy(false); scrollToEnd(); });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        send(input.value);
    });

    section.querySelectorAll('[data-role="ask-suggestion"]').forEach(function (chip) {
        chip.addEventListener('click', function () { send(chip.getAttribute('data-q')); });
    });

    // An AI-assisted button elsewhere on Home (e.g. the waiting-customers move) asks the same assistant, by the same intentional send.
    document.querySelectorAll('[data-ask-prompt]').forEach(function (btn) {
        btn.addEventListener('click', function () { send(btn.getAttribute('data-ask-prompt')); });
    });

    if (minimizeBtn) {
        minimizeBtn.addEventListener('click', function () { setOpen(false); });
    }
    // Escape anywhere goes back to Home; the conversation and any unsent draft are kept. An open <details> menu or a composing IME owns Escape first.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || e.defaultPrevented || e.isComposing || !isOpen()) { return; }
        var menu = section.querySelector('details[open]');
        if (menu) { menu.removeAttribute('open'); return; }
        setOpen(false);
    });

    if (newBtn) {
        newBtn.addEventListener('click', function () {
            state.uid = null;
            state.startNew = true;
            state.failed = {};
            thread.textContent = '';
            thread.appendChild(emptyState());
            if (deleteBtn) { deleteBtn.hidden = true; }
            input.focus();
        });
    }

    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            if (!state.uid || !cfg.remove) { return; }
            // Two-step: the first press asks, the second deletes. Nothing is removed by a stray click.
            if (deleteBtn.getAttribute('data-armed') !== '1') {
                deleteBtn.setAttribute('data-armed', '1');
                deleteBtn.textContent = 'Press again to delete';
                window.setTimeout(function () { deleteBtn.removeAttribute('data-armed'); deleteBtn.textContent = 'Delete conversation'; }, 5000);
                return;
            }
            deleteBtn.disabled = true;
            request(cfg.remove.replace('__UID__', encodeURIComponent(state.uid)), { method: 'POST', body: {} }).then(function (res) {
                if (res.status === 409) {
                    thread.appendChild(bubble({ role: 'assistant', kind: 'error', body: (res.body && res.body.message) || 'An action is still running. Try again in a moment.', payload: { title: 'Not deleted yet', retryable: false } }));
                    return;
                }
                if (!res.ok) { throw new Error('status ' + res.status); }
                state.uid = null;
                state.startNew = false;
                state.failed = {};
                thread.textContent = '';
                thread.appendChild(el('p', 'text-muted mb-0', 'Conversation deleted.'));
                if (resumeBtn) { resumeBtn.removeAttribute('data-has-conversation'); resumeBtn.hidden = true; }
                deleteBtn.hidden = true;
            }).catch(function () {
                thread.appendChild(bubble({ role: 'assistant', kind: 'error', body: "We couldn't delete it just now. Nothing was changed.", payload: { title: 'Not deleted', retryable: false } }));
            }).then(function () { deleteBtn.disabled = false; deleteBtn.removeAttribute('data-armed'); deleteBtn.textContent = 'Delete conversation'; });
        });
    }

    if (resumeBtn) {
        resumeBtn.addEventListener('click', function () { setOpen(true); });
    }

    // Reading the saved conversation never calls a model; it only lets Home offer to continue it (and, on first use,
    // carries the AI-data notice shown when the panel opens).
    function showNotice(notice) {
        if (!state.notice) {
            state.notice = el('div', 'home-ask-notice text-caption text-muted mt-1');
            state.notice.setAttribute('data-role', 'ask-notice');
            form.parentNode.insertBefore(state.notice, form.nextSibling);
        }
        state.notice.textContent = '';
        if (!notice) { state.notice.hidden = true; return; }
        state.notice.appendChild(el('strong', null, notice.title || ''));
        state.notice.appendChild(document.createTextNode(' ' + (notice.body || '')));
        state.notice.hidden = false;
    }

    // Returns a promise that settles once the read has finished (state.loaded says whether it worked).
    function loadSaved() {
        if (!cfg.show) { state.loaded = true; return Promise.resolve(); }
        return request(cfg.show).then(function (res) {
            if (!res.ok || !res.body) { return; }
            state.loaded = true;
            var any = (res.body.messages || []).length > 0;
            state.firstUse = !any && !!res.body.notice;
            // First use: the disclosure sits right under the input, on screen before anything can be sent.
            showNotice(state.firstUse ? res.body.notice : null);
            render(res.body);
            if (any && resumeBtn) {
                resumeBtn.setAttribute('data-has-conversation', '1');
                resumeBtn.hidden = false;
            }
        }).catch(function () { /* the box still works once the read succeeds; until then nothing is sent */ });
    }

    // ── composer: multiline, Enter sends, Shift+Enter breaks the line; the unsent draft survives Back to Home and a reload ───────
    var draftKey = 'homeAskDraft:' + (cfg.message || '');

    function autosize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 168) + 'px';
    }

    function saveDraft() {
        try {
            if (input.value) { window.sessionStorage.setItem(draftKey, input.value); } else { window.sessionStorage.removeItem(draftKey); }
        } catch (e) { /* storage unavailable: the draft just lives for the page */ }
    }

    try {
        var saved = window.sessionStorage.getItem(draftKey);
        if (saved && !input.value) { input.value = saved; autosize(); }
    } catch (e) { /* ignore */ }

    input.addEventListener('input', function () { autosize(); saveDraft(); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { send(input.value); }
        }
    });
    thread.addEventListener('scroll', function () { state.stick = nearBottom(); }, { passive: true });

    var initialLoad = loadSaved();

    // A reload or a shared link on #ask lands back in the conversation (it is read from the server, never lost).
    if (window.location.hash === '#ask') {
        initialLoad.then(function () { if (!isOpen()) { apply(true); input.focus({ preventScroll: true }); } });
    }
})();
