// Real-browser regression for live updates in the Conversations inbox (the screen Agency Outreach -> Conversations opens into).
// It loads the SHIPPED `live-inbox` block and the shipped conversation-selection handler out of
// resources/views/customer/ChatBox/index.blade.php (not a copy) into Chromium, against a simulated server that renders the same
// row / thread markup shapes (data-id, data-item-key, .notification_count) the real partials render.
// Run: node tests/js/conversations-live.spec.js   (needs `playwright` and its Chromium; BLADE=<path> tests another revision).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const blade = fs.readFileSync(process.env.BLADE || path.join(root, 'resources/views/customer/ChatBox/index.blade.php'), 'utf8');
const between = (from, to) => {
  const a = blade.indexOf(from);
  const b = blade.indexOf(to, a);
  assert.ok(a >= 0 && b > a, `markers not found: ${from}`);
  return blade.slice(a, b);
};
const shipped = [
  between('// BEGIN live-inbox', '// END live-inbox'),
  between('$("#users-list").on("click", ".chat-users-list li, .chat-users-list-pinned li", function() {\n          // Destroy', '// The contact panel slides'),
].join('\n').replace(/\{\{[\s\S]*?\}\}/g, 'x');

const html = `<!doctype html><html><body>
<input class="chat_id">
<div id="users-list" style="height:160px;overflow:auto"><ul class="chat-users-list-pinned"></ul><ul class="chat-users-list"></ul></div>
<div class="user-chats" style="height:120px;overflow:auto"><div class="chat_history"></div></div>
<div id="conversation-context"></div><input type="file" id="media_image"><textarea id="message"></textarea></body></html>`;

// ---- the simulated server: conversations with stored messages; unread is a server count that opening never clears ----
function makeServer() {
  const s = { convs: {}, clock: 1000, hits: [], inflight: 0, maxInflight: 0, delay: {}, fail: null, reloads: 0 };
  s.add = (id, name, opts = {}) => { s.convs[id] = { id, name, pinned: !!opts.pinned, unread: 0, at: s.clock++, msgs: [] }; return s.convs[id]; };
  s.inbound = (id, text, key) => { const c = s.convs[id]; c.msgs.push({ key: key || `m${s.clock}`, dir: 'in', text }); c.unread += 1; c.at = s.clock++; };
  s.outbound = (id, text) => { const c = s.convs[id]; c.msgs.push({ key: `m${s.clock}`, dir: 'out', text }); c.at = s.clock++; };
  const row = (c) => {
    const last = c.msgs.at(-1);
    return `<li data-id="${c.id}" data-box-id="${c.id}" class="conv-row ${c.unread ? 'conv-unread' : ''}"><h6 data-role="conversation-row-title">${c.name}</h6>`
      + `<p data-role="conversation-row-preview">${last ? last.text : 'No messages yet'}</p><div class="chat-meta">`
      + (c.unread ? `<span class="badge notification_count">${c.unread}</span>` : '<div class="counter" hidden><span class="badge notification_count"></span></div>')
      + '</div></li>';
  };
  s.list = (pinned) => Object.values(s.convs).filter((c) => c.pinned === pinned).sort((a, b) => b.at - a.at).map(row).join('');
  s.thread = (id) => s.convs[id].msgs.map((m) => `<div class="chat" data-role="timeline-message" data-item-key="${m.key}"><p>${m.text}</p></div>`).join('') || '<div class="timeline-empty">No messages</div>';
  return s;
}

async function scenario(name, fn, { clock = false } = {}) {
  if (process.env.ONLY && !name.includes(process.env.ONLY)) { return; }
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const server = makeServer();
  if (clock) { await page.clock.install(); }
  await page.route('http://conv.test/**', async (route) => {
    const url = route.request().url();
    if (url.endsWith('/')) { return route.fulfill({ contentType: 'text/html', body: html }); }
    const params = new URLSearchParams(route.request().postData() || '');
    const kind = url.includes('/load') ? (params.get('pinned') ? 'pinned' : 'list') : 'timeline';
    const id = kind === 'timeline' ? url.split('/').pop() : null;
    server.hits.push({ kind, id, params: Object.fromEntries(params) });
    server.inflight += 1; server.maxInflight = Math.max(server.maxInflight, server.inflight);
    try {
      if (server.fail && server.fail(kind, id)) { const f = server.fail(kind, id); return route.fulfill({ status: f, body: 'no' }); }
      const body = kind === 'timeline' ? JSON.stringify({ status: 'success', timeline: server.thread(id) }) : server.list(kind === 'pinned');
      const hold = server.delay[kind + ':' + (id || '')];
      const snapshot = body;   // the answer is fixed at request time; a late arrival is stale by then
      if (hold) { await hold; }
      return route.fulfill({ status: 200, contentType: kind === 'timeline' ? 'application/json' : 'text/html', body: snapshot });
    } finally { server.inflight -= 1; }
  });
  await page.goto('http://conv.test/');
  page.on('pageerror', (e) => console.error('PAGEERROR', e.message));
  page.on('framenavigated', () => { server.reloads += 1; });
  await page.addScriptTag({ path: path.join(root, 'public/vendors/js/vendors.min.js') });
  await page.evaluate((code) => {
    window.conversationRoutes = { load: 'http://conv.test/load' };
    window.conversationUrl = (n, id) => 'http://conv.test/' + n + '/' + id;
    window.invalidateComposer = () => {}; window.resetConversationHeader = () => {};
    // The selection handler's own timeline load: the open conversation's thread is drawn when it answers.
    window.loadTimeline = (id) => $.post('http://conv.test/timeline/' + id).done((r) => { if ($('.chat_id').val() === String(id)) { $('.chat_history').html(r.timeline); } });
    window.scope = { filter: 'recents', search: '', channel: 'all', createdFrom: '', createdTo: '' };
    const s = document.createElement('script'); s.textContent = code; document.body.appendChild(s);
  }, shipped);
  try { await fn(page, server); console.log('ok  -', name); } finally { await browser.close(); }
}

