/*
 * Ask MotionGrove — conversation-switching regressions (no server, no AI provider).
 *
 * Runs INSIDE the real Home page (Playwright `page.evaluate`, or pasted into a console on a signed-in Home): it replaces window.fetch
 * with a stub whose responses are released by hand, so the order in which answers arrive is exact, not timing luck. It proves that a
 * late answer, an out-of-order read or a send made while another conversation is opening can never replace the conversation on screen
 * or put messages in the wrong thread, that no draft is lost, and that nothing is sent twice.
 *
 *   const results = await page.evaluate(fs.readFileSync('tests/Browser/AskMotionGrove/conversation-switching.js', 'utf8') + '; runConversationSwitchingChecks()');
 *   // every result must be { ok: true }
 */
async function runConversationSwitchingChecks() {
    var results = [];
    var realFetch = window.fetch;
    var section = document.querySelector('[data-role="home-ask"]');
    var q = function (sel) { return section.querySelector(sel); };
    var tick = function (ms) { return new Promise(function (r) { window.setTimeout(r, ms || 30); }); };

    var calls = [];
    var pending = [];

    function msg(uid, role, body) {
        return { uid: uid, role: role, kind: role === 'user' ? 'text' : 'answer', body: body, payload: {}, proposal: null, at: new Date().toISOString() };
    }
    function convo(uid, bodies) {
        return {
            uid: uid, title: 'Chat ' + uid,
            messages: bodies.map(function (b, i) { return msg(uid + '-m' + i, i % 2 === 0 ? 'user' : 'assistant', b); }),
            page: { has_more: false, before: null }, allowance: { status: 'ok', usable: true, nearing: false, blocked: null },
        };
    }
    var saved = { A: convo('A', ['asked in A', 'answer in A']), B: convo('B', ['asked in B', 'answer in B']) };
    var list = { conversations: [{ uid: 'A', title: 'Chat A', preview: 'answer in A', last_message_at: new Date().toISOString(), message_count: 2 }, { uid: 'B', title: 'Chat B', preview: 'answer in B', last_message_at: new Date().toISOString(), message_count: 2 }], has_more: false, next: null };

    // A stubbed network: GET reads answer at once unless `hold` is set; POSTs (the only calls that reach the assistant) always wait for release().
    var hold = { show: false, list: false };
    window.fetch = function (url, opts) {
        url = String(url);
        var method = (opts && opts.method) || 'GET';
        var entry = { url: url, method: method, body: opts && opts.body ? JSON.parse(opts.body) : null };
        calls.push(entry);
        var make = function (status, body) { return { ok: status < 400, status: status, json: function () { return Promise.resolve(body); } }; };
        var answer;
        if (method === 'POST' && /assistant\/messages/.test(url)) { answer = function () { return make(200, entry.reply); }; entry.hold = true; }
        else if (/assistant\/conversations/.test(url)) { answer = function () { return make(200, list); }; entry.hold = hold.list; }
        else { var m = /conversation=([^&]+)/.exec(url); answer = function () { return make(m ? 200 : 200, m ? saved[decodeURIComponent(m[1])] : { uid: null, messages: [], notice: null, allowance: { status: 'ok', usable: true, nearing: false, blocked: null } }); }; entry.hold = hold.show; }
        return new Promise(function (resolve) {
            entry.release = function (reply) { if (reply) { entry.reply = reply; } resolve(answer()); };
            if (!entry.hold) { entry.release(); } else { pending.push(entry); }
        });
    };

    function check(name, ok, detail) { results.push({ name: name, ok: !!ok, detail: ok ? undefined : detail }); }
    var threadText = function () { return q('[data-role="ask-thread"]').textContent; };
    var posts = function () { return calls.filter(function (c) { return c.method === 'POST' && /assistant\/messages/.test(c.url); }); };
    var openHistoryItem = function (uid) { q('[data-role="ask-history"]').click(); return tick(40).then(function () { var item = [].slice.call(section.querySelectorAll('[data-role="ask-history-item"]')).filter(function (b) { return b.parentNode.getAttribute('data-uid') === uid; })[0]; item.click(); }); };
    var type = function (text) { var i = q('[data-role="ask-input"]'); i.value = text; i.dispatchEvent(new Event('input', { bubbles: true })); };
    var submit = function () { q('[data-role="ask-form"]').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); };
    var reset = async function () { calls.length = 0; pending.length = 0; hold.show = false; hold.list = false; q('[data-role="ask-new"]').click(); await tick(); type(''); };
    var answerFor = function (uid, ask, say) { return { uid: uid, title: 'Chat ' + uid, messages: saved[uid].messages.concat([msg(uid + '-q', 'user', ask), msg(uid + '-a', 'assistant', say)]), page: { has_more: false, before: null }, allowance: { status: 'ok', usable: true, nearing: false, blocked: null } }; };

    try {
        q('[data-role="ask-resume"]').hidden = false;
        q('[data-role="ask-resume"]').click();
        await tick(400);

        // 1 · send, then New chat before the answer arrives
        await reset();
        await openHistoryItem('A'); await tick();
        type('first question'); submit(); await tick();
        q('[data-role="ask-new"]').click(); await tick();
        type('draft typed in the new chat');
        posts()[0].release(answerFor('A', 'first question', 'LATE ANSWER A')); await tick(80);
        check('1 · New chat stays a new chat after a late answer', !/LATE ANSWER A/.test(threadText()) && !!q('[data-role="ask-empty"]'), threadText().slice(0, 120));
        check('1 · the draft in the new chat survives', q('[data-role="ask-input"]').value === 'draft typed in the new chat', q('[data-role="ask-input"]').value);
        check('1 · exactly one question reached the assistant', posts().length === 1, posts().length);

        // 2 · send, then open another saved conversation
        await reset();
        await openHistoryItem('A'); await tick();
        type('second question'); submit(); await tick();
        await openHistoryItem('B'); await tick(80);
        posts()[0].release(answerFor('A', 'second question', 'LATE ANSWER A2')); await tick(80);
        check('2 · the opened conversation B stays on screen', /answer in B/.test(threadText()) && !/LATE ANSWER A2/.test(threadText()), threadText().slice(0, 160));
        check('2 · the late answer is not sent twice', posts().length === 1, posts().length);

        // 3 · open A then B quickly; A's read arrives last
        await reset();
        hold.show = true;
        await openHistoryItem('A'); await openHistoryItem('B'); await tick(40);
        var reads = pending.filter(function (c) { return /conversation=/.test(c.url); });
        reads[1].release(); await tick(60);
        reads[0].release(); await tick(80);
        check('3 · B (the last chosen) wins although A answered last', /answer in B/.test(threadText()) && !/answer in A/.test(threadText()), threadText().slice(0, 160));
        hold.show = false;

        // 4 · switch while a conversation is opening, then try to send
        await reset();
        hold.show = true;
        await openHistoryItem('B'); await tick(40);
        type('typed while opening'); submit(); await tick(60);
        check('4 · nothing is sent while a conversation is still opening', posts().length === 0, posts().length);
        check('4 · the words stay in the composer', q('[data-role="ask-input"]').value === 'typed while opening', q('[data-role="ask-input"]').value);
        pending.filter(function (c) { return /conversation=/.test(c.url); })[0].release(); await tick(80);
        hold.show = false;
        submit(); await tick(60);
        check('4 · after it opens, the send goes to THAT conversation, once', posts().length === 1 && posts()[0].body.conversation === 'B', JSON.stringify(posts().map(function (p) { return p.body; })));
        posts()[0].release(answerFor('B', 'typed while opening', 'answer for B')); await tick(80);
        check('4 · and its answer lands in B', /answer for B/.test(threadText()), threadText().slice(0, 160));

        // 5 · history reads that finish out of order: the latest read wins
        await reset();
        hold.list = true;
        q('[data-role="ask-history"]').click(); await tick(20);
        q('[data-role="ask-history-close"]').click(); q('[data-role="ask-history"]').click(); await tick(40);
        var lists = pending.filter(function (c) { return /assistant\/conversations/.test(c.url); });
        var firstList = JSON.parse(JSON.stringify(list));
        lists[1].release(); await tick(40);
        var after = section.querySelectorAll('[data-role="ask-history-item"]').length;
        lists[0].release(); await tick(40);
        check('5 · a stale history read does not overwrite the newer one', section.querySelectorAll('[data-role="ask-history-item"]').length === after && after === firstList.conversations.length, after);
        hold.list = false;
    } catch (e) {
        check('script ran to the end', false, String(e && e.stack || e));
    } finally {
        window.fetch = realFetch;
    }

    return results;
}

