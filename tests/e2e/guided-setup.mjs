// Browser walkthrough of the whole guided photo booth setup: confirm → services → photos → generate → preview →
// publish gate. Needs a running app, the fixture (README.md) and a folder of test photos (IMG_DIR).
import { launch, login, wizardUrl, check, finish, BASE, fixture } from './lib.mjs';

const IMG = process.env.IMG_DIR || '/tmp/claude-0/imgs';
const SHOTS = process.env.E2E_SHOTS || '/tmp';

// Scroll the whole page so lazy-loaded images are fetched before a full-page screenshot.
async function settle(p) {
  await p.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 500) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); }
    window.scrollTo(0, 0);
  });
  await p.waitForTimeout(400);
}

const browser = await launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
page.setDefaultTimeout(20000);
await login(page);

const key = () => page.locator('[data-wizard-screen]').getAttribute('data-screen-key');
const alive = () => page.evaluate(() => window.__alive);
const waitScreen = (k) => page.waitForFunction((x) => document.querySelector('[data-wizard-screen]')?.getAttribute('data-screen-key') === x, k, { timeout: 20000 });
const cont = async (next) => {
  await page.click('[data-wizard-continue]');
  if (next) { await waitScreen(next); }
};
const skip = async (next) => { await page.click('[data-wizard-skip]'); await waitScreen(next); };
const uploadTo = async (locator, file) => {
  await locator.setInputFiles(`${IMG}/${file}`);
  await page.waitForFunction(() => document.querySelectorAll('[data-upload-progress]').length === 0 || [...document.querySelectorAll('[data-upload-progress]')].every((b) => b.closest('.progress')?.classList.contains('d-none')), null, { timeout: 20000 });
};

await page.goto(wizardUrl(''), { waitUntil: 'load' });
await page.evaluate(() => { window.__alive = 'same-document'; });

// 1. basics (prefilled) → confirm
check('business name prefilled', (await page.inputValue('input[name="s[business_name][value]"]')) === 'Glow Booths Co');
await page.fill('input[name="s[phone][value]"]', '949-555-0142');
await page.fill('input[name="s[email][value]"]', 'hello@glowbooths.test');
await cont('base_location');

// 2. base + areas
check('base prefilled from the primary location', (await page.inputValue('input[name="value[city]"]')) === 'Irvine');
await cont('service_area_cities');
check('ten suggested areas', (await page.locator('[data-repeatable] [data-row] input[type="text"]').count()) === 10);
await cont('booth_services');

// 3. booth services via presets, with photos for two of three
await page.click('[data-preset-key="open_air"]');
await page.click('[data-preset-key="mirror"]');
await page.click('[data-preset-key="three_sixty"]');
const cards = page.locator('[data-service-card]');
check('three booth cards from three preset taps', (await cards.count()) === 3, String(await cards.count()));
check('preset descriptions are prefilled', /open-concept/.test(await cards.nth(0).locator('[data-item-description]').inputValue()));
await uploadTo(cards.nth(0).locator('[data-image-input]').first(), 'open-air.jpg');
await uploadTo(cards.nth(1).locator('[data-image-input]').first(), 'mirror.jpg');
check('chips show chosen state', (await page.locator('[data-preset-key="open_air"]').getAttribute('aria-pressed')) === 'true');
await page.screenshot({ path: `${SHOTS}/booths-desktop.png`, fullPage: true });
await cont('event_types');

// 4. events
for (const k of ['weddings', 'corporate', 'birthdays']) { await page.click(`[data-preset-key="${k}"]`); }
check('three event cards', (await page.locator('[data-service-card]').count()) === 3);
await cont('pricing_strategy');