const bootList = (page, server) => page.evaluate(({ list, pinned }) => {
  $('.chat-users-list').html(list); $('.chat-users-list-pinned').html(pinned);
  inboxLive.start(() => window.scope);
}, { list: server.list(false), pinned: server.list(true) });
const open = async (page, server, id) => {
  await page.evaluate((i) => $('.chat-users-list li[data-id="' + i + '"], .chat-users-list-pinned li[data-id="' + i + '"]').first().trigger('click'), id);
  await page.waitForFunction((i) => $('.chat_id').val() === i && $('.chat_history').children().length > 0, id);
  await page.waitForTimeout(120);   // the shipped handler clears the composer 50 ms after the thread loads
};
const refresh = async (page, server) => {
  const before = server.hits.length;
  await page.evaluate(() => inboxLive.refreshNow());
  await page.waitForFunction(() => true);
  await page.waitForTimeout(250);
  assert.ok(server.hits.length > before, 'a refresh reads the server');
  while (server.inflight) { await page.waitForTimeout(50); }
  await page.waitForTimeout(100);
};
const keys = (page) => page.evaluate(() => $('.chat_history [data-item-key]').map((_, e) => $(e).attr('data-item-key')).get());
const rowOrder = (page) => page.evaluate(() => $('.chat-users-list > li').map((_, e) => $(e).attr('data-id')).get());
const seed = (server) => { server.add('A', 'Ann'); server.add('B', 'Bob'); server.inbound('A', 'a-1', 'a1'); server.inbound('B', 'b-1', 'b1'); server.convs.A.unread = 0; server.convs.B.unread = 0; };

