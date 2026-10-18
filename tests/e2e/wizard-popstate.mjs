// Regression: a failed save during browser Back/Forward must never discard the screen's unsaved input.
// When `popstate` fires the address bar has already moved but the old screen is still showing; if that screen
// cannot be saved (offline, server error, revision conflict) the wizard must stay on it, keep what was typed, put
// the history position back and offer Retry. Requires a running app + fixture (README.md).
import { launch, login, wizardUrl, check, finish } from './lib.mjs';

const browser = await launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
page.setDefaultTimeout(15000);
await login(page);

const status = (p = page) => p.locator('[data-save-status]').innerText();
const screenKey = (p = page) => p.locator('[data-wizard-screen]').getAttribute('data-screen-key');
const alive = () => page.evaluate(() => window.__alive);
const mark = () => page.evaluate(() => { window.__alive = 'same-document'; });
const waitSaved = (p = page) => p.waitForFunction(() => /Saved/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 10000 });
const waitScreen = (k) => page.waitForFunction((x) => document.querySelector('[data-wizard-screen]')?.getAttribute('data-screen-key') === x, k, { timeout: 12000 });
const waitFailed = () => page.waitForFunction(() => !document.querySelector('[data-wizard-error]')?.classList.contains('d-none') && /Couldn.t save/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 12000 });
const areaRow = (p = page) => p.locator('[data-wizard-screen] [data-repeatable] [data-row] input[type="text"]').first();
const city = () => page.locator('input[name="value[city]"]');
const urlIs = (key) => page.url().endsWith('/' + key);

// -- setup: basics → base location → service areas, all in one document so history has three entries -------
await page.goto(wizardUrl(''), { waitUntil: 'load' });
await mark();
await page.fill('input[name="s[phone][value]"]', '555-0142');
await page.fill('input[name="s[email][value]"]', 'hello@snapbooth.test');
await waitSaved();
await page.click('[data-wizard-continue]'); await waitScreen('base_location');
await page.click('[data-wizard-continue]'); await waitScreen('service_area_cities');
check('walked basics → base location → service areas in-page', (await alive()) === 'same-document');
await areaRow().fill('Saved Baseline, CA');
await waitSaved();

// -- A. offline Back: stay, keep the typed value, history put back --------------------------------------
await areaRow().fill('Typed Offline, CA');
await context.setOffline(true);
await page.goBack();
await waitFailed();
check('A: offline Back stays on the screen being edited', (await screenKey()) === 'service_area_cities');
check('A: the address bar was put back to that screen', urlIs('service_area_cities'), page.url());
check('A: the unsaved input is still there', (await areaRow().inputValue()) === 'Typed Offline, CA');
check('A: "Couldn\'t save" with Retry is shown', (await page.locator('[data-save-status] [data-save-retry]').count()) === 1);
check('A: an explanatory message is shown', /stayed on this screen/.test(await page.locator('[data-wizard-error]').innerText()));
check('A: no document reload happened', (await alive()) === 'same-document');
await context.setOffline(false);
await page.click('[data-save-retry]');
await waitSaved();
await page.goBack(); await waitScreen('base_location');
check('A: after Retry, Back works and moves in-page', urlIs('base_location') && (await alive()) === 'same-document');
await page.goForward(); await waitScreen('service_area_cities');
check('A: the retried value was saved durably', (await areaRow().inputValue()) === 'Typed Offline, CA');

// -- B. offline Forward from an edited earlier screen ----------------------------------------------------
await page.goBack(); await waitScreen('base_location');
await city().fill('Costa Mesa');
await context.setOffline(true);
await page.goForward();
await waitFailed();
check('B: offline Forward stays on the edited screen', (await screenKey()) === 'base_location' && urlIs('base_location'), page.url());
check('B: the unsaved city is kept', (await city().inputValue()) === 'Costa Mesa');
await context.setOffline(false);
await page.click('[data-save-retry]'); await waitSaved();
await page.goForward(); await waitScreen('service_area_cities');
check('B: after Retry, Forward works', urlIs('service_area_cities') && (await alive()) === 'same-document');
await page.goBack(); await waitScreen('base_location');
check('B: the retried city is durable', (await city().inputValue()) === 'Costa Mesa');
await page.goForward(); await waitScreen('service_area_cities');

// -- C. server error (HTTP 500 from the draft endpoint) --------------------------------------------------
await page.route('**/website/build/*/draft', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{"ok":false}' }));
await areaRow().fill('Typed During 500, CA');
await page.goBack();
await waitFailed();
check('C: a server error on Back stays on the screen', (await screenKey()) === 'service_area_cities' && urlIs('service_area_cities'), page.url());
check('C: the unsaved input is kept after a server error', (await areaRow().inputValue()) === 'Typed During 500, CA');
await page.unroute('**/website/build/*/draft');
await page.click('[data-save-retry]'); await waitSaved();

// -- D. an unknown history entry (the page was reloaded, so the wizard has no record of earlier entries) ---
await page.reload({ waitUntil: 'load' });
await mark();
await page.route('**/website/build/*/draft', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{"ok":false}' }));
await areaRow().fill('Typed After Reload, CA');
await page.goBack();
await waitFailed();
await page.waitForTimeout(400);
check('D: after a reload, a failed Back still stays on the edited screen', (await screenKey()) === 'service_area_cities' && urlIs('service_area_cities'), page.url());
check('D: the unsaved input is kept', (await areaRow().inputValue()) === 'Typed After Reload, CA');
check('D: no document reload happened', (await alive()) === 'same-document');
await page.unroute('**/website/build/*/draft');
await page.click('[data-save-retry]'); await waitSaved();
await page.reload({ waitUntil: 'load' });
check('D: the retried value is durable', (await areaRow().inputValue()) === 'Typed After Reload, CA');
await mark();

// -- E. revision conflict: another tab saved the same setup meanwhile -----------------------------------
const other = await context.newPage();
other.setDefaultTimeout(15000);
await other.goto(wizardUrl('/service_area_cities'), { waitUntil: 'load' });
await areaRow(other).fill('Typed In Other Tab, CA');
await waitSaved(other);
await areaRow().fill('Typed In Stale Tab, CA');
await page.goBack();
await page.waitForFunction(() => /another tab/.test(document.querySelector('[data-wizard-error]')?.textContent || ''), null, { timeout: 12000 });
await page.waitForTimeout(300);
check('E: a revision conflict on Back stays on the screen', (await screenKey()) === 'service_area_cities' && urlIs('service_area_cities'), page.url());
check('E: the stale tab\'s unsaved input is kept (not overwritten, not discarded)', (await areaRow().inputValue()) === 'Typed In Stale Tab, CA');
check('E: the other tab\'s newer answer was not overwritten', (await (async () => { await other.reload({ waitUntil: 'load' }); return areaRow(other).inputValue(); })()) === 'Typed In Other Tab, CA');
check('E: no document reload happened', (await alive()) === 'same-document');

await browser.close();
finish();
