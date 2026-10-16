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
