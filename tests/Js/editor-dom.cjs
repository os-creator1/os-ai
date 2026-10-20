// Drives the REAL rendered Landing Page editor (HTML from PHP, real scripts) in Chromium with a simulated server.
// usage: node tests/Js/editor-dom.cjs <editor.html> <public-dir>   → prints one JSON object of scenario results.
const path = require('path');
const { pathToFileURL } = require('url');
let chromium;
for (const base of [process.env.PLAYWRIGHT_MODULE, '/opt/node-tools/node_modules/playwright', 'playwright'].filter(Boolean)) {
    try { ({ chromium } = require(base)); break; } catch (e) { /* try next */ }
}
if (!chromium) { console.log(JSON.stringify({ skipped: 'playwright not available' })); process.exit(0); }

const [html, publicDir] = process.argv.slice(2);
const TITLE = '[data-path="copy.home.hero.title"] [data-input]';

async function scenario(browser, name, serverFor, run) {
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
    const server = { state: {}, published: 0, saves: [] };
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.route('**/*', async (route) => {
        const url = route.request().url();
        if (!url.includes('/agency/site/') || route.request().method() !== 'POST') { return route.continue(); }
        const kind = url.split('/agency/site/')[1].split('?')[0];
        const body = JSON.parse(route.request().postData() || '{}');
        const res = await serverFor(kind, body, server);
        if (res === 'abort') { return route.abort('failed'); }
        return route.fulfill({ status: res.status || 200, contentType: 'application/json', body: JSON.stringify(res.body) });
    });
    await page.goto(pathToFileURL(html).href);
    await page.click('[data-step-button="2"]');
    await page.click('li[data-key="hero"] [data-open-section]');
    const out = await run(page, server);
    out.errors = errors;
    await page.context().close();
    return out;
}
const ok = (extra) => ({ status: 200, body: Object.assign({ ok: true, status: 'draft', isLive: false, unpublishedChanges: false, checklist: [], booking: {}, hostedUrl: 'https://example.test/w/x' }, extra || {}) });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
    const browser = await chromium.launch();
    const results = {};

    // P1 — edit again while the first save is still in flight: the latest value must be what the server holds.
    results.rapidEdits = await scenario(browser, 'rapid', async (kind, body, server) => {
        if (kind === 'save') {
            server.saves.push(body.answers['copy.home.hero.title']);
            if (server.saves.length === 1) { await wait(900); }          // first response is slow
            Object.assign(server.state, body.answers);
        }
        return ok();
    }, async (page, server) => {
        await page.fill(TITLE, 'First');
        await wait(950);                                               // debounce fires, request 1 is now in flight
        await page.fill(TITLE, 'First plus the newer edit');
        await wait(2600);                                              // request 1 finishes, debounce for edit 2, request 2
        return { final: server.state['copy.home.hero.title'], saves: server.saves, inputKept: await page.inputValue(TITLE) };
    });

    // P2 — a failing save blocks Publish, keeps the edit on screen and says so.
    results.validationFailureBlocksPublish = await scenario(browser, 'invalid', async (kind, body, server) => {
        if (kind === 'save') { return { status: 422, body: { ok: false, message: 'Use plain text only — no HTML or special characters.' } }; }
        if (kind === 'publish') { server.published++; }
        return ok();
    }, async (page, server) => {
        await page.fill(TITLE, 'Bad <b>title</b>');
        await page.click('#wl-publish');
        await wait(600);
        return { published: server.published, message: (await page.textContent('#wl-publish-result')).trim(), inputKept: await page.inputValue(TITLE), state: (await page.textContent('#wl-save-state')).trim() };
    });

    results.networkFailureBlocksPublish = await scenario(browser, 'network', async (kind, body, server) => {
        if (kind === 'save') { return 'abort'; }
        if (kind === 'publish') { server.published++; }
        return ok();
    }, async (page, server) => {
        await page.fill(TITLE, 'Offline edit');
        await page.click('#wl-publish');
        await wait(600);
        return { published: server.published, message: (await page.textContent('#wl-publish-result')).trim(), inputKept: await page.inputValue(TITLE) };
    });

    // Publishing with a pending (not yet auto-saved) edit saves it first, then publishes.
    results.pendingEditIsSavedThenPublished = await scenario(browser, 'pending', async (kind, body, server) => {
        if (kind === 'save') { Object.assign(server.state, body.answers); }
        if (kind === 'publish') { server.published++; server.stateAtPublish = Object.assign({}, server.state); }
        return ok({ isLive: kind === 'publish' });
    }, async (page, server) => {
        await page.fill(TITLE, 'Typed just before publishing');
        await page.click('#wl-publish');                                // well inside the 700ms debounce
        await wait(800);
        return { published: server.published, atPublish: server.stateAtPublish && server.stateAtPublish['copy.home.hero.title'] };
    });

    // Save draft never says saved before persistence succeeds, and reports failure.
    results.saveDraft = await scenario(browser, 'draft', async (kind, body, server) => {
        if (kind === 'save') { await wait(150); if (/FAIL/.test(JSON.stringify(body))) { return { status: 500, body: { ok: false, message: 'Server error.' } }; } Object.assign(server.state, body.answers); }
        return ok();
    }, async (page, server) => {
        await page.fill(TITLE, 'Draft title');
        await page.click('#wl-save-draft');
        await wait(60);
        const during = (await page.textContent('#wl-save-state')).trim();
        await wait(2500);
        const after = (await page.textContent('#wl-save-state')).trim();
        await page.fill(TITLE, 'FAIL this one');
        await page.click('#wl-save-draft');
        await wait(2500);
        return { during, after, failed: (await page.textContent('#wl-save-state')).trim(), inputKept: await page.inputValue(TITLE) };
    });

    // Reset vs pending autosave: the reset must win and stay persisted after the reload it triggers.
    const resetServer = (slowFirstSave) => async (kind, body, server) => {
        server.events = server.events || [];
        if (kind === 'save') {
            server.events.push('save:' + JSON.stringify(body.answers['copy.home.hero.title'] ?? null));
            if (slowFirstSave && server.events.filter((e) => e.startsWith('save')).length === 1) { await wait(900); }
            Object.assign(server.state, body.answers);
        }
        if (kind === 'reset-group') { server.events.push('reset'); delete server.state['copy.home.hero.title']; }
        return ok();
    };
    const resetRun = async (page, server, waitBeforeReset) => {
        let loads = 0; page.on('load', () => { loads++; });
        page.on('dialog', (d) => d.accept());
        await page.fill(TITLE, 'Edited then reset');
        if (waitBeforeReset) { await wait(waitBeforeReset); }
        await page.click('li[data-key="hero"] [data-reset-group="hero"]');
        await wait(2500);                                              // longer than the debounce: a leaked timer would fire now
        return { events: server.events, finalHasOverride: 'copy.home.hero.title' in server.state, reloaded: loads >= 1 };
    };
    results.resetBeatsPendingDebounce = await scenario(browser, 'reset-pending', resetServer(false), (page, server) => resetRun(page, server, 0));
    results.resetBeatsInFlightSave = await scenario(browser, 'reset-inflight', resetServer(true), (page, server) => resetRun(page, server, 1000));

    console.log(JSON.stringify(results));
    await browser.close();
})();
