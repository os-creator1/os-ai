// Browser regression: a service-area row's local details (venues, logistics, ticked photos) belong to ONE place and must
// never become claims about another. Covers removing the last row, naming a different place in the retained blank row,
// "replace with fresh suggestions", renaming (a real change vs. a formatting correction), autosave + reload and reorder.
// Requires the fixture + app described in README.md and IMG_DIR with event-1.jpg ... event-3.jpg (any small distinct JPEGs).
import { launch, login, wizardUrl, check, finish } from './lib.mjs';

const IMG = process.env.IMG_DIR || '/tmp/claude-0/imgs';
const browser = await launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
page.setDefaultTimeout(20000);
page.on('dialog', (dialog) => dialog.accept());
await login(page);

const waitSaved = () => page.waitForFunction(() => /Saved/.test(document.querySelector('[data-save-status]').innerText), null, { timeout: 10000 });
const waitScreen = (k) => page.waitForFunction((x) => document.querySelector('[data-wizard-screen]')?.getAttribute('data-screen-key') === x, k, { timeout: 20000 });
const rowValues = () => page.locator('[data-wizard-screen] [data-repeatable] [data-row] > input[type="text"]').evaluateAll((els) => els.map((e) => e.value));
const rows = () => page.locator('[data-wizard-screen] [data-repeatable] [data-row]');
const rowAt = (i) => rows().nth(i);
const nameInput = (i) => rowAt(i).locator('> input[type="text"]');
const panelAt = async (i) => {
  const panel = rowAt(i).locator('[data-area-detail-panel]');
  if (!(await panel.evaluate((el) => el.open))) { await panel.locator('summary').click(); }
  return panel;
};
const json = (i) => rowAt(i).locator('[data-area-details-json]').inputValue();
// Everything the panel shows and submits for a row, in one object.
const state = async (i) => {
  const panel = await panelAt(i);
  return panel.evaluate((p) => ({
    venues: p.querySelector('[data-area-field="venues"]').value,
    logistics: p.querySelector('[data-area-field="logistics"]').value,
    ticked: p.querySelectorAll('[data-area-photo]:checked').length,
    disabled: p.querySelectorAll('[data-area-photo]:disabled').length,
    counter: p.querySelector('[data-area-photo-count]').textContent.trim(),
    badgeHidden: p.querySelector('[data-area-detail-badge]').classList.contains('d-none'),
    hidden: p.closest('[data-row]').querySelector('[data-area-details-json]').value,
  }));
};
const isEmpty = (s) => s.venues === '' && s.logistics === '' && s.ticked === 0 && s.disabled === 0 && s.counter === '0 of 6 photos selected' && s.badgeHidden && s.hidden === '';
// What the form would submit for every row's details, straight from the DOM.
const submittedDetails = () => page.evaluate(() => Array.from(new FormData(document.querySelector('form[id^="answer-form-"]')).getAll('area_details[]')));
const fillDetails = async (i, venues, logistics, photoIdx = []) => {
  const panel = await panelAt(i);
  await panel.locator('[data-area-field="venues"]').fill(venues);
  await panel.locator('[data-area-field="logistics"]').fill(logistics);
  for (const n of photoIdx) { await panel.locator('[data-area-photo]').nth(n).check(); }
};

// -- reach the service-area screen with three gallery photos available ---------------------------------------------
await page.goto(wizardUrl(''), { waitUntil: 'load' });
await page.fill('input[name="s[phone][value]"]', '555-0142');
await page.fill('input[name="s[email][value]"]', 'hello@snapbooth.test');
await waitSaved();
await page.click('[data-wizard-continue]');
await waitScreen('base_location');
await page.click('[data-wizard-continue]');
await waitScreen('service_area_cities');
await page.goto(wizardUrl('/gallery'), { waitUntil: 'load' });
await page.locator('[data-gallery-input]').setInputFiles([1, 2, 3].map((n) => `${IMG}/event-${n}.jpg`));
await page.waitForFunction(() => document.querySelectorAll('[data-gallery-card]').length >= 3, null, { timeout: 40000 });
await waitSaved().catch(() => {});
await page.waitForLoadState('networkidle');
await page.goto(wizardUrl('/service_area_cities'), { waitUntil: 'load' });

// -- 1. City A, details and photos; remove the last row; City B in the retained blank row ----------------------------
while ((await rowValues()).length > 1) { await rows().last().locator('[data-remove-row]').click(); }
await nameInput(0).fill('Tustin, CA');
await fillDetails(0, 'The Tustin Hangar', 'Ten minutes from our studio.', [0, 1]);
await waitSaved();
let a = await state(0);
check('City A has venues, logistics, two photos, a badge and a JSON payload', a.venues !== '' && a.ticked === 2 && a.counter === '2 of 6 photos selected' && !a.badgeHidden && a.hidden !== '', JSON.stringify(a));

