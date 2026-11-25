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
  // The page's own composer (channel switch, reply target, Send): the live refresh must drive it, so it is loaded for real.
  between('var composerState', "/**\n       * Load the person's activity timeline"),
  between('function m5UuidV4', 'function enter_chat'),
  between('function enter_chat', '// Conversations failed-send/retry — delegated'),
  between('// BEGIN live-inbox', '// END live-inbox'),
  between('$("#users-list").on("click", ".chat-users-list li, .chat-users-list-pinned li", function() {\n          // Destroy', '// The contact panel slides'),
  // The page's own pin handler and its own email archive / delete handlers (real code; the harness only supplies the server).
  between('$(".add-to-pin").on("click"', '        }).always(function() {\n          $pin.prop("disabled", false);\n        });\n      });'),
  '}).always(function() { $pin.prop("disabled", false); }); });',
  between('function openEmailThreadKey', '$(document).on("click", "[data-undo-archive]"'),
].join('\n').replace(/\{\{[\s\S]*?\}\}/g, 'x');

const html = `<!doctype html><html><body>
<input class="chat_id">
<div id="users-list" style="height:160px;overflow:auto"><ul class="chat-users-list-pinned"></ul><ul class="chat-users-list"></ul></div>
<div class="user-chats" style="height:120px;overflow:auto"><div class="chat_history"></div></div>
<div id="conversation-context"></div><button class="add-to-pin">pin</button><div class="active-chat"></div><div class="start-chat-area d-none"></div>
<button data-email-action="archive">archive</button><button data-email-action="delete">delete</button><input type="file" id="media_image">
<div class="chat-app-form"><div data-role="composer-channel"><button class="conversation-channel-option" data-channel="sms"></button><button class="conversation-channel-option" data-channel="email"></button></div>
<div data-role="composer-email-fields"><input id="email-subject"><select id="email-location"></select></div><div data-role="composer-email-toolbar"></div><span data-role="composer-email-to"><span data-role="composer-email-recipient"></span></span><p data-role="composer-guidance"></p>
<textarea id="message" class="message"></textarea><button class="send">Send</button></div></body></html>`;

// 10 s of silence as a playable WAV, so a real <audio>/<video> element can genuinely play in Chromium.
const wav = (() => { const n = 80000, b = Buffer.alloc(44 + n, 0x80); b.write('RIFF', 0); b.writeUInt32LE(36 + n, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22); b.writeUInt32LE(8000, 24); b.writeUInt32LE(8000, 28); b.writeUInt16LE(1, 32); b.writeUInt16LE(8, 34); b.write('data', 36); b.writeUInt32LE(n, 40); return 'data:audio/wav;base64,' + b.toString('base64'); })();

// ---- the simulated server: conversations with stored messages; unread is a server count that opening never clears ----
function makeServer() {
  const s = { convs: {}, clock: 1000, hits: [], inflight: 0, maxInflight: 0, delay: {}, fail: null, reloads: 0, emails: [] };
  s.add = (id, name, opts = {}) => { s.convs[id] = { id, name, pinned: !!opts.pinned, unread: 0, at: s.clock++, msgs: [] }; return s.convs[id]; };
  s.inbound = (id, text, key) => { const c = s.convs[id]; c.msgs.push({ key: key || `m${s.clock}`, dir: 'in', text }); c.unread += 1; c.at = s.clock++; };
  s.outbound = (id, text) => { const c = s.convs[id]; c.msgs.push({ key: `m${s.clock}`, dir: 'out', text }); c.at = s.clock++; };
  const row = (c) => {
    const last = c.msgs.at(-1);
    return `<li data-id="${c.id}" data-box-id="${c.id}" data-activity-ts="${c.at}" class="conv-row ${c.unread ? 'conv-unread' : ''}"><h6 data-role="conversation-row-title">${c.name}</h6>`
      + `<p data-role="conversation-row-preview">${last ? last.text : 'No messages yet'}</p><div class="chat-meta">`
      + (c.unread ? `<span class="badge notification_count">${c.unread}</span>` : '<div class="counter" hidden><span class="badge notification_count"></span></div>')
      + '</div></li>';
  };
  // The real `load`: the tab's filter (read / unread), newest first, 50 rows a page, and the empty state when nothing matches.
  s.list = (pinned, filter, limit) => {
    const rows = Object.values(s.convs).filter((c) => c.pinned === pinned).filter((c) => (filter === 'read' ? !c.unread : filter === 'unread' ? c.unread > 0 : true)).sort((a, b) => (b.at - a.at) || (a.id < b.id ? 1 : -1)).slice(0, limit || 1e9).map(row).join('');
    return rows || (pinned ? '' : '<div class="text-center text-muted p-2" data-role="chat-list-empty">No conversations</div>');
  };
  s.thread = (id) => s.convs[id].msgs.map((m) => `<div class="chat" data-role="timeline-message" data-item-key="${m.key}">${(m.media || []).map((x) => `<p class="timeline-media"><${x.tag} controls src="${x.src}"></${x.tag}></p>`).join('')}<p>${m.text}</p>${m.detail ? `<span data-role="timeline-detail">${m.detail}</span>` : ''}</div>`).join('') || '<div class="timeline-empty">No messages</div>';
  s.addMedia = (id, text, key, media) => { const c = s.convs[id]; c.msgs.push({ key, dir: 'in', text, media }); c.unread += 1; c.at = s.clock++; };
  s.setDetail = (id, key, detail) => { s.convs[id].msgs.find((m) => m.key === key).detail = detail; };
  // The composer the real timeline() returns: the Contact's latest email thread is the reply target, else a fresh send.
  s.composer = (id) => ({ sms: true, email: !s.convs[id].emailOff, default: 'sms', email_connected: !s.convs[id].emailOff, recipient: 'pat@example.com', subject: 'Re: Hi', locations: s.convs[id].locations || [],
    url: s.convs[id].emailUrl || 'http://conv.test/email/send', contact_uid: s.convs[id].emailUrl ? null : 'contact-' + id });
  s.emailArrives = (id, thread) => { const c = s.convs[id]; c.emailUrl = 'http://conv.test/email/reply/' + thread; c.msgs.push({ key: 'email:' + thread, dir: 'in', text: 'New email on ' + thread }); c.unread += 1; c.at = s.clock++; };
  s.sent = [];
  return s;
}

