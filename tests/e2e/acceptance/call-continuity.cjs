// Playwright is the one preinstalled in the CI/dev image (see tests/e2e/acceptance/README.md).
const { chromium } = require('playwright');
const fs=require('fs'); const seed=JSON.parse(fs.readFileSync((process.env.SEED_JSON||'/tmp/seed.json')));
const OUT=process.env.OUT_DIR||'/tmp/mg-acceptance';
(async()=>{
 const b=await chromium.launch({args:['--no-sandbox','--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream']});
 const c=await b.newContext({viewport:{width:1440,height:900}});
 await c.addCookies([{name:seed.cookieName,value:seed.cookie,domain:'127.0.0.1',path:'/'}]);
 const base=`${process.env.BASE_URL||'http://127.0.0.1:8000'}/workspaces/${seed.ws}/businesses/${seed.biz}`;
 const sent=[]; let stage='ringing';
 const fakeSdk=`
  (function(){
  window.__sdk=null;
  function Call(){this.state='ringing';this.sent=[];}
  Call.prototype={answer(){this.state='active'},hangup(){this.state='hangup';window.__hungup=(window.__hungup||0)+1},muteAudio(){},unmuteAudio(){},dtmf(d){this.sent.push(d)},hold(){return Promise.resolve()},unhold(){return Promise.resolve()}};
  window.TelnyxWebRTC={TelnyxRTC:function(){var h={};window.__h=h;this.on=function(e,f){h[e]=f};this.connect=function(){setTimeout(function(){h['telnyx.ready']&&h['telnyx.ready']()},10)};this.disconnect=function(){};}};
  window.__ring=function(){var c=new Call();window.__sdk=c;window.__h['telnyx.notification']({type:'callUpdate',call:c});};
  })();`;
 await c.route('**/vendor/telnyx/webrtc-2.28.2.js',r=>r.fulfill({status:200,contentType:'application/javascript',body:fakeSdk}));
 // The server side of Telnyx is not reachable here: the calls API is answered by the test, everything else is the real app.
 await c.route(/\/businesses\/[^/]+\/calls(\/|$|\?)/,async r=>{const u=new URL(r.request().url()); const m=r.request().method(); const p=u.pathname; sent.push(m+' '+p.replace(/.*\/calls/,'/calls'));
   const j=(o,s=200)=>r.fulfill({status:s,contentType:'application/json',body:JSON.stringify(o)});
   if(p.endsWith('/calls/numbers')) return j({data:[{id:'tokA',number:'+1 (720) 502-8306',primary:true},{id:'tokB',number:'+1 (720) 502-8307',primary:false}]});
   if(p.endsWith('/calls/browser-session')) return j({data:{login_token:'t'}});
   if(p.endsWith('/calls/presence')) return j({data:{available:true}});
   if(p.endsWith('/calls/incoming')) return j({data:[]});
   if(p.endsWith('/hangup')){stage='ended'; return j({data:{id:'c1',status:'ended',ui_state:'ended',answered_at:'2026-11-15T10:00:00Z',duration_seconds:75}});}
   if(p.endsWith('/wrapup')) return j({data:{saved:true}});
   if(m==='POST'&&p.endsWith('/calls')){ setTimeout(()=>{},0); return j({data:{id:'c1',ui_state:'connecting',from_number:'+1 (720) 502-8307'}},202);}
   if(m==='GET'&&/\/calls\/c1$/.test(p)) return j({data:{id:'c1',status:stage==='connected'?'answered':(stage==='ended'?'ended':'initiated'),ui_state:stage,answered_at:stage==='ringing'?null:'2026-11-15T10:00:00Z',duration_seconds:75,from_number:'+1 (720) 502-8307'}});
   return r.continue();
 });
 const p=await c.newPage(); const errs=[]; p.on('pageerror',e=>errs.push(e.message));
 const out={};
 await p.goto(base+'/people',{waitUntil:'networkidle'}); await p.waitForTimeout(800);
 out.shell=await p.$('#mg-app-frame')!==null;
 const frame=()=>p.frames().find(f=>f!==p.mainFrame()&&f.url().includes('/businesses/'));
 out.frameHasPopup=await frame().$('#mg-softphone')!==null; // must be false: the shell owns it
 await frame().click('[data-mgx-call] >> nth=0');
 await p.waitForSelector('[data-role=softphone-chooser] .mg-sp-nr',{timeout:8000});
 out.chooserRows=await p.$$eval('[data-role=softphone-chooser] .mg-sp-nr',r=>r.length);
 await p.screenshot({path:OUT+'/shell-choose-number.png'});
 await p.click('[data-role=softphone-chooser] .mg-sp-nr >> nth=1'); await p.click('[data-role=softphone-choose-go]');
 await p.waitForFunction(()=>window.__h&&typeof window.__h['telnyx.notification']==='function');
 await p.waitForTimeout(300); await p.evaluate(()=>window.__ring());
 stage='connected';
 await p.waitForFunction(()=>document.querySelector('[data-role=softphone-status]').textContent==='On call',null,{timeout:10000});
 out.placeBody=null;
 const t0=await p.textContent('[data-role=softphone-timer]');
 // --- navigate inside the app while on the call ---
 const sdkBefore=await p.evaluate(()=>{window.__marker=Math.random();return window.__marker});
 await frame().click('a[href$="/conversations"]'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(2500);
 out.urlAfterNav=p.url().replace(/.*\/businesses\/[^/]+/,'');
 out.titleAfterNav=await p.title();
 out.stateAfterNav=await p.getAttribute('#mg-softphone','data-state');
 out.sameRealm=(await p.evaluate(()=>window.__marker))===sdkBefore;
 out.hungup=await p.evaluate(()=>window.__hungup||0);
 out.timerAdvanced=(await p.textContent('[data-role=softphone-timer]'))!==t0;
 out.convLoaded=!!(await frame().$('.chat-app-window'));
 await p.screenshot({path:OUT+'/shell-call-on-conversations.png'});
 await frame().click('a[href$="/people"]'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
 out.stateAfterNav2=await p.getAttribute('#mg-softphone','data-state');
 // minimize + restore + keypad + hold while navigating
 await p.click('[data-role=softphone-minimize]'); out.pillVisible=await p.isVisible('[data-role=softphone-pill]');
 await frame().click('a[href$="/conversations"]'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(500);
 out.pillStill=await p.isVisible('[data-role=softphone-pill]');
 await p.screenshot({path:OUT+'/shell-minimized-on-conversations.png'});
 await p.click('[data-role=pill-expand]'); out.restored=await p.isVisible('#mg-softphone');
 await p.click('[data-role=softphone-keypad]'); await p.click('[data-digit="5"]'); out.dtmf=await p.evaluate(()=>window.__sdk.sent.join('')); await p.click('[data-role=softphone-keypad-hide]');
 await p.click('[data-role=softphone-hold]'); await p.waitForTimeout(300); out.holdStatus=await p.textContent('[data-role=softphone-status]'); await p.click('[data-role=softphone-hold]');
 out.hungupBeforeEnd=await p.evaluate(()=>window.__hungup||0);
 await p.click('[data-role=softphone-hangup]'); await p.waitForTimeout(800);
 out.endedState=await p.getAttribute('#mg-softphone','data-state');
 out.placeSentFrom=null;
 out.requests=sent.filter(x=>!x.includes('GET /calls/c1')).join(' | ');
 out.errs=errs;
 console.log(JSON.stringify(out,null,1)); await b.close();
})().catch(e=>{console.log('ERR',e.message);process.exit(1)});