await rowAt(0).locator('[data-remove-row]').click();
check('removing the last row keeps one blank row', (await rows().count()) === 1 && (await nameInput(0).inputValue()) === '');
check('the blank row is completely cleared: fields, JSON, photos, counter, badge', isEmpty(await state(0)), JSON.stringify(await state(0)));

await nameInput(0).fill('Irvine, CA');
await waitSaved();
check('City B (the retained row) submits no City A details or photos', (await submittedDetails()).every((v) => v === ''), JSON.stringify(await submittedDetails()));
await page.reload({ waitUntil: 'load' });
check('after autosave and reload City B did not inherit City A’s facts', (await rowValues())[0] === 'Irvine, CA' && isEmpty(await state(0)), JSON.stringify(await state(0)));

// -- 2. formatting corrections keep the details; a different place drops them ------------------------------------------
await fillDetails(0, 'Irvine Spectrum ballroom', 'Twenty minutes away.', [2]);
await waitSaved();
await nameInput(0).fill('  irvine,   CA ');
await waitSaved();
a = await state(0);
check('a formatting-only change (case, spacing) keeps the confirmed details', a.venues === 'Irvine Spectrum ballroom' && a.ticked === 1 && a.hidden !== '', JSON.stringify(a));
await nameInput(0).fill('Costa Mesa, CA');
await waitSaved();
check('naming a different place drops the old facts everywhere', isEmpty(await state(0)) && (await submittedDetails()).every((v) => v === ''), JSON.stringify(await state(0)));
await nameInput(0).fill('Irvine, CA');
check('typing the old name back does not resurrect them', isEmpty(await state(0)));

// -- 3. "Replace with fresh suggestions": nothing transfers to the new first row ---------------------------------------
await fillDetails(0, 'Irvine Spectrum ballroom', 'Twenty minutes away.', [0, 2]);
await waitSaved();
const suggestUrl = await page.locator('[data-area-list]').getAttribute('data-suggest-url');
await page.route(`${suggestUrl}*`, (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ resolved: true, suggestions: [{ label: 'Anaheim, CA' }, { label: 'Orange, CA' }] }) }));
// The refresh control is only rendered after a base-location change; add it the same way that notice does.
await page.evaluate(() => {
  const button = document.createElement('button');
  button.type = 'button';
  button.setAttribute('data-area-refresh', '');
  button.id = 'e2e-refresh';
  button.textContent = 'Replace';
  document.querySelector('[data-area-list]').appendChild(button);
});
await page.click('#e2e-refresh');
await page.waitForFunction(() => Array.from(document.querySelectorAll('[data-repeatable] [data-row] > input[type="text"]')).some((e) => e.value === 'Anaheim, CA'));
check('the list now shows the fresh suggestions', JSON.stringify(await rowValues()) === JSON.stringify(['Anaheim, CA', 'Orange, CA']), JSON.stringify(await rowValues()));
check('the first row (kept by the replace) has none of Irvine’s facts', isEmpty(await state(0)), JSON.stringify(await state(0)));
check('the new second row is empty too', isEmpty(await state(1)));
await waitSaved();
await page.reload({ waitUntil: 'load' });
check('after autosave and reload no stale facts remain', isEmpty(await state(0)) && isEmpty(await state(1)) && (await submittedDetails()).every((v) => v === ''));

// -- 4. reorder and retaining the same place keep the right details ------------------------------------------------------
await fillDetails(0, 'Anaheim Convention Center', 'Thirty minutes away.', [1]);
await waitSaved();
await rowAt(0).locator('[data-move-row="down"]').click();
await waitSaved();
await page.reload({ waitUntil: 'load' });
check('after a reorder and reload Anaheim (now second) keeps its own details', (await rowValues()).join('|') === 'Orange, CA|Anaheim, CA');
const orange = await state(0);
const anaheim = await state(1);
check('the other place did not inherit them', isEmpty(orange), JSON.stringify(orange));
check('Anaheim keeps venues, logistics, its photo, counter, badge and JSON', anaheim.venues === 'Anaheim Convention Center' && anaheim.ticked === 1 && anaheim.counter === '1 of 6 photos selected' && !anaheim.badgeHidden && anaheim.hidden !== '', JSON.stringify(anaheim));

await browser.close();
finish();
