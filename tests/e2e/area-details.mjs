// Browser walkthrough of the optional per-area local details inside the service-area step: add details, upload photos
// LATER (the gallery step comes after), come back, pick a photo, save, Next/Back, reload, reorder and remove.
// Requires a running app + the fixture from tests/Feature/Website/E2e/WizardBrowserFixtureTest.php (see README.md) and a
// folder of three or more sample photos (IMG_DIR, default /tmp/claude-0/imgs: event-1.jpg ... event-3.jpg).
import { launch, login, wizardUrl, check, finish } from './lib.mjs';

const IMG = process.env.IMG_DIR || '/tmp/claude-0/imgs';
const browser = await launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
page.setDefaultTimeout(20000);
await login(page);

const status = () => page.locator('[data-save-status]').innerText();
const waitSaved = () => page.waitForFunction(() => /Saved/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 10000 }).catch(async (e) => { console.log('STATUS AT TIMEOUT:', JSON.stringify(await page.locator('[data-save-status]').innerText())); throw e; });
const waitScreen = (k) => page.waitForFunction((x) => document.querySelector('[data-wizard-screen]')?.getAttribute('data-screen-key') === x, k, { timeout: 20000 });
const rowValues = () => page.locator('[data-wizard-screen] [data-repeatable] [data-row] input[type="text"]').evaluateAll((els) => els.map((e) => e.value));
const row = async (name) => page.locator('[data-wizard-screen] [data-row]').nth((await rowValues()).indexOf(name));
const openPanel = async (name) => {
  const panel = (await row(name)).locator('[data-area-detail-panel]');
  if (!(await panel.evaluate((el) => el.open))) { await panel.locator('summary').click(); }
  return panel;
};
const savedJson = async (name) => (await row(name)).locator('[data-area-details-json]').inputValue();

// -- reach the service-area screen -----------------------------------------------------------------
await page.goto(wizardUrl(''), { waitUntil: 'load' });
await page.fill('input[name="s[phone][value]"]', '555-0142');
await page.fill('input[name="s[email][value]"]', 'hello@snapbooth.test');
await waitSaved();
await page.click('[data-wizard-continue]');
await waitScreen('base_location');
await page.click('[data-wizard-continue]');
await waitScreen('service_area_cities');

// keep three areas: the base city, Tustin and Orange
while ((await rowValues()).length > 3) { await page.locator('[data-wizard-screen] [data-row]').last().locator('[data-remove-row]').click(); }
const names = await rowValues();
await page.locator('[data-wizard-screen] [data-row] input[type="text"]').nth(1).fill('Tustin, CA');
await page.locator('[data-wizard-screen] [data-row] input[type="text"]').nth(2).fill('Orange, CA');
check('three service areas to work with', names.length === 3, JSON.stringify(await rowValues()));

// -- first-time owner: no photos exist yet, and the panel says where they come from ------------------
let tustin = await openPanel('Tustin, CA');
check('the panel is collapsed until opened and says it is optional', /optional/i.test(await (await row('Tustin, CA')).locator('summary').innerText()));
check('with no photos yet, the panel explains the Gallery step and the Edit link on Review', /Gallery step/.test(await tustin.locator('[data-area-photo-hint]').innerText()) && /Review/.test(await tustin.locator('[data-area-photo-hint]').innerText()));
check('example text is a placeholder, not a value', (await tustin.locator('[data-area-field="venues"]').inputValue()) === '' && /e\.g\./.test((await tustin.locator('[data-area-field="venues"]').getAttribute('placeholder')) || ''));
check('nothing is saved from an untouched panel', (await savedJson('Orange, CA')) === '');

await tustin.locator('[data-area-field="venues"]').fill('The Tustin Hangar\nOld Town Courtyard');
await tustin.locator('[data-area-field="logistics"]').fill('Ten minutes from our Irvine studio, no travel fee.');
await waitSaved();
check('typed details are serialised with the row', JSON.parse(await savedJson('Tustin, CA')).venues.includes('Tustin Hangar'));
check('the row shows a Details added badge', await tustin.locator('[data-area-detail-badge]').isVisible());