async function scenario(name, fn, { clock = false } = {}) {
  if (process.env.ONLY && !name.includes(process.env.ONLY)) { return; }
  const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  const page = await browser.newPage();
  const server = makeServer();
  if (clock) { await page.clock.install(); }
  await page.route('http://conv.test/**', async (route) => {
    const url = route.request().url();
    if (url.endsWith('/')) { return route.fulfill({ contentType: 'text/html', body: html }); }
    const params = new URLSearchParams(route.request().postData() || '');
    if (url.includes('/pin/')) { const c = server.convs[url.split('/').pop()]; c.pinned = !c.pinned; return route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":"success"}' }); }
    if (url.includes('/email-thread/')) {
      const act = url.split('/').pop(); const t = params.get('thread');
      if (server.actionHold) { await server.actionHold; }
      if (server.actionFail) { return route.fulfill({ status: 422, contentType: 'application/json', body: '{"message":"nope"}' }); }
      if (act === 'archive' || act === 'delete') { server.emails = server.emails.filter((e) => e !== t); }
      return route.fulfill({ status: 200, contentType: 'application/json', body: '{"status":"success"}' });
    }
    if (url.includes('/email/send') || url.includes('/email/reply/')) { server.sent.push({ url, body: Object.fromEntries(params) }); return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'success', message: 'Sent.' }) }); }
    const kind = url.includes('/load') ? (params.get('pinned') ? 'pinned' : 'list') : 'timeline';
    const id = kind === 'timeline' ? url.split('/').pop() : null;
    server.hits.push({ kind, id, params: Object.fromEntries(params) });
    server.inflight += 1; server.maxInflight = Math.max(server.maxInflight, server.inflight);
    try {
      if (id === 'hold') { if (server.delay['timeline:hold']) { await server.delay['timeline:hold']; } return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' }); }
      if (server.fail && server.fail(kind, id)) { const f = server.fail(kind, id); return route.fulfill({ status: f, body: 'no' }); }
      const body = kind === 'list' && server.listBody !== undefined ? server.listBody
        : kind === 'list' && params.get('channel') === 'email' ? server.emails.map((t) => `<li data-id="email:${t}" data-channel="email" class="conv-row email-row"><h6 data-role="conversation-row-title">${t}</h6></li>`).join('')
        : kind === 'timeline' ? JSON.stringify({ status: 'success', timeline: server.thread(id), composer: server.composer(id), channel: 'sms' }) : server.list(kind === 'pinned', params.get('filter'), kind === 'pinned' ? 0 : 50);
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
    window.resetConversationHeader = () => {}; window.isRtl = false; window.emailSendsInFlight = window.emailSendsInFlight || {};
    window.setPinState = () => {}; window.confirm = () => true;
    window.toastr = { warning: (m) => (window.toasts = window.toasts || []).push(m), success: () => {} };
    window.conversationRoutes.emailAction = { archive: 'http://conv.test/email-thread/archive', delete: 'http://conv.test/email-thread/delete', unarchive: 'http://conv.test/email-thread/unarchive', unread: 'http://conv.test/email-thread/unread' };
    window.conversationUrl = (n, id) => 'http://conv.test/' + n + '/' + id;
    // The selection handler's own timeline load: the open conversation's thread is drawn when it answers.
    window.loadTimeline = (id) => $.post('http://conv.test/timeline/' + id).done((r) => { if ($('.chat_id').val() === String(id)) { $('.chat_history').html(r.timeline); applyComposer(r.composer, 'sms'); } });
    window.scope = { filter: 'recents', search: '', channel: 'all', createdFrom: '', createdTo: '' };
    const s = document.createElement('script'); s.textContent = code; document.body.appendChild(s);
  }, shipped);
  try { await fn(page, server); console.log('ok  -', name); } finally { await browser.close(); }
}

