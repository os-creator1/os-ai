// Playwright is the one preinstalled in the CI/dev image (see tests/e2e/acceptance/README.md).
const { chromium } = require('playwright');
const fs=require('fs'); const seed=JSON.parse(fs.readFileSync((process.env.SEED_JSON||'/tmp/seed.json'))); const call=fs.readFileSync((process.env.CALL_UID_FILE||'/tmp/calluid.txt'),'utf8').trim();
(async()=>{
 const b=await chromium.launch({args:['--no-sandbox']}); const c=await b.newContext();
 await c.addCookies([{name:seed.cookieName,value:seed.cookie,domain:'127.0.0.1',path:'/'}]);
 const p=await c.newPage(); const base=`${process.env.BASE_URL||'http://127.0.0.1:8000'}/workspaces/${seed.ws}/businesses/${seed.biz}`;
 await p.goto(base+'/people?mgframe=0',{waitUntil:'networkidle'});
 const token=await p.evaluate(()=>document.querySelector('meta[name=csrf-token]')?.content||'');
 const res=await p.evaluate(async ({base,call,token})=>{
   const one=()=>fetch(`${base}/calls/${call}/wrapup`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({note:'RACE2-NOTE',disposition:'booked'})}).then(r=>r.json().then(j=>({s:r.status,j})));
   return Promise.all(Array.from({length:12},one)); },{base,call,token});
 console.log(JSON.stringify(res.map(r=>[r.s,r.j.data&&r.j.data.duplicate])));
 await b.close();
})();