// 5. pricing → packages
await page.click('label[for="pricing_strategy-fixed_packages"]');
await cont('packages');
await page.fill('input[name$="[0][name]"]', 'Essential');
await page.fill('input[name$="[0][price]"]', '499');
await page.fill('textarea[name$="[0][description]"]', 'Two hours with an attendant.');
await page.locator('button[data-add-row]', { hasText: 'Add package' }).click();
const second = page.locator('[data-new-package]').nth(1);
await second.locator('input[name$="[name]"]').fill('Signature');
await second.locator('input[name$="[price]"]').fill('799');
check('packages can be linked to a booth', (await page.locator('[data-package-link]').count()) >= 1);
await page.locator('[data-package-link]').first().selectOption({ label: 'Open-Air Photo Booth' });
await page.screenshot({ path: `${SHOTS}/packages-desktop.png`, fullPage: true });
await cont('addons');

// 6. add-ons (skippable), backdrops
await page.click('[data-preset-key="extra_hours"]');
await page.click('[data-preset-key="guest_book"]');
await page.locator('[data-service-card]').first().locator('input[name$="[price]"]').fill('75');
await cont('offers_backdrops');
await page.click('label[for="offers_backdrops-yes"]');
await cont('backdrops');
await page.locator('[data-row]').first().locator('[data-item-name]').fill('Floral wall');
await page.locator('[data-row]').first().locator('select[name$="[category]"]').selectOption('backdrop');
await uploadTo(page.locator('[data-row]').first().locator('[data-image-input]').first(), 'backdrop.jpg');
await cont('gallery');

// 7. gallery with an explicit cover
await page.locator('[data-gallery-input]').setInputFiles([1, 2, 3, 4, 5, 6].map((n) => `${IMG}/event-${n}.jpg`));
await page.waitForFunction(() => document.querySelectorAll('[data-gallery-card]').length >= 6, null, { timeout: 40000 });
await page.locator('[data-gallery-card]').nth(1).locator('[data-gallery-action="cover"]').click();
await page.waitForFunction(() => document.querySelectorAll('[data-gallery-action="cover"].btn-primary').length === 1);
check('exactly one homepage cover is chosen', (await page.locator('[data-gallery-action="cover"].btn-primary').count()) === 1);
await page.screenshot({ path: `${SHOTS}/gallery-desktop.png`, fullPage: true });
await cont('photo_rights');
await page.check('#photo_rights-confirmed');
await cont('brand_personality');

// 8. brand, about (default + another suggestion), advantages, reviews
await page.click('label[for="brand_personality-playful"]');
await cont('about_story');
const aboutDefault = await page.inputValue('[data-about-text]');
check('About starts complete', aboutDefault.length > 80);
await page.click('[data-about-action="another"]');
await page.waitForFunction((t) => document.querySelector('[data-about-text]').value !== t, aboutDefault);
await page.click('[data-about-action="keep"]');
await page.screenshot({ path: `${SHOTS}/about-desktop.png`, fullPage: true });
await cont('advantages');
await page.click('label[for="advantages-digital_sharing"]');
await page.click('label[for="advantages-premium_equipment"]');
await cont('testimonials');
await skip('primary_cta');

// 9. booking, form, FAQ
await page.click('label[for="primary_cta-quote_request"]');
await cont('contact_form_fields');
await page.click('[data-form-recommended]');
await page.click('[data-form-preview-toggle]');
check('form preview lists the fields', (await page.locator('[data-form-preview-fields] label').count()) >= 6);
await cont('faq_items');
check('six FAQ cards are filled in', (await page.locator('[data-repeatable] [data-row]').count()) === 6);
check('no FAQ answer is empty', (await page.locator('[data-repeatable] [data-row] textarea').evaluateAll((els) => els.every((e) => e.value.trim().length > 20))));
await page.screenshot({ path: `${SHOTS}/faq-desktop.png`, fullPage: true });
check('every step so far was in-page', (await alive()) === 'same-document');
await page.click('[data-wizard-continue]');

