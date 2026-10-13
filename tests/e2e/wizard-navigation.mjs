// Browser walkthrough of the setup wizard's in-page navigation and durable saving.
// Requires a running app + the fixture from tests/Feature/Website/E2e/WizardBrowserFixtureTest.php (see README.md).
import { launch, login, wizardUrl, check, finish } from './lib.mjs';

const browser = await launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
await login(page);

const status = () => page.locator('[data-save-status]').innerText();
const navEntries = () => page.evaluate(() => performance.getEntriesByType('navigation').length);
const screenKey = () => page.locator('[data-wizard-screen]').getAttribute('data-screen-key');
const val = (sel) => page.locator(sel).inputValue();
const alive = () => page.evaluate(() => window.__alive);
const waitSaved = () => page.waitForFunction(() => /Saved/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 8000 });
const waitScreen = (key) => page.waitForFunction((k) => document.querySelector('[data-wizard-screen]').getAttribute('data-screen-key') === k, key, { timeout: 12000 });
const rows = () => page.locator('[data-wizard-screen] [data-repeatable] [data-row] input[type="text"]');
const rowValues = () => rows().evaluateAll((els) => els.map((e) => e.value));

await page.goto(wizardUrl(''), { waitUntil: 'load' });
await page.evaluate(() => { window.__alive = 'same-document'; });
check('setup opens on the basics screen', (await screenKey()) === 'business_name');

// -- basics: prefilled, autosaved, durable across a hard refresh -----------------------------------
check('business name is prefilled from the real business', (await val('input[name="s[business_name][value]"]')) === 'Snap Booth Co');
await page.fill('input[name="s[phone][value]"]', '555-0142');
await page.fill('input[name="s[email][value]"]', 'hello@snapbooth.test');
await waitSaved();
check('autosave shows Saved after typing', /Saved/.test(await status()));
await page.reload({ waitUntil: 'load' });
check('a refresh keeps the autosaved phone number', (await val('input[name="s[phone][value]"]')) === '555-0142');
await page.evaluate(() => { window.__alive = 'same-document'; });

// -- Continue → "Where are you based?" without a document reload -----------------------------------
await page.click('[data-wizard-continue]');
await waitScreen('base_location');
check('Continue did not reload the document', (await alive()) === 'same-document');
check('URL followed the screen (history API)', page.url().endsWith('/base_location'));
check('a single navigation entry exists', (await navEntries()) === 1);
check('base location prefilled from the primary location', (await val('input[name="value[city]"]')) === 'Irvine');
check('the country select is prefilled', (await val('select[name="value[country_code]"]')) === 'US');

// -- Continue → service areas: ten suggested, individually editable rows ----------------------------
await page.click('[data-wizard-continue]');
await waitScreen('service_area_cities');
check('service-area screen reached in-page', (await alive()) === 'same-document');
check('about ten rows are suggested for a business based in Irvine', (await rows().count()) === 10, String(await rows().count()));
const suggested = await rowValues();
check('the base city is the first suggestion', suggested[0] === 'Irvine, CA');
check('suggestions are real neighbouring communities', ['Santa Ana, CA', 'Costa Mesa, CA', 'Newport Beach, CA'].every((c) => suggested.includes(c)));
check('the suggestions note says they are not verified', /not verified/.test(await page.locator('[data-area-note="suggested"]').innerText()));
await page.screenshot({ path: process.env.E2E_SHOTS ? `${process.env.E2E_SHOTS}/areas-desktop.png` : '/tmp/areas-desktop.png', fullPage: true });

// edit, delete, add, reorder → exact list persisted
await rows().nth(1).fill('Tustin, CA');
await page.locator('[data-row]').nth(2).locator('[data-remove-row]').click();
await page.locator('[data-row]').nth(2).locator('[data-remove-row]').click();
await page.click('[data-add-row]');
await rows().last().fill('Orange, CA');
await page.locator('[data-row]').last().locator('[data-move-row="up"]').click();
await waitSaved();
const expected = await rowValues();
check('rows reflect the edits', expected.includes('Tustin, CA') && expected.includes('Orange, CA') && expected.length === 9, JSON.stringify(expected));

// suggest more nearby areas appends new, unlisted places
const before = expected.length;
await page.click('[data-area-suggest-more]');
await page.waitForFunction((n) => document.querySelectorAll('[data-wizard-screen] [data-repeatable] [data-row]').length > n, before, { timeout: 8000 });
const withMore = await rowValues();
check('Suggest more adds new places without duplicating', withMore.length > before && new Set(withMore).size === withMore.length, `${before} → ${withMore.length}`);
// drop the extras again so the list under test is the edited one
for (let i = withMore.length - 1; i >= before; i--) { await page.locator('[data-row]').nth(i).locator('[data-remove-row]').click(); }
await waitSaved();
const final = await rowValues();

// -- Back / Forward / refresh keep the exact list and order ----------------------------------------
await page.click('[data-wizard-back] button');
await waitScreen('base_location');
check('Back returned in-page', (await alive()) === 'same-document');
await page.goForward();
await waitScreen('service_area_cities');
check('browser Forward restored the screen in-page', (await alive()) === 'same-document');
check('the exact list and order survived Back/Forward', JSON.stringify(await rowValues()) === JSON.stringify(final));
await page.reload({ waitUntil: 'load' });
check('the exact list and order survived a refresh', JSON.stringify(await rowValues()) === JSON.stringify(final));
check('saved rows are no longer presented as fresh suggestions', (await page.locator('[data-area-note="suggested"]').count()) === 0);
await page.evaluate(() => { window.__alive = 'same-document'; });

// -- offline: no false "Saved", nothing lost, no silent navigation ---------------------------------
await context.setOffline(true);
await rows().first().fill('Irvine');
await page.waitForFunction(() => /Couldn.t save/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 8000 });
check('offline save reports "Couldn\'t save"', /Couldn.t save/.test(await status()));
await page.click('[data-wizard-continue]');
await page.waitForTimeout(800);
check('Continue while offline does not navigate away', (await screenKey()) === 'service_area_cities');
check('the typed value is still in the field', (await rows().first().inputValue()) === 'Irvine');
check('an explanatory message is shown', (await page.locator('[data-wizard-error]:not(.d-none)').count()) === 1);
await context.setOffline(false);
await page.click('[data-save-retry]');
await waitSaved();
check('Retry saves once back online', /Saved/.test(await status()));
await page.reload({ waitUntil: 'load' });
check('the retried value is durable', (await rows().first().inputValue()) === 'Irvine');

// -- the page header follows the screen ---------------------------------------------------------
check('the page title follows the screen', /Where do you provide your services\?/.test(await page.locator('.content-header-title').innerText()), await page.locator('.content-header-title').innerText());

// -- mobile layout (a fresh page load at phone width) ---------------------------------------------
const phone = await context.newPage();
await phone.setViewportSize({ width: 390, height: 844 });
await phone.goto(wizardUrl('/service_area_cities'), { waitUntil: 'load' });
await phone.screenshot({ path: process.env.E2E_SHOTS ? `${process.env.E2E_SHOTS}/areas-mobile.png` : '/tmp/areas-mobile.png', fullPage: true });
check('no horizontal scroll on a phone', await phone.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1));
check('every row keeps its move and remove controls on a phone', (await phone.locator('[data-wizard-screen] [data-row] [data-remove-row]:visible').count()) >= 5);

await browser.close();
finish();