const bootList = (page, server) => page.evaluate(({ list, pinned }) => {
  $('.chat-users-list').html(list); $('.chat-users-list-pinned').html(pinned);
  inboxLive.start(() => window.scope);
}, { list: server.list(false, server.bootFilter, 1e9), pinned: server.list(true) });
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

  await scenario('(thread 1) reopening a thread keeps the cumulative server count apart from the displayed delta', async (page, server) => {
    seed(server); server.convs.A.unread = 5; await bootList(page, server);
    await open(page, server, 'A'); await open(page, server, 'B');            // A read at 5, left
    server.inbound('A', 'one', 'a2'); server.inbound('A', 'two', 'a3');      // server count is now 7
    await refresh(page, server);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A] .notification_count').text()), '2', 'only the 2 new ones are shown');
    await page.evaluate(() => $('.chat-users-list li[data-id=A]').trigger('click'));      // reopen A
    await page.evaluate(() => $('.chat-users-list li[data-id=B]').trigger('click'));      // leave again BEFORE the next poll
    await refresh(page, server);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A]').hasClass('conv-unread')), false, 'A shows zero unread');
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A] .notification_count').text()), '');
    server.inbound('A', 'three', 'a4'); await refresh(page, server);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A] .notification_count').text()), '1', 'a later message is unread again, alone');
  });

  await scenario('(thread 1) a row no poll has refreshed yet, opened twice, keeps its server baseline', async (page, server) => {
    seed(server); server.convs.A.unread = 5; await bootList(page, server);       // initial markup: no data-server-count on A
    await open(page, server, 'A'); await open(page, server, 'B'); await open(page, server, 'A'); await open(page, server, 'B');   // no poll in between
    await refresh(page, server);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A]').hasClass('conv-unread')), false, 'the 5 read messages are not unread again');
    server.inbound('A', 'one more', 'a2'); await refresh(page, server);
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A] .notification_count').text()), '1');
  });

  const badge = (page, id) => page.evaluate((i) => ({ unread: $('.chat-users-list li[data-id=' + i + ']').hasClass('conv-unread'), n: $('.chat-users-list li[data-id=' + i + '] .notification_count').text() }), id);

  await scenario('(unseen reply 1) the poll observes a reply, A\'s timeline is delayed, the operator switches to B, the late answer is dropped: A stays unread', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    let release; server.delay['timeline:A'] = new Promise((r) => { release = r; });
    server.inbound('A', 'never rendered in A', 'a2');
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(400);        // list observed the reply, timeline still pending
    await page.evaluate(() => $('.chat-users-list li[data-id=B]').trigger('click'));
    await page.waitForFunction(() => $('.chat_id').val() === 'B' && $('.chat_history [data-item-key=b1]').length === 1);
    server.delay['timeline:A'] = null; release(); await page.waitForTimeout(500);
    assert.deepEqual(await badge(page, 'A'), { unread: true, n: '1' });
    await refresh(page, server); await refresh(page, server);
    assert.deepEqual(await badge(page, 'A'), { unread: true, n: '1' }, 'and it stays unread on later polls');
  });

  await scenario('(unseen reply 2) the timeline request FAILS, then the operator switches away: A stays unread', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    server.fail = (kind, id) => (kind === 'timeline' && id === 'A' ? 503 : 0);
    server.inbound('A', 'unseen', 'a2');
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(500);
    assert.deepEqual(await keys(page), ['a1'], 'the reply was never drawn');
    await open(page, server, 'B'); server.fail = null;
    await refresh(page, server);
    assert.deepEqual(await badge(page, 'A'), { unread: true, n: '1' });
  });

  await scenario('(unseen reply 3) the reply actually renders in A: A is read, and stays read after switching away', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    server.inbound('A', 'seen in A', 'a2'); await refresh(page, server);
    assert.deepEqual(await keys(page), ['a1', 'a2']);
    assert.equal((await badge(page, 'A')).unread, false);
    await open(page, server, 'B'); await refresh(page, server);
    assert.equal((await badge(page, 'A')).unread, false, 'read, because it was displayed');
  });

  await scenario('(unseen reply 4) an arrival newer than what an older timeline answer contained stays unread', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    server.inbound('A', 'in the answer', 'a2');
    let release; server.delay['timeline:A'] = new Promise((r) => { release = r; });
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(400);        // timeline request issued: its answer holds a2 only
    server.inbound('A', 'newer than the answer', 'a3');
    server.delay['timeline:A'] = null; release(); await page.waitForTimeout(500);
    assert.deepEqual(await keys(page), ['a1', 'a2']);
    // The next cycle's list observes a3, but its own timeline answer is held and the operator leaves: a3 was never displayed.
    let release2; server.delay['timeline:A'] = new Promise((r) => { release2 = r; });
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(400);
    await page.evaluate(() => $('.chat-users-list li[data-id=B]').trigger('click'));
    await page.waitForFunction(() => $('.chat_id').val() === 'B' && $('.chat_history [data-item-key=b1]').length === 1);
    server.delay['timeline:A'] = null; release2(); await page.waitForTimeout(500);
    assert.deepEqual(await badge(page, 'A'), { unread: true, n: '1' }, 'a3 was never displayed, so it is unread (a2 was displayed, so it is not counted)');
    await open(page, server, 'A'); await refresh(page, server);
    assert.deepEqual(await keys(page), ['a1', 'a2', 'a3']);
    assert.equal((await badge(page, 'A')).unread, false, 'read once it is displayed');
  });

  await scenario('(no flash) a reply to the open, displayed conversation never shows an unread badge on its row while the thread loads', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    await page.evaluate(() => { window.flashed = false; new MutationObserver(() => { if ($('.chat-users-list li[data-id=A]').hasClass('conv-unread')) { window.flashed = true; } }).observe(document.getElementById('users-list'), { subtree: true, childList: true, attributes: true }); });
    let release; server.delay['timeline:A'] = new Promise((r) => { release = r; });
    server.inbound('A', 'live reply', 'a2');
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(500);        // list observed it, thread still loading
    assert.equal((await badge(page, 'A')).unread, false, 'no badge while the open thread is about to show it');
    server.delay['timeline:A'] = null; release(); await page.waitForTimeout(500);
    assert.deepEqual(await keys(page), ['a1', 'a2']);
    assert.equal(await page.evaluate(() => window.flashed), false, 'never, not even briefly');
  });

  await scenario('(no flash) while the page itself is busy with the thread (poll skipped), the open row still does not flash; the badge appears once the operator leaves', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    server.inbound('A', 'during own request', 'a2');
    await page.evaluate(() => { window.holdOwn = $.post('http://conv.test/timeline/A'); $.ajaxSettings.global = true; });   // an own (global) request in flight: the thread poll is skipped
    let release; server.delay['timeline:A'] = new Promise((r) => { release = r; });
    await page.evaluate(() => { $.post('http://conv.test/timeline/A'); }); await page.waitForTimeout(100);
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(500);
    assert.equal((await badge(page, 'A')).unread, false, 'skipped thread poll: still no flash on the open row');
    server.delay['timeline:A'] = null; release(); await page.waitForTimeout(300);
    await page.evaluate(() => $('.chat-users-list li[data-id=B]').trigger('click'));
    await page.waitForTimeout(100);
    assert.deepEqual(await badge(page, 'A'), { unread: true, n: '1' }, 'left without it ever being displayed: unread');
  });

  await scenario('(no flash, opening) a reply arriving while the thread is still opening is never badged on the row being opened', async (page, server) => {
    seed(server); await bootList(page, server);
    let release; server.delay['timeline:A'] = new Promise((r) => { release = r; });
    await page.evaluate(() => { window.flashed = false; new MutationObserver(() => { if ($('.chat-users-list li[data-id=A]').hasClass('conv-unread')) { window.flashed = true; } }).observe(document.getElementById('users-list'), { subtree: true, childList: true, attributes: true }); });
    await page.evaluate(() => $('.chat-users-list li[data-id=A]').trigger('click')); await page.waitForTimeout(300);   // the open load is slow
    server.inbound('A', 'arrives while A is opening', 'a2');
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(500);
    server.delay['timeline:A'] = null; release();
    await page.waitForFunction(() => $('.chat_history [data-item-key]').length >= 1); await page.waitForTimeout(300);
    await refresh(page, server);
    assert.deepEqual(await keys(page), ['a1', 'a2'].slice(0, (await keys(page)).length));
    assert.equal(await page.evaluate(() => window.flashed), false, 'never badged, not even briefly');
    assert.equal((await badge(page, 'A')).unread, false);
  });

  await scenario('(streak) the busy-skip cap is per open conversation, not shared across switches', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    let releaseA; server.delay['timeline:hold'] = new Promise((r) => { releaseA = r; });
    await page.evaluate(() => { $.post('http://conv.test/timeline/hold'); });                    // an own request in flight: thread steps are skipped
    for (let i = 0; i < 5; i++) { await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(250); }   // A burns the whole cap
    releaseA(); await page.waitForTimeout(300);
    await open(page, server, 'B');
    let releaseB; server.delay['timeline:hold'] = new Promise((r) => { releaseB = r; });
    await page.evaluate(() => { $.post('http://conv.test/timeline/hold'); });
    server.inbound('B', 'reply to B while the page is busy', 'b2');
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(400);
    assert.equal((await badge(page, 'B')).unread, false, 'B gets its own deferral window');
    releaseB(); await page.waitForTimeout(300);
  });

  // ---- (email reply target) the refreshed timeline's composer metadata drives the page's real composer and Send ----
  const composeEmail = (page) => page.evaluate(() => { setComposerChannel('email'); $('#email-subject').val('Re: pricing'); $('#message').val('half-typed email reply').focus(); $('#message')[0].setSelectionRange(2, 6); });
  const sendAndRead = async (page, server) => { await page.evaluate(() => enter_chat()); await page.waitForTimeout(500); return server.sent.at(-1); };

  for (const [label, hadThread] of [['the contact already had an email thread', true], ['the contact had no email thread when first opened', false]]) {
    await scenario(`(email reply target) ${label}: a newer thread arrives through live refresh, and Send targets it, with draft, focus and channel kept`, async (page, server) => {
      seed(server); if (hadThread) { server.convs.A.emailUrl = 'http://conv.test/email/reply/T-old'; }
      await bootList(page, server); await open(page, server, 'A'); await composeEmail(page);
      assert.equal(await page.evaluate(() => composerState.cfg.url), hadThread ? 'http://conv.test/email/reply/T-old' : 'http://conv.test/email/send');
      server.emailArrives('A', 'T-new'); await refresh(page, server);
      assert.ok((await keys(page)).includes('email:T-new'), 'the timeline shows the new email');
      assert.equal(await page.evaluate(() => composerState.cfg.url), 'http://conv.test/email/reply/T-new', 'the composer now targets the new thread');
      assert.equal(await page.evaluate(() => composerState.channel), 'email', 'the selected channel is kept');
      assert.equal(await page.evaluate(() => $('#message').val()), 'half-typed email reply', 'the draft is kept');
      assert.equal(await page.evaluate(() => $('#email-subject').val()), 'Re: pricing', 'the typed subject is kept');
      assert.equal(await page.evaluate(() => document.activeElement.id), 'message', 'focus is kept');
      assert.deepEqual(await page.evaluate(() => [$('#message')[0].selectionStart, $('#message')[0].selectionEnd]), [2, 6]);
      const sent = await sendAndRead(page, server);
      assert.ok(sent, 'Send made a request');
      assert.equal(sent.url, 'http://conv.test/email/reply/T-new', 'the ACTUAL request goes to the newest thread');
      assert.equal(sent.body.body, 'half-typed email reply');
      assert.equal(sent.body.contact_uid, undefined, 'a reply to a thread, not a new send for the contact');
    });
  }

  await scenario('(email composer) a poll never resets the operator\'s chosen Location, and Send still carries it', async (page, server) => {
    seed(server); server.convs.A.locations = [{ uid: 'L1', name: 'One' }, { uid: 'L2', name: 'Two' }];
    await bootList(page, server); await open(page, server, 'A'); await composeEmail(page);
    await page.evaluate(() => { $('#email-location').val('L2'); });
    server.inbound('A', 'a newer text', 'a9'); await refresh(page, server);
    assert.equal(await page.evaluate(() => $('#email-location').val()), 'L2', 'the choice survived the poll');
    assert.equal(await page.evaluate(() => $('#email-location option').length), 3, 'the options are still the server\'s');
    const sent = await sendAndRead(page, server);
    assert.ok(sent, 'Send went through without asking for a Location again');
    assert.equal(sent.body.location_uid, 'L2');
  });

  await scenario('(email composer) a poll never moves an email draft to SMS when email stops being available', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A'); await composeEmail(page);
    server.convs.A.emailOff = true;                                                            // the mailbox was disconnected meanwhile
    server.inbound('A', 'a newer text', 'a9'); await refresh(page, server);
    assert.equal(await page.evaluate(() => composerState.channel), 'email', 'the selected channel is not changed behind the operator');
    assert.equal(await page.evaluate(() => $('#message').val()), 'half-typed email reply', 'the draft is untouched');
    assert.ok((await keys(page)).includes('a9'), 'the thread itself still refreshed');
  });

  await scenario('(email composer) when email becomes unavailable the page says so and blocks Send, without moving the draft or the channel; it recovers with the new reply target', async (page, server) => {
    seed(server); server.convs.A.emailUrl = 'http://conv.test/email/reply/T-old'; await bootList(page, server); await open(page, server, 'A'); await composeEmail(page);
    server.convs.A.emailOff = true; server.emailArrives('A', 'T-mid');
    await page.evaluate(() => { window.toasts = []; });
    await refresh(page, server);
    assert.equal(await page.evaluate(() => composerState.cfg.email), false, 'the page now knows email is unavailable');
    assert.equal(await page.evaluate(() => composerState.channel), 'email', 'the channel is not changed');
    assert.equal(await page.evaluate(() => $('#message').val()), 'half-typed email reply', 'the draft is untouched');
    assert.equal(await page.evaluate(() => $('[data-role=composer-guidance]').prop('hidden')), false, 'the guidance is shown');
    assert.equal(await page.evaluate(() => $('[data-role=composer-channel] [data-channel=email]').prop('hidden')), true, 'the channel pill follows what is usable now');
    const before = server.sent.length;
    await page.evaluate(() => enter_chat()); await page.waitForTimeout(400);
    assert.equal(server.sent.length, before, 'Send does not go to the stale reply target');
    assert.equal(await page.evaluate(() => window.toasts.length), 1, 'the operator is told why');
    server.convs.A.emailOff = false; server.emailArrives('A', 'T-new'); await refresh(page, server);
    assert.equal(await page.evaluate(() => composerState.cfg.url), 'http://conv.test/email/reply/T-new', 'and it recovers with the newest thread');
    assert.equal((await sendAndRead(page, server)).url, 'http://conv.test/email/reply/T-new');
  });

  await scenario('(email composer) a Location left over from another conversation is never carried into a thread whose composer was not set up yet', async (page, server) => {
    seed(server); server.convs.A.locations = server.convs.B.locations = [{ uid: 'L1', name: 'One' }, { uid: 'L2', name: 'Two' }];
    await bootList(page, server); await open(page, server, 'A'); await composeEmail(page);
    await page.evaluate(() => { $('#email-location').val('L2'); });
    await open(page, server, 'B');                                                              // B open; its composer is not set up yet:
    await page.evaluate(() => { invalidateComposer(); $('#email-location').html('<option value="">x</option><option value="L2">Two</option>').val('L2'); });   // A's choice is still in the shared field
    server.inbound('B', 'poll sets B up', 'b9'); await refresh(page, server);
    assert.equal(await page.evaluate(() => composerState.forId), 'B', 'the poll set the composer up for B');
    assert.equal(await page.evaluate(() => $('#email-location').val() || ''), '', 'B does not inherit A\'s Location');
  });

  // ---- (filter membership) a conversation that stops matching the active tab leaves the list; one that merely moved past page 1 stays ----
  const onTab = (page, f) => page.evaluate((x) => { window.scope = { filter: x, search: '', channel: 'all', createdFrom: '', createdTo: '' }; }, f);
  const ids = (page) => page.evaluate(() => $('.chat-users-list > li[data-id]').map((_, e) => $(e).attr('data-id')).get());
  const emptyShown = (page) => page.evaluate(() => $('.chat-users-list [data-role=chat-list-empty]').length);

  await scenario('(filter) in the Read tab a conversation that receives a reply leaves the list, and the others stay', async (page, server) => {
    seed(server); server.bootFilter = 'read'; await onTab(page, 'read'); await bootList(page, server);
    assert.deepEqual((await ids(page)).sort(), ['A', 'B']);
    server.inbound('B', 'B no longer matches Read', 'b2'); await refresh(page, server);
    assert.deepEqual(await ids(page), ['A'], 'B left the Read list; it is now unread');
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=A] [data-role=conversation-row-preview]').text()), 'a-1');
  });

  await scenario('(filter) when the LAST matching conversation leaves, the server\'s empty state is shown', async (page, server) => {
    seed(server); delete server.convs.B; server.bootFilter = 'read'; await onTab(page, 'read'); await bootList(page, server);
    server.inbound('A', 'the only read one got a reply', 'a2'); await refresh(page, server);
    assert.deepEqual(await ids(page), []);
    assert.equal(await emptyShown(page), 1, 'the empty state replaces the stale row');
    server.convs.A.unread = 0; await refresh(page, server);                                   // it matches again (read elsewhere)
    assert.deepEqual(await ids(page), ['A']);
    assert.equal(await emptyShown(page), 0, 'and the empty state goes away');
  });

  await scenario('(filter) the open conversation is never pulled out from under the operator', async (page, server) => {
    seed(server); server.bootFilter = 'read'; await onTab(page, 'read'); await bootList(page, server); await open(page, server, 'A');
    server.inbound('A', 'reply to the open one', 'a2'); await refresh(page, server);
    assert.ok((await ids(page)).includes('A'), 'A stays: it is open');
    assert.deepEqual(await keys(page), ['a1', 'a2']);
  });

  await scenario('(filter) rows that only moved beyond page 1 (scrolled in later pages) are kept; a page-1 row that stops matching is removed', async (page, server) => {
    for (let i = 0; i < 55; i++) { server.add('C' + i, 'Conv ' + i); server.inbound('C' + i, 'm' + i, 'k' + i); server.convs['C' + i].unread = 0; server.convs['C' + i].at = 5000 - i; }
    server.bootFilter = 'read'; await onTab(page, 'read'); await bootList(page, server);       // all 55 shown, as after scrolling to page 2
    assert.equal((await ids(page)).length, 55);
    server.inbound('C10', 'C10 now unread', 'k10x'); server.convs.C10.unread = 1; await refresh(page, server);
    const after = await ids(page);
    assert.equal(after.includes('C10'), false, 'the page-1 row that stopped matching is gone');
    assert.equal(after.includes('C52') && after.includes('C54'), true, 'rows from later pages are untouched');
  });

  await scenario('(filter) a row displaced to page 2 by an arrival on a full page is kept, and the list stays aligned with the server\'s paging', async (page, server) => {
    for (let i = 0; i < 55; i++) { server.add('C' + i, 'Conv ' + i); server.inbound('C' + i, 'm' + i, 'k' + i); server.convs['C' + i].at = 5000 - i; }
    await bootList(page, server);                                                                // all 55 on screen, as after scrolling to page 2
    server.clock = 9000; server.inbound('C52', 'a later-page conversation jumps to the top', 'k52x');   // server page 1 is now C52, C0..C48: C49 is displaced
    await refresh(page, server);
    const after = await ids(page);
    assert.equal(after[0], 'C52', 'the replied conversation moved to the top');
    assert.equal(after.includes('C49'), true, 'C49 only moved to page 2: it is still a matching conversation');
    assert.equal(after.length, 55, 'nothing was lost');
  });

  await scenario('(filter) the answer\'s last row is not loaded locally (only page 1 is): a row that stopped matching is still removed', async (page, server) => {
    for (let i = 0; i < 60; i++) { server.add('U' + String(i).padStart(2, '0'), 'Unread ' + i); server.inbound('U' + String(i).padStart(2, '0'), 'm' + i, 'k' + i); server.convs['U' + String(i).padStart(2, '0')].at = 5000 - i; }
    server.bootFilter = 'unread'; await onTab(page, 'unread');
    await page.evaluate((l) => { $('.chat-users-list').html(l); inboxLive.start(() => window.scope); }, server.list(false, 'unread', 50));   // only page 1 loaded
    server.convs.U05.unread = 0;                                                               // read elsewhere: it leaves the Unread tab; page 1 now ends with U50, which is not on screen
    await refresh(page, server);
    assert.equal((await ids(page)).includes('U05'), false, 'removed although the boundary row was never loaded');
    assert.equal((await ids(page)).length, 49 + 1, 'the rest stay, and the newly ranked row appears');
  });

  await scenario('(filter) a local order that differs from the server\'s (timestamp tie, a row moved by hand) never removes a row that still matches', async (page, server) => {
    const name = (i) => 'P' + String(i).padStart(2, '0');
    for (let i = 0; i < 50; i++) { server.add(name(i), 'P ' + i); server.inbound(name(i), 'm' + i, 'k' + i); server.convs[name(i)].at = 5000 - i; }
    server.add('Q', 'Q'); server.inbound('Q', 'q', 'kq'); server.convs.Q.at = server.convs.P49.at;              // Q ties P49; the server's tiebreak ranks Q above P49
    server.add('R', 'R'); server.inbound('R', 'r', 'kr'); server.convs.R.at = 3000;
    await bootList(page, server);                                                                  // 52 rows on screen; server page 1 = P00..P48 + Q, so Q is the boundary
    await page.evaluate(() => { $('.chat-users-list li[data-id=P49]').insertBefore($('.chat-users-list li[data-id=Q]')); });   // the local order now puts P49 BEFORE the boundary
    await refresh(page, server);
    const after = await ids(page);
    assert.equal(after.includes('P49'), true, 'P49 ties the boundary: it is on page 2, not gone');
    assert.equal(after.length, 52, 'nothing was lost');
  });

  await scenario('(filter) a 200 answer that is not a real list (no rows, no empty state) never wipes the list', async (page, server) => {
    seed(server); await bootList(page, server);
    server.listBody = '<html><body>Please sign in</body></html>';
    await refresh(page, server);
    assert.deepEqual((await ids(page)).sort(), ['A', 'B'], 'the list is untouched');
    assert.equal(await emptyShown(page), 0);
  });

  // ---- (MMS playback) unchanged attachments keep their player, position and play state across a refresh ----
  const playing = (page) => page.evaluate(async () => {
    const a = $('audio')[0], v = $('video')[0]; window.__a = a; window.__v = v;
    await Promise.all([a.play(), v.play()]);
    await new Promise((r) => setTimeout(r, 700));
    return { a: a.currentTime, v: v.currentTime };
  });
  const players = (page) => page.evaluate(() => ({ sameA: $('audio')[0] === window.__a, sameV: $('video')[0] === window.__v, aPaused: window.__a.paused, vPaused: window.__v.paused, a: window.__a.currentTime, v: window.__v.currentTime, connected: window.__a.isConnected && window.__v.isConnected }));

  await scenario('(MMS playback) a new message arriving keeps the playing audio and video elements, positions and play state', async (page, server) => {
    seed(server); server.addMedia('A', 'voicemail', 'mm1', [{ tag: 'audio', src: wav }]); server.convs.A.msgs.find((m) => m.key === 'mm1').media.push({ tag: 'video', src: wav });
    await bootList(page, server); await open(page, server, 'A');
    const before = await playing(page);
    assert.ok(before.a > 0.3 && before.v > 0.3, 'both are really playing');
    server.inbound('A', 'a newer message', 'a9'); await refresh(page, server);
    assert.ok((await keys(page)).includes('a9'), 'the new message is rendered');
    const after = await players(page);
    assert.equal(after.sameA && after.sameV, true, 'the existing elements were kept, not recreated');
    assert.equal(after.aPaused || after.vPaused, false, 'still playing');
    assert.ok(after.a >= before.a && after.v >= before.v, 'the positions did not reset');
  });

  await scenario('(MMS playback) a delivery update on the attachment\'s own message is rendered and the player is still kept', async (page, server) => {
    seed(server); server.addMedia('A', 'voicemail', 'mm1', [{ tag: 'audio', src: wav }]); server.convs.A.msgs.find((m) => m.key === 'mm1').media.push({ tag: 'video', src: wav });
    await bootList(page, server); await open(page, server, 'A');
    const before = await playing(page);
    server.setDetail('A', 'mm1', 'Delivered'); await refresh(page, server);
    assert.equal(await page.evaluate(() => $('[data-item-key=mm1] [data-role=timeline-detail]').text()), 'Delivered', 'the delivery state is rendered');
    const after = await players(page);
    assert.equal(after.sameA && after.sameV && after.connected, true, 'the same players, still in the page');
    assert.equal(after.aPaused || after.vPaused, false, 'still playing');
    assert.ok(after.a >= before.a && after.v >= before.v, 'the positions did not reset');
  });

  await scenario('(MMS playback) an attachment that actually changed is replaced, not kept', async (page, server) => {
    seed(server); server.addMedia('A', 'voicemail', 'mm1', [{ tag: 'audio', src: wav }]);
    await bootList(page, server); await open(page, server, 'A');
    await page.evaluate(() => { window.__a = $('audio')[0]; });
    server.convs.A.msgs.find((m) => m.key === 'mm1').media = [{ tag: 'audio', src: wav + 'AAAA' }];
    await refresh(page, server);
    assert.equal(await page.evaluate(() => $('audio')[0] === window.__a), false, 'a different source is a different attachment');
  });

  await scenario('(no stuck read) the initial load of A fails, so the thread is never displayed: a reply to A is still badged', async (page, server) => {
    seed(server); await bootList(page, server);
    server.fail = (kind, id) => (kind === 'timeline' && id === 'A' ? 500 : 0);
    await page.evaluate(() => $('.chat-users-list li[data-id=A]').trigger('click')); await page.waitForTimeout(300);
    assert.equal(await page.evaluate(() => $('.chat_history').children().length), 0, 'nothing displayed');
    server.inbound('A', 'unseen: the thread never loaded', 'a2');
    await refresh(page, server); await refresh(page, server);
    assert.deepEqual(await badge(page, 'A'), { unread: true, n: '1' });
  });

  await scenario('(no stuck read) a thread that became unavailable (404) is not deferred forever: its new reply is badged', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    server.fail = (kind, id) => (kind === 'timeline' && id === 'A' ? 404 : 0);
    await refresh(page, server);                                                              // A is now known gone for this operator
    server.inbound('A', 'arrives for a thread that is no longer served', 'a2');
    await refresh(page, server); await refresh(page, server);
    assert.deepEqual(await badge(page, 'A'), { unread: true, n: '1' });
  });

  await scenario('(mobile read) the server counter is reset by another client: the next reply shows 1 unread, not hidden by the old baseline', async (page, server) => {
    seed(server); server.convs.A.unread = 5; await bootList(page, server);
    await open(page, server, 'A'); await open(page, server, 'B');
    server.convs.A.unread = 0;                                                                // read on mobile: shared counter reset
    await refresh(page, server);
    assert.equal((await badge(page, 'A')).unread, false);
    server.inbound('A', 'first reply after the mobile read', 'a2'); await refresh(page, server);
    assert.deepEqual(await badge(page, 'A'), { unread: true, n: '1' });
    server.inbound('A', 'second', 'a3'); await refresh(page, server);
    assert.deepEqual(await badge(page, 'A'), { unread: true, n: '2' });
  });

  await scenario('(thread 2) poll in flight, the operator pins (real handler), the older response lands: the row appears once, in the rail', async (page, server) => {
    seed(server); await bootList(page, server); await open(page, server, 'A');
    let release; server.delay['list:'] = new Promise((r) => { release = r; });
    server.inbound('B', 'b news', 'b2');
    await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(300);          // the older response is on its way
    await page.evaluate(() => $('.add-to-pin').trigger('click'));                                 // pin A through the page's own handler
    await page.waitForFunction(() => $('.chat-users-list-pinned li[data-id=A]').length === 1);
    server.delay['list:'] = null; release(); await page.waitForTimeout(500);
    assert.equal(await page.evaluate(() => $('#users-list li[data-id=A]').length), 1, 'exactly once');
    assert.equal(await page.evaluate(() => $('.chat-users-list-pinned li[data-id=A]').length), 1, 'in the pinned rail');
    assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id=B] [data-role=conversation-row-preview]').text()), 'b news', 'the rest of the older answer still merged');
  });

  for (const action of ['archive', 'delete']) {
    await scenario(`(thread 3) Email list poll in flight, ${action} succeeds (real handler), the older response lands: the thread stays removed`, async (page, server) => {
      server.emails = ['T1', 'T2'];
      await page.evaluate(() => { window.scope = { filter: 'recents', search: '', channel: 'email', createdFrom: '', createdTo: '' }; });
      await page.evaluate((l) => { $('.chat-users-list').html(l); inboxLive.start(() => window.scope); },
        server.emails.map((t) => `<li data-id="email:${t}" data-channel="email" class="conv-row email-row"><h6 data-role="conversation-row-title">${t}</h6></li>`).join(''));
      await page.evaluate(() => { $('.chat-users-list li[data-id="email:T1"]').addClass('active'); $('.chat_id').val('email:T1'); });
      let release; server.delay['list:'] = new Promise((r) => { release = r; });
      await page.evaluate(() => inboxLive.refreshNow()); await page.waitForTimeout(300);        // the older answer (with T1) is on its way
      await page.evaluate((a) => $('[data-email-action=' + a + ']').trigger('click'), action);
      await page.waitForFunction(() => $('.chat-users-list li[data-id="email:T1"]').length === 0);
      server.delay['list:'] = null; release(); await page.waitForTimeout(500);
      assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id="email:T1"]').length), 0, 'stays removed');
      assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id="email:T2"]').length), 1);
      await page.waitForTimeout(5600);
      assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id="email:T1"]').length), 0, 'and the next poll agrees');
    });

    await scenario(`(thread 3, failure) a failed ${action} keeps the row and the existing failure behaviour; the next list answer is used normally`, async (page, server) => {
      server.emails = ['T1', 'T2']; server.actionFail = true;
      await page.evaluate(() => { window.scope = { filter: 'recents', search: '', channel: 'email', createdFrom: '', createdTo: '' }; });
      await page.evaluate((l) => { $('.chat-users-list').html(l); inboxLive.start(() => window.scope); },
        server.emails.map((t) => `<li data-id="email:${t}" data-channel="email" class="conv-row email-row"><h6 data-role="conversation-row-title">${t}</h6></li>`).join(''));
      await page.evaluate(() => { $('.chat-users-list li[data-id="email:T1"]').addClass('active'); $('.chat_id').val('email:T1'); window.toasts = []; });
      await page.evaluate((a) => $('[data-email-action=' + a + ']').trigger('click'), action);
      await page.waitForTimeout(400);
      assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id="email:T1"]').length), 1, 'the row stays: nothing was changed');
      assert.equal(await page.evaluate(() => window.toasts.length), 1, 'the existing warning toast');
      assert.equal(await page.evaluate(() => $('.chat_id').val()), 'email:T1', 'the thread stays open');
      await refresh(page, server);
      assert.equal(await page.evaluate(() => $('.chat-users-list li[data-id="email:T1"]').length), 1);
    });
  }

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
