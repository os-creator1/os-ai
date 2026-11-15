// usage: node shot.js <app|ref> <name> <path-or-refdir/file> <w> <h> [action]
// Playwright is the one preinstalled in the CI/dev image (see tests/e2e/acceptance/README.md).
const { chromium } = require('playwright');
const fs = require('fs');
const [,, kind, name, target, w, h, action] = process.argv;
const OUT = process.env.OUT_DIR || '/tmp/mg-acceptance';
const seed = JSON.parse(fs.readFileSync((process.env.SEED_JSON||'/tmp/seed.json')));
(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 1 });
  if (kind === 'app') await ctx.addCookies([{ name: seed.cookieName, value: seed.cookie, domain: '127.0.0.1', path: '/' }]);
  const page = await ctx.newPage();
  const logs = [];
  page.on('console', m => { if (m.type() === 'error') logs.push(m.text()); });
  page.on('pageerror', e => logs.push('PAGEERROR ' + e.message));
  let url;
  if (kind === 'app') url = (process.env.BASE_URL || 'http://127.0.0.1:8000') + (process.env.SHELL_MODE ? target : target + (target.includes('?') ? '&' : '?') + 'mgframe=0').replace('{ws}', seed.ws).replace('{biz}', seed.biz).replace('{amira}', seed.amira).replace('{karina}', seed.karina);
  else url = 'http://127.0.0.1:8100/' + target;
  await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 }).catch(e => logs.push('GOTO ' + e.message));
  await page.waitForTimeout(1500);
  if (action) { try { await eval('(async()=>{' + action + '})()'); } catch (e) { logs.push('ACTION ' + e.message); } await page.waitForTimeout(800); }
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false });
  console.log('url', page.url()); console.log(logs.slice(0, 8).join('\n'));
  await browser.close();
})();