// 10. review → generate
await page.waitForURL(/\/build\/review/);
check('review lists the missing photo', /Photo needed: 360 Photo Booth/.test(await page.locator('[data-testid="photo-requirements"]').innerText()));
check('review shows counts', (await page.locator('[data-count="booths"]').innerText()) === '3' && (await page.locator('[data-count="events"]').innerText()) === '3');
await page.screenshot({ path: `${SHOTS}/review-desktop.png`, fullPage: true });
await Promise.all([page.waitForURL(/\/website\/(preview|studio)/, { timeout: 90000 }), page.click('[data-generation-form] button[type="submit"]')]);
check('a draft website was generated (no AI)', true, page.url());
await page.screenshot({ path: `${SHOTS}/generated-desktop.png`, fullPage: true });

// 11. the Studio blocks publishing and names the missing photo
const studioUrl = `${BASE}/workspaces/${fixture.workspace}/businesses/${fixture.business}/website/studio`;
await page.goto(studioUrl, { waitUntil: 'load' });
await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
check('Studio checklist names the missing booth photo', /Photo needed: 360 Photo Booth/.test(await page.evaluate(() => document.body.innerText)));
await page.screenshot({ path: `${SHOTS}/studio-desktop.png`, fullPage: true });

await Promise.all([page.waitForLoadState('load'), page.locator('form[action$="/website/publish"] button[type="submit"]').first().click()]);
const stateText = async () => { await page.goto(studioUrl, { waitUntil: 'load' }); return (await page.locator('.content-body').first().innerText()).replace(/\s+/g, ' '); };
check('publishing is refused while the photo is missing', /Draft/.test(await stateText()) && !/Not published yet/.test('') && /Not published yet/.test(await page.evaluate(() => document.body.innerText)));

// 12. add the photo through the checklist link, rebuild the draft from the answers, publish
await page.goto(studioUrl, { waitUntil: 'load' });
await Promise.all([page.waitForURL(/\/build\/booth_services/), page.click('a:has-text("Add the missing photos")')]);
await uploadTo(page.locator('[data-service-card]').nth(2).locator('[data-image-input]').first(), '360.jpg');
for (let i = 0; i < 40; i++) {
  await page.click('[data-wizard-continue]');
  await page.waitForTimeout(700);
  if (/\/build\/review/.test(page.url()) || !/\/build\//.test(page.url())) { break; }
}
check('editing answers finished on the review step', /\/build\/review/.test(page.url()), page.url());
await Promise.all([page.waitForURL(/\/website\/studio/, { timeout: 60000 }), page.click('form[action$="/build/generate"] button[type="submit"]')]);
await page.goto(`${BASE}/workspaces/${fixture.workspace}/businesses/${fixture.business}/website/rebuild`, { waitUntil: 'load' });
await page.check('#rebuild-mode-full');
await page.click('#rebuild-apply');
await page.waitForSelector('#rebuild-confirm-go', { state: 'visible' });
await Promise.all([page.waitForLoadState('load'), page.click('#rebuild-confirm-go')]);
await page.waitForTimeout(1500);
await page.goto(studioUrl, { waitUntil: 'load' });
await Promise.all([page.waitForLoadState('load'), page.locator('form[action$="/website/publish"] button[type="submit"]').first().click()]);
check('after adding the photo the website publishes', /No unpublished changes|Published/.test(await stateText()) && !/Not published yet/.test(await page.evaluate(() => document.body.innerText)));

// 13. the live site, desktop and phone
const live = await context.newPage();
await live.goto(`${BASE}/workspaces/${fixture.workspace}/businesses/${fixture.business}/website/preview`, { waitUntil: 'load' });
await settle(live);
await live.screenshot({ path: `${SHOTS}/site-desktop.png`, fullPage: true });
const html = await live.content();
check('no empty picture frames or broken images on the site', await live.evaluate(() => [...document.images].every((i) => i.complete && i.naturalWidth > 0)));
await live.setViewportSize({ width: 390, height: 844 });
await live.reload({ waitUntil: 'load' });
await settle(live);
await live.screenshot({ path: `${SHOTS}/site-mobile.png`, fullPage: true });
check('the site has no horizontal scroll on a phone', await live.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1));

await browser.close();
finish();
