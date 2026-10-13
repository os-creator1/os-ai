// Browser walkthrough of the setup wizard's in-page navigation and durable saving.
// Requires a running app + the fixture from tests/Feature/Website/E2e/WizardBrowserFixtureTest.php (see README.md).
import { launch, login, wizardUrl, check, finish } from './lib.mjs';

const browser = await launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
await login(page);

const status = () => page.locator('[data-save-status]').innerText();
const heading = () => page.locator('[data-wizard-heading], [data-wizard-screen] h4').first().innerText();
const navEntries = () => page.evaluate(() => performance.getEntriesByType('navigation').length);
const screenKey = () => page.locator('[data-wizard-screen]').getAttribute('data-screen-key');
const val = (sel) => page.locator(sel).inputValue();

await page.goto(wizardUrl(''), { waitUntil: 'load' });
await page.evaluate(() => { window.__alive = 'same-document'; });
const firstScreen = await screenKey();

// -- debounce autosave, then a hard refresh keeps what was typed -------------------------------
await page.fill('input[name="s[phone][value]"]', '555-0142');
await page.waitForFunction(() => /Saved/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 8000 });
check('autosave shows Saved after typing', /Saved/.test(await status()));
await page.reload({ waitUntil: 'load' });
check('a refresh keeps the autosaved phone number', (await val('input[name="s[phone][value]"]')) === '555-0142');
await page.evaluate(() => { window.__alive = 'same-document'; });

// -- Continue moves to the next screen WITHOUT a document reload ------------------------------
await page.fill('input[name="s[email][value]"]', 'hello@snapbooth.test');
await page.selectOption('select[name="s[primary_cta][value]"]', 'quote_request');
await page.click('[data-wizard-continue]');
await page.waitForFunction((k) => document.querySelector('[data-wizard-screen]').getAttribute('data-screen-key') !== k, firstScreen, { timeout: 10000 });
check('Continue changed the screen', (await screenKey()) !== firstScreen, await screenKey());
check('Continue did not reload the document', (await page.evaluate(() => window.__alive)) === 'same-document');
check('URL followed the screen (history API)', page.url().endsWith('/' + (await screenKey())));
check('Continue produced a single navigation entry', (await navEntries()) === 1);

// -- service areas: individual rows, add / reorder / remove persist ---------------------------
const rows = () => page.locator('[data-wizard-screen] [data-row] input[type="text"]');
await rows().first().fill('Irvine, CA');
for (const city of ['Anaheim, CA', 'Newport Beach, CA', 'Tustin, CA', 'Orange, CA']) {
  await page.click('[data-add-row]');
  await rows().last().fill(city);
}
await page.locator('[data-row]').nth(4).locator('[data-move-row="up"]').click(); // Orange above Tustin
await page.locator('[data-row]').nth(1).locator('[data-remove-row]').click();     // remove Anaheim
await page.waitForFunction(() => /Saved/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 8000 });
const expected = ['Irvine, CA', 'Newport Beach, CA', 'Orange, CA', 'Tustin, CA'];
check('rows hold the edited list in order', JSON.stringify(await rows().evaluateAll((els) => els.map((e) => e.value))) === JSON.stringify(expected));

// -- Back keeps previous values; Forward restores the list -------------------------------------
await page.click('[data-wizard-back] button');
await page.waitForFunction((k) => document.querySelector('[data-wizard-screen]').getAttribute('data-screen-key') === k, firstScreen, { timeout: 10000 });
check('Back returned without reloading', (await page.evaluate(() => window.__alive)) === 'same-document');
check('Back kept the phone number', (await val('input[name="s[phone][value]"]')) === '555-0142');
check('Back kept the email', (await val('input[name="s[email][value]"]')) === 'hello@snapbooth.test');

await page.goBack();                                      // browser Back → the service-area screen
await page.waitForFunction(() => document.querySelector('[data-wizard-screen]').getAttribute('data-screen-key') !== 'business_name', null, { timeout: 10000 }).catch(() => {});
check('browser Back/Forward works in-page', (await page.evaluate(() => window.__alive)) === 'same-document');

// -- offline: no false "Saved", nothing lost, no silent navigation -----------------------------
await page.goto(wizardUrl('/' + firstScreen), { waitUntil: 'load' });
await page.evaluate(() => { window.__alive = 'same-document'; });
await context.setOffline(true);
await page.fill('input[name="s[phone][value]"]', '555-0199');
await page.waitForFunction(() => /Couldn.t save/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 8000 });
check('offline save reports "Couldn\'t save"', /Couldn.t save/.test(await status()));
await page.click('[data-wizard-continue]');
await page.waitForTimeout(800);
check('Continue while offline does not navigate away', (await screenKey()) === firstScreen);
check('the typed value is still in the field', (await val('input[name="s[phone][value]"]')) === '555-0199');
check('an explanatory message is shown', await page.locator('[data-wizard-error]:not(.d-none)').count() === 1);
await context.setOffline(false);
await page.click('[data-save-retry]');
await page.waitForFunction(() => /Saved/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 8000 });
check('Retry saves once back online', /Saved/.test(await status()));
await page.reload({ waitUntil: 'load' });
check('the retried value is durable', (await val('input[name="s[phone][value]"]')) === '555-0199');

// -- rapid Continue/Back clicks never lose or reorder answers -----------------------------------
await page.evaluate(() => { window.__alive = 'same-document'; });
await page.fill('input[name="s[phone][value]"]', '555-0200');
await page.click('[data-wizard-continue]');
await page.waitForFunction((k) => document.querySelector('[data-wizard-screen]').getAttribute('data-screen-key') !== k, firstScreen, { timeout: 10000 });
await page.click('[data-wizard-back] button');
await page.waitForFunction((k) => document.querySelector('[data-wizard-screen]').getAttribute('data-screen-key') === k, firstScreen, { timeout: 10000 });
check('after a quick forward/back the latest phone value is shown', (await val('input[name="s[phone][value]"]')) === '555-0200');
check('and the document was never reloaded', (await page.evaluate(() => window.__alive)) === 'same-document');

await browser.close();
finish();