/*
 * The first read of the saved conversation (and the one the first send waits for) can answer late. The script re-initialises the Ask
 * card with the stub already in place, so the very first GET is held, then proves a late or overlapping read never replaces the
 * conversation the customer chose, never auto-sends into it, and still shows the first-use disclosure before anything is sent.
 *
 *   (the same evaluate pattern as above) + '; runSavedLoadChecks()'
 */
async function runSavedLoadChecks() {
    var results = [];
    var realFetch = window.fetch;
    var scriptUrl = document.querySelector('script[src*="ask-assistant.js"]').src.split('?')[0] + '?fresh=' + Date.now();
    var scriptText = await realFetch(scriptUrl).then(function (r) { return r.text(); });
    var tick = function (ms) { return new Promise(function (r) { window.setTimeout(r, ms || 30); }); };
    var calls = [];
    var pending = [];
    var hold = { initial: true };
    var allow = { status: 'ok', usable: true, nearing: false, blocked: null };
    var msg = function (uid, role, body) { return { uid: uid, role: role, kind: role === 'user' ? 'text' : 'answer', body: body, payload: {}, proposal: null, at: new Date().toISOString() }; };
    var convo = function (uid, bodies) { return { uid: uid, title: 'Chat ' + uid, messages: bodies.map(function (b, i) { return msg(uid + '-m' + i, i % 2 === 0 ? 'user' : 'assistant', b); }), page: { has_more: false, before: null }, allowance: allow }; };
    var saved = { B: convo('B', ['asked in B', 'answer in B']) };
    var list = { conversations: [{ uid: 'B', title: 'Chat B', preview: 'answer in B', last_message_at: new Date().toISOString(), message_count: 2 }], has_more: false, next: null };

    window.fetch = function (url, opts) {
        url = String(url);
        var method = (opts && opts.method) || 'GET';
        var entry = { url: url, method: method, body: opts && opts.body ? JSON.parse(opts.body) : null };
        calls.push(entry);
        var make = function (status, body) { return { ok: status < 400, status: status, json: function () { return Promise.resolve(body); } }; };
        var m = /conversation=([^&]+)/.exec(url);
        var isList = /assistant\/conversations/.test(url);
        var isSavedRead = method === 'GET' && !m && !isList;
        var held = method === 'POST' ? true : (isSavedRead ? hold.initial : false);
        return new Promise(function (resolve) {
            entry.release = function (reply) {
                resolve(make(200, reply || (isList ? list : (m ? saved[decodeURIComponent(m[1])] : convo('L', ['asked in L', 'answer in L'])))));
            };
            if (held) { pending.push(entry); } else { entry.release(); }
        });
    };

    var section;
    var q = function (sel) { return section.querySelector(sel); };
    var threadText = function () { return q('[data-role="ask-thread"]').textContent; };
    var posts = function () { return calls.filter(function (c) { return c.method === 'POST'; }); };
    var savedReads = function () { return pending.filter(function (c) { return c.method === 'GET' && !/conversation=/.test(c.url) && !/assistant\/conversations/.test(c.url); }); };
    var check = function (name, ok, detail) { results.push({ name: name, ok: !!ok, detail: ok ? undefined : detail }); };
    var type = function (text) { var i = q('[data-role="ask-input"]'); i.value = text; i.dispatchEvent(new Event('input', { bubbles: true })); };
    var submit = function () { q('[data-role="ask-form"]').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); };
    var openHistoryItem = function (uid) {
        q('[data-role="ask-history"]').click();
        return tick(40).then(function () {
            [].slice.call(section.querySelectorAll('[data-role="ask-history-item"]')).filter(function (b) { return b.parentNode.getAttribute('data-uid') === uid; })[0].click();
        });
    };

    // A fresh Ask card: the old one is replaced by a listener-free clone and the real script runs again with the stub in place.
    var boot = async function () {
        calls.length = 0; pending.length = 0; hold.initial = true;
        var old = document.querySelector('[data-role="home-ask"]');
        var clone = old.cloneNode(true);
        clone.dataset.assistantWired = '0';
        clone.classList.remove('is-conversation-open');
        // The clone starts clean: no open history panel, no leftover list rows (their listeners did not survive cloning), no old thread.
        clone.querySelector('[data-role="ask-history-panel"]').hidden = true;
        clone.querySelector('[data-role="ask-history-list"]').textContent = '';
        clone.querySelector('[data-role="ask-thread"]').textContent = '';
        clone.querySelector('[data-role="ask-conversation"]').hidden = true;
        old.parentNode.replaceChild(clone, old);
        section = clone;
        (0, eval)(scriptText);
        await tick(60);
    };

    try {
        // 1 - the initial GET is held; another conversation is opened and resolved first; the initial GET is released last
        await boot();
        type('my draft');
        await openHistoryItem('B'); await tick(80);
        savedReads()[0].release(convo('L', ['asked in L', 'answer in L'])); await tick(80);
        check('1 - a late initial read does not replace the selected conversation', /answer in B/.test(threadText()) && !/answer in L/.test(threadText()), threadText().slice(0, 140));
        check('1 - and leaves the composer alone', q('[data-role="ask-input"]').value === 'my draft', q('[data-role="ask-input"]').value);
        check('1 - no assistant call', posts().length === 0, posts().length);

        // 2 - two saved reads overlap; the older one answers last
        await boot();
        type('a question'); submit(); await tick(60); // the not-yet-loaded send asks for the saved chat again: two reads in flight
        var reads = savedReads();
        check('2 - two saved reads are in flight', reads.length === 2, reads.length);
        reads[1].release(convo('NEWER', ['asked in NEWER', 'answer in NEWER'])); await tick(60);
        reads[0].release(convo('OLDER', ['asked in OLDER', 'answer in OLDER'])); await tick(80);
        check('2 - the newest read wins; the stale one is ignored', /answer in NEWER/.test(threadText()) && !/answer in OLDER/.test(threadText()), threadText().slice(0, 140));

        // 3 - a pending first send must not fire into a conversation chosen meanwhile
        await boot();
        type('hold this question'); submit(); await tick(60);
        await openHistoryItem('B'); await tick(80);
        savedReads().forEach(function (r) { r.release(convo('L', ['asked in L', 'answer in L'])); }); await tick(100);
        check('3 - nothing is sent into the other conversation', posts().length === 0, JSON.stringify(posts().map(function (p) { return p.body; })));
        check('3 - the question stays in the composer', q('[data-role="ask-input"]').value === 'hold this question', q('[data-role="ask-input"]').value);
        check('3 - the chosen conversation stays on screen', /answer in B/.test(threadText()), threadText().slice(0, 140));

        // 4 - control: with no selection change, the waiting send goes out once
        await boot();
        type('go ahead'); submit(); await tick(60);
        savedReads().forEach(function (r) { r.release(convo('L', ['asked in L', 'answer in L'])); }); await tick(100);
        check('4 - an undisturbed waiting send is sent exactly once', posts().length === 1 && posts()[0].body.q === 'go ahead', JSON.stringify(posts().map(function (p) { return p.body; })));

        // 5 - first use: the disclosure is on screen and nothing is sent until the customer sends again
        await boot();
        type('first ever question'); submit(); await tick(60);
        savedReads().forEach(function (r) { r.release({ uid: null, messages: [], notice: { title: 'How Ask MotionGrove uses AI', body: 'DISCLOSURE TEXT' }, allowance: allow }); }); await tick(100);
        var notice = q('[data-role="ask-notice"]');
        check('5 - the first-use disclosure is shown', !!notice && !notice.hidden && /DISCLOSURE TEXT/.test(notice.textContent), notice && notice.textContent);
        check('5 - and nothing reached the assistant before it', posts().length === 0, posts().length);
    } catch (e) {
        check('script ran to the end', false, String((e && e.stack) || e));
    } finally {
        window.fetch = realFetch;
    }

    return results;
}

