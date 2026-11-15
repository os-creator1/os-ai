// Playwright is the one preinstalled in the CI/dev image (see tests/e2e/acceptance/README.md).
const { chromium } = require('playwright');
const fs=require('fs'); const seed=JSON.parse(fs.readFileSync((process.env.SEED_JSON||'/tmp/seed.json')));
(async()=>{
 const b=await chromium.launch({args:['--no-sandbox']}); const c=await b.newContext({viewport:{width:1440,height:900}});
 await c.addCookies([{name:seed.cookieName,value:seed.cookie,domain:'127.0.0.1',path:'/'}]);
 const p=await c.newPage(); const errs=[]; p.on('pageerror',e=>errs.push(e.message));
 const base=`${process.env.BASE_URL||'http://127.0.0.1:8000'}/workspaces/${seed.ws}/businesses/${seed.biz}`; const out={};
 await p.goto(base+'/people',{waitUntil:'networkidle'}); const F=p.frameLocator('#mg-app-frame');
 // contact creation inside the shell: country/number conflict, then a valid LT contact
 await F.locator('[data-mgx-open-contact]').click();
 await F.locator('[data-mgx-cc]').click(); await F.locator('[data-mgx-pick-search]').fill('lithuania'); await p.keyboard.press('Enter');
 await F.locator('#mgx-phone').fill('+1 720 664 0969'); await F.locator('[data-mgx-save]').click();
 await F.locator('[data-mgx-err=phone].is-on').waitFor(); out.conflictMsg=(await F.locator('[data-mgx-err=phone]').textContent());
 await F.locator('#mgx-phone').fill('6'+String(10+Math.floor(Math.random()*89))+' '+String(10000+Math.floor(Math.random()*89999))); await F.locator('#mgx-first').fill('Ona'); await F.locator('#mgx-last').fill('Petrauskaite'); await F.locator('#mgx-email').fill('ona@example.lt');
 await Promise.all([p.waitForFunction(()=>true), F.locator('[data-mgx-save]').click()]);
 await F.locator('text=Ona Petrauskaite').first().waitFor({timeout:15000}); out.createdVisible=true;
 out.rows=await F.locator('[data-role=contact-row]').count();
 // search + group filter
 await F.locator('#contacts-search').fill('ona'); await F.locator('#contacts-search').press('Enter'); await p.waitForTimeout(1500);
 out.searchRows=await F.locator('[data-role=contact-row]').count();
 await F.locator('#contacts-search').fill(''); await F.locator('#contacts-search').press('Enter'); await p.waitForTimeout(1200);
 await F.locator('#contacts-group').selectOption({label:'Vendors'}); await p.waitForTimeout(1500);
 out.vendorRows=await F.locator('[data-role=contact-row]').count();
 // conversations: channel switching, search
 await F.locator('a[href$="/conversations"]').first().click({timeout:6000}).catch(async e=>{await p.screenshot({path:(process.env.OUT_DIR||'/tmp/mg-acceptance')+'/e2e-stuck.png'}); throw e}); await p.waitForLoadState('networkidle'); await p.waitForTimeout(1500);
 const count=async()=>F.locator('#users-list li.conv-row').count();
 out.all=await count();
 await F.locator('.channel-button[data-channel=sms]').click(); await p.waitForTimeout(1200); out.sms=await count();
 await F.locator('.channel-button[data-channel=email]').click(); await p.waitForTimeout(1200); out.email=await count();
 await F.locator('.channel-button[data-channel=all]').click(); await p.waitForTimeout(1000);
 await F.locator('#chat-search').fill('Amira'); await p.waitForTimeout(1500); out.searchAmira=await count();
 // open the email thread and try to send: the sandbox has no reachable mailbox provider, so the truthful outcome is an error, never "sent"
 await F.locator('#chat-search').fill(''); await p.waitForTimeout(800);
 await F.locator('.channel-button[data-channel=email]').click(); await p.waitForTimeout(1200);
 await F.locator('li.conv-row[data-channel=email]').first().click(); await p.waitForTimeout(1500);
 await F.locator('#message').fill('Test body'); out.sendEnabled=await F.locator('.send').isEnabled(); out.sendVisible=await F.locator('.send').isVisible(); await F.locator('.send').click({timeout:5000,force:true}).catch(e=>{out.sendClickError=e.message.split('\n').slice(0,3).join(' / ')}); await p.waitForTimeout(3000);
 out.toast=(await F.locator('#toast-container').allTextContents()).join(' | ').slice(0,160);
 out.sentClaim=/Email sent/.test(out.toast);
 // responsive: layout stays within the viewport at tablet and mobile
 for (const [w,h] of [[820,1180],[390,844]]) { await p.setViewportSize({width:w,height:h}); await p.waitForTimeout(600);
   out['overflow'+w]=await F.locator('html').evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth+1); }
 out.errs=errs; console.log(JSON.stringify(out,null,1)); await b.close();
})().catch(e=>{console.log('E2E2 ERROR',e.message.slice(0,400));process.exit(1)});
