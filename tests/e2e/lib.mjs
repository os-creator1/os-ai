// Shared helpers for the browser walkthroughs (see README.md).
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(process.env.PW_MODULE_DIR ? process.env.PW_MODULE_DIR + '/' : import.meta.url);
export const { chromium } = require('playwright-core');

export const BASE = process.env.E2E_BASE_URL || 'http://127.0.0.1:8011';
export const fixture = JSON.parse(fs.readFileSync(process.env.WIZARD_E2E_OUT || '/tmp/claude-0/e2e-fixture.json', 'utf8'));

export async function launch() {
  return chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
}

export async function login(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', fixture.email);
  await page.fill('input[name="password"]', fixture.password);
  await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]:has-text("Login")')]);
}

export const wizardUrl = (path = '') => `${BASE}/workspaces/${fixture.workspace}/businesses/${fixture.business}/website/build${path}`;

let failures = 0;
export function check(name, ok, detail = '') {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
  if (!ok) failures++;
}
export function finish() {
  console.log(failures === 0 ? '\nALL CHECKS PASSED' : `\n${failures} CHECK(S) FAILED`);
  process.exit(failures === 0 ? 0 : 1);
}