// -- upload photos in the (later) Gallery step, then come back ---------------------------------------
await page.goto(wizardUrl('/gallery'), { waitUntil: 'load' });
await page.locator('[data-gallery-input]').setInputFiles([1, 2, 3].map((n) => `${IMG}/event-${n}.jpg`));
await page.waitForFunction(() => document.querySelectorAll('[data-gallery-card]').length >= 3, null, { timeout: 40000 });
// Let the gallery screen's own save settle before leaving by URL (an in-wizard Back flushes it first).
await waitSaved().catch(() => {});
await page.waitForLoadState('networkidle');
await page.goto(wizardUrl('/service_area_cities'), { waitUntil: 'load' });
tustin = await openPanel('Tustin, CA');
check('returning keeps the typed venues and logistics', /Tustin Hangar/.test(await tustin.locator('[data-area-field="venues"]').inputValue()) && /no travel fee/.test(await tustin.locator('[data-area-field="logistics"]').inputValue()));
check('the uploaded gallery photos can now be assigned', (await tustin.locator('[data-area-photo]').count()) === 3);
check('the no-photos hint is gone', (await tustin.locator('[data-area-photo-hint]').count()) === 0);

await tustin.locator('[data-area-photo]').nth(1).check();
await waitSaved();
const chosen = await tustin.locator('[data-area-photo]').nth(1).inputValue();
check('the chosen photo is in the row payload', JSON.parse(await savedJson('Tustin, CA')).photos[0] === chosen);

// -- Next, Back, reload -----------------------------------------------------------------------------
await page.click('[data-wizard-continue]');
await page.waitForFunction(() => document.querySelector('[data-wizard-screen]')?.getAttribute('data-screen-key') !== 'service_area_cities', null, { timeout: 20000 });
await page.click('[data-wizard-back] button');
await waitScreen('service_area_cities');
tustin = await openPanel('Tustin, CA');
check('Back restores venues, logistics and the ticked photo', /Old Town Courtyard/.test(await tustin.locator('[data-area-field="venues"]').inputValue()) && (await tustin.locator('[data-area-photo]').nth(1).isChecked()) && !(await tustin.locator('[data-area-photo]').nth(0).isChecked()));
await page.reload({ waitUntil: 'load' });
tustin = await openPanel('Tustin, CA');
check('a hard reload restores the same details and photo', /Old Town Courtyard/.test(await tustin.locator('[data-area-field="venues"]').inputValue()) && (await tustin.locator('[data-area-photo]').nth(1).isChecked()));
check('an area with no details stayed empty', (await savedJson('Orange, CA')) === '');

// -- reorder: details travel with the row ------------------------------------------------------------
await (await row('Tustin, CA')).locator('[data-move-row="up"]').click();
await waitSaved();
const reordered = await rowValues();
check('Tustin moved up', reordered.indexOf('Tustin, CA') === 0, JSON.stringify(reordered));
await page.reload({ waitUntil: 'load' });
tustin = await openPanel('Tustin, CA');
check('after reorder + reload the details are still on Tustin', /Tustin Hangar/.test(await tustin.locator('[data-area-field="venues"]').inputValue()) && (await tustin.locator('[data-area-photo]').nth(1).isChecked()));
check('and Orange did not inherit them', (await savedJson('Orange, CA')) === '');

// -- remove: the row goes, nothing else changes ------------------------------------------------------
await (await row('Orange, CA')).locator('[data-remove-row]').click();
await waitSaved();
await page.reload({ waitUntil: 'load' });
check('Orange is gone after reload', !(await rowValues()).includes('Orange, CA'));
tustin = await openPanel('Tustin, CA');
check('Tustin still has its details', /Tustin Hangar/.test(await tustin.locator('[data-area-field="venues"]').inputValue()));
await tustin.locator('[data-area-photo]').nth(1).uncheck();
await tustin.locator('[data-area-field="venues"]').fill('');
await tustin.locator('[data-area-field="logistics"]').fill('');
await waitSaved();
check('clearing every field clears the row payload and badge', (await savedJson('Tustin, CA')) === '' && !(await tustin.locator('[data-area-detail-badge]').isVisible()));
check('no save error or conflict was shown', !/error|conflict|couldn/i.test(await status()), await status());
await page.screenshot({ path: process.env.E2E_SHOTS ? `${process.env.E2E_SHOTS}/area-details.png` : '/tmp/area-details.png', fullPage: true });

await browser.close();
finish();