(async () => {
  await scenario('(reported workflow) a reply to the open conversation appears once, in order, with no reload, and the draft and focus survive', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    await page.evaluate(() => { $('#message').val('half-typed reply').focus(); $('#message')[0].setSelectionRange(3, 7); });
    server.inbound('A', 'Hello from the prospect', 'a2');
    await refresh(page, server); await refresh(page, server);   // a second cycle must not draw it twice
    assert.deepEqual(await keys(page), ['a1', 'a2']);
    assert.equal(await page.evaluate(() => $('#message').val()), 'half-typed reply');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'message', 'focus stays in the composer');
    assert.deepEqual(await page.evaluate(() => [$('#message')[0].selectionStart, $('#message')[0].selectionEnd]), [3, 7]);
    assert.equal(server.reloads, 0, 'no navigation / reload');
    assert.equal(await page.evaluate(() => $('.chat-users-list li.active').attr('data-id')), 'A', 'A stays selected');
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A]').hasClass('conv-unread')), false, 'the open conversation is not shown unread');
  });

  await scenario('(reported workflow) B replying while A is open updates only B: preview, position and unread; A stays open', async (page, server) => {
    seed(server); server.convs.A.at = 2000; server.clock = 2001; await bootList(page, server); await open(page, server, 'A');
    assert.deepEqual(await rowOrder(page), ['A', 'B']);
    server.inbound('B', 'B says hi', 'b2');
    await refresh(page, server);
    assert.deepEqual(await rowOrder(page), ['B', 'A'], 'B moved to the top');
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=B] [data-role=conversation-row-preview]').text()), 'B says hi');
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=B]').hasClass('conv-unread')), true);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=B] .notification_count').text()), '1');
    assert.equal(await page.evaluate(() => $('.chat_id').val()), 'A');
    assert.deepEqual(await keys(page), ['a1'], "A's thread is untouched by B's reply");
    // Nothing the refresh did marks anything read: no request other than list / pinned / the open thread's timeline.
    assert.deepEqual([...new Set(server.hits.map((h) => h.kind + ':' + (h.id || '')))].sort(), ['list:', 'timeline:A']);
  });

  await scenario('a first-ever reply (a conversation the list does not have yet) appears; a read conversation never comes back unread', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A'); await open(page, server, 'B');
    server.add('C', 'Cy'); server.inbound('C', 'first ever', 'c1');
    server.inbound('B', 'while open', 'b2');                 // the server keeps counting for B, which is open
    await refresh(page, server);
    assert.deepEqual((await rowOrder(page)).slice(0, 3), ['B', 'C', 'A']);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=C]').hasClass('conv-unread')), true);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=B]').hasClass('conv-unread')), false, 'B is open');
    // A was read earlier (opened, then left). A reply that arrives afterwards is unread again, only for what is new.
    server.inbound('A', 'late', 'a2');
    await refresh(page, server);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A] .notification_count').text()), '1');
  });

  await scenario('a thread the operator opened earlier does not turn unread again just because the list was refreshed', async (page, server) => {
    seed(server); server.convs.A.unread = 2; await bootList(page, server);
    await open(page, server, 'A'); await open(page, server, 'B');
    await refresh(page, server); await refresh(page, server);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A]').hasClass('conv-unread')), false);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A] .notification_count').text()), '');
  });

  await scenario('a reader keeps their place; someone at the bottom keeps following', async (page, server) => {
    seed(server); for (let i = 0; i < 40; i++) { server.outbound('A', 'filler ' + i); }
    await bootList(page, server); await open(page, server, 'A');
    const geom = () => page.evaluate(() => { const c = $('.user-chats')[0]; return { top: c.scrollTop, max: c.scrollHeight - c.clientHeight, y: $('[data-item-key]').filter((_, e) => $(e).text().includes('filler 10'))[0].getBoundingClientRect().top }; });
    await page.evaluate(() => { const c = $('.user-chats')[0]; c.scrollTop = c.scrollHeight; });
    server.inbound('A', 'follow me', 'f1'); await refresh(page, server);
    let g = await geom(); assert.ok(Math.abs(g.top - g.max) <= 2, 'at the bottom: followed the new message');
    await page.evaluate(() => { const c = $('.user-chats')[0]; c.scrollTop = 200; });
    const before = await geom();
    server.inbound('A', 'do not move me', 'f2'); await refresh(page, server);
    const after = await geom();
    assert.ok(Math.abs(after.y - before.y) <= 2, `reading older messages: position held (${before.y} -> ${after.y})`);
    assert.ok(after.max > before.max, 'the new message was added below');
  });

  await scenario('a delayed answer for A never renders inside B after the operator switches threads', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    let release; server.delay['timeline:A'] = new Promise((r) => { release = r; });
    server.inbound('A', 'only for A', 'a2');
    await page.evaluate(() => inboxLive.refreshNow());
    await page.waitForFunction(() => true); await page.waitForTimeout(300);
    await page.evaluate(() => $('.chat-users-list li[data-id=B]').trigger('click'));
    await page.waitForFunction(() => $('.chat_id').val() === 'B' && $('.chat_history [data-item-key=b1]').length === 1);
    release(); await page.waitForTimeout(400);
    assert.deepEqual(await keys(page), ['b1'], "B shows only B's thread");
    assert.equal(await page.evaluate(() => $('.chat_history').text().includes('only for A')), false);
  });

  await scenario('an answer for a list the operator already left (tab / search changed) is dropped', async (page, server) => {
    seed(server); await bootList(page, server);
    let release; server.delay['list:'] = new Promise((r) => { release = r; });
    server.inbound('B', 'would be stale', 'b2');
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(300);
    await page.evaluate(() => { window.scope = { filter: 'unread', search: 'zzz', channel: 'all', createdFrom: '', createdTo: '' }; });
    release(); await page.waitForTimeout(400);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=B] [data-role=conversation-row-preview]').text()), 'b-1', 'the old-scope list was not merged');
  });

  await scenario('the pinned rail updates in place (preview, unread) without adding or dropping rows', async (page, server) => {
    seed(server); server.add('P', 'Pin', { pinned: true }); server.inbound('P', 'p-1', 'p1'); server.convs.P.unread = 0;
    await bootList(page, server); await open(page, server, 'A');
    server.inbound('P', 'pinned reply', 'p2'); server.add('Q', 'Pin2', { pinned: true }); server.inbound('Q', 'not in the rail yet', 'q1');
    await refresh(page, server);
    assert.equal(await page.evaluate(() => $('.chat-users-list-pinned li[data-id=P] [data-role=conversation-row-preview]').text()), 'pinned reply');
    assert.equal(await page.evaluate(() => $('.chat-users-list-pinned li[data-id=P]').hasClass('conv-unread')), true);
    assert.equal(await page.evaluate(() => $('.chat-users-list-pinned li[data-id=Q]').length), 0, 'the rail is only updated, never extended');
    assert.equal(server.hits.filter((h) => h.kind === 'pinned').every((h) => h.params.search === '' && h.params.pinned === '1'), true);
  });

  await scenario('one refresh at a time: a slow cycle is never overlapped; the missed trigger is caught up right after', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    let release; server.delay['list:'] = new Promise((r) => { release = r; });
    await page.evaluate(() => { inboxLive.refreshNow(); inboxLive.refreshNow(); inboxLive.refreshNow(); });
    await page.waitForTimeout(300);
    assert.equal(server.maxInflight, 1);
    server.delay['list:'] = null; server.inbound('A', 'during the slow cycle', 'a2');
    release(); await page.waitForTimeout(700);
    assert.equal(server.maxInflight, 1, 'still never two at once');
    assert.deepEqual(await keys(page), ['a1', 'a2'], 'the trigger that arrived mid-cycle was caught up');
  });

  await scenario('a temporary failure retries soon; a lost session / access (403) stops polling instead of hammering', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    let failing = true; server.fail = () => (failing ? 503 : 0);
    server.inbound('A', 'after the blip', 'a2');
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(500);
    assert.deepEqual(await keys(page), ['a1'], 'nothing drawn from a failed answer');
    failing = false; await page.waitForTimeout(2600);
    assert.deepEqual(await keys(page), ['a1', 'a2'], 'caught up on the retry');
    server.fail = () => 403; await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(400);
    const n = server.hits.length; await page.waitForTimeout(2600);
    assert.equal(server.hits.length, n, 'no further requests once access is refused');
  });

  await scenario('a thread that is gone (404) stops only its own refresh; the list keeps updating', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    server.fail = (kind, id) => (kind === 'timeline' && id === 'A' ? 404 : 0);
    await refresh(page, server);
    server.inbound('B', 'still arrives', 'b2'); await refresh(page, server); await refresh(page, server);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=B] [data-role=conversation-row-preview]').text()), 'still arrives');
    assert.equal(server.hits.filter((h) => h.kind === 'timeline').length, 2, 'the open plus ONE refused poll: the dead thread is not asked again');
  });

  await scenario('a poll overtaken by the operator\'s own request (send / retry / reload) is not applied over it', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    let release; server.delay['timeline:A'] = new Promise((r) => { release = r; });
    server.inbound('A', 'older snapshot', 'a2');
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(300);
    await page.evaluate(() => { $('.chat_history').append('<div class="chat" data-role="timeline-message">optimistic bubble</div>'); $.post('http://conv.test/timeline/A'); });
    await page.waitForTimeout(200); server.delay['timeline:A'] = null; release(); await page.waitForTimeout(400);
    assert.equal(await page.evaluate(() => $('.chat_history').text().includes('optimistic bubble')), true, 'the stale poll did not wipe the just-sent bubble');
  });

  await scenario('a row pinned while a list answer was in flight is not drawn twice', async (page, server) => {
    seed(server); await bootList(page, server);
    let release; server.delay['list:'] = new Promise((r) => { release = r; });
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(300);
    await page.evaluate(() => { $('.chat-users-list-pinned').append($('.chat-users-list li[data-id=A]')); });   // the operator pins A
    server.delay['list:'] = null; release(); await page.waitForTimeout(500);
    assert.equal(await page.evaluate(() => $('#users-list li[data-id=A]').length), 1);
  });

  await scenario('polling is paused while the tab is hidden and catches up the moment it is visible again', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); });
    server.inbound('A', 'while hidden', 'a2');
    await page.waitForTimeout(5600);
    assert.equal(server.hits.filter((h) => h.id === 'A').length, 1, 'only the open load, nothing while hidden');
    await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => false }); document.dispatchEvent(new Event('visibilitychange')); });
    await page.waitForFunction(() => $('.chat_history [data-item-key=a2]').length === 1, null, { timeout: 2000 });
  });

  await scenario('REAL TIMERS: a stored reply shows up on its own within the polling interval (measured)', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    await page.evaluate(() => { $('#message').val('typing...'); });
    const t0 = Date.now(); server.inbound('A', 'automatic', 'a2');
    await page.waitForFunction(() => $('.chat_history [data-item-key=a2]').length === 1, null, { timeout: 12000 });
    const delay = Date.now() - t0;
    console.log(`    observed update delay: ${delay} ms`);
    assert.ok(delay < 8000, 'within one polling interval plus a request');
    assert.equal(await page.evaluate(() => $('#message').val()), 'typing...');
    assert.equal(server.reloads, 0);
  });

  console.log('conversations live updates: all browser scenarios passed');
})().catch((e) => { console.error(e); process.exit(1); });