/*
 * Deleting a conversation is a two-step press and an asynchronous request. The script holds the delete response by hand and proves a
 * slow answer only ever clears the conversation it deleted (never one chosen meanwhile), and that the armed confirmation disarms
 * itself whenever the selected conversation changes.
 *
 *   (the same evaluate pattern as above) + '; runDeleteChecks()'
 */
async function runDeleteChecks() {
    var results = [];
    var realFetch = window.fetch;
    var section = document.querySelector('[data-role="home-ask"]');
    var q = function (sel) { return section.querySelector(sel); };
    var tick = function (ms) { return new Promise(function (r) { window.setTimeout(r, ms || 30); }); };
    var calls = [];
    var pending = [];
    var allow = { status: 'ok', usable: true, nearing: false, blocked: null };
    var msg = function (uid, role, body) { return { uid: uid, role: role, kind: role === 'user' ? 'text' : 'answer', body: body, payload: {}, proposal: null, at: new Date().toISOString() }; };
    var convo = function (uid, bodies) { return { uid: uid, title: 'Chat ' + uid, messages: bodies.map(function (b, i) { return msg(uid + '-m' + i, i % 2 === 0 ? 'user' : 'assistant', b); }), page: { has_more: false, before: null }, allowance: allow }; };
    var saved = { A: convo('A', ['asked in A', 'answer in A']), B: convo('B', ['asked in B', 'answer in B']) };
    var list = { conversations: [{ uid: 'A', title: 'Chat A', preview: 'answer in A', last_message_at: new Date().toISOString(), message_count: 2 }, { uid: 'B', title: 'Chat B', preview: 'answer in B', last_message_at: new Date().toISOString(), message_count: 2 }], has_more: false, next: null };

    window.fetch = function (url, opts) {
        url = String(url);
        var method = (opts && opts.method) || 'GET';
        var entry = { url: url, method: method };
        calls.push(entry);
        var make = function (status, body) { return { ok: status < 400, status: status, json: function () { return Promise.resolve(body); } }; };
        var m = /conversation=([^&]+)/.exec(url);
        var isDelete = method === 'POST' && /\/delete$/.test(url);
        return new Promise(function (resolve) {
            entry.release = function () {
                resolve(isDelete ? make(200, { deleted: true }) : make(200, /assistant\/conversations/.test(url) ? list : (m ? saved[decodeURIComponent(m[1])] : saved.A)));
            };
            if (isDelete) { pending.push(entry); } else { entry.release(); }
        });
    };

    var check = function (name, ok, detail) { results.push({ name: name, ok: !!ok, detail: ok ? undefined : detail }); };
    var threadText = function () { return q('[data-role="ask-thread"]').textContent; };
    var del = function () { return q('[data-role="ask-delete"]'); };
    var openHistoryItem = function (uid) {
        q('[data-role="ask-history"]').click();
        return tick(40).then(function () {
            [].slice.call(section.querySelectorAll('[data-role="ask-history-item"]')).filter(function (b) { return b.parentNode.getAttribute('data-uid') === uid; })[0].click();
        });
    };
    var deletes = function () { return calls.filter(function (c) { return c.method === 'POST' && /\/delete$/.test(c.url); }); };

    try {
        q('[data-role="ask-resume"]').hidden = false;
        q('[data-role="ask-resume"]').click();
        await tick(400);

        // 1 - a slow delete of A answers after B was opened: B must stay exactly as it is
        await openHistoryItem('A'); await tick(80);
        del().click(); del().click(); await tick(40); // arm, then confirm
        check('1 - the delete request names conversation A', deletes().length === 1 && /\/A\/delete$/.test(deletes()[0].url), JSON.stringify(deletes().map(function (d) { return d.url; })));
        await openHistoryItem('B'); await tick(80);
        pending[0].release(); await tick(100);
        check('1 - the late delete answer does not clear conversation B', /answer in B/.test(threadText()) && !/Conversation deleted\./.test(threadText()), threadText().slice(0, 140));
        check('1 - and B can still be deleted (the control is still there)', !del().hidden, 'hidden');

        // 2 - the armed confirmation does not survive a change of conversation
        pending.length = 0; calls.length = 0;
        await openHistoryItem('A'); await tick(80);
        del().click(); await tick(20);
        check('2 - one press arms it', del().getAttribute('data-armed') === '1', del().textContent);
        await openHistoryItem('B'); await tick(80);
        check('2 - choosing another conversation disarms it', del().getAttribute('data-armed') !== '1' && del().textContent === 'Delete conversation', del().textContent);
        del().click(); await tick(40);
        check('2 - the next press only arms again; nothing is deleted', deletes().length === 0, deletes().length);
    } catch (e) {
        check('script ran to the end', false, String((e && e.stack) || e));
    } finally {
        window.fetch = realFetch;
    }

    return results;
}
