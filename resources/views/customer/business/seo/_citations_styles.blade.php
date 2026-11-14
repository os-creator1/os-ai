{{--
    Citations — the approved MotionGrove Citations design.

    Every rule is scoped under `.cg-page` and every class carries the `cg-` prefix, so nothing here can
    collide with the shell's global .row/.card/.btn/.top/.side. The design's hex values are used exactly as
    supplied (tokens on `.cg-page`); the application shell, navigation and top bar are untouched.
    No dynamic output: static CSS only.
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
.cg-page{
  --brand:#B4514A; --brand-dk:#8F3B35; --brand-tint:#F8E9E6; --brand-soft:#FBF0EE;
  --bg:#F6F4F1; --card:#FFFFFF; --sunk:#FBF9F7; --line:#E9E4DF; --line-2:#F0EBE6; --field:#D6CFC8;
  --ink:#1F1A19; --ink-2:#2E2826; --muted:#5E5652; --faint:#8A817C;
  --green:#17693F; --green-bg:#E3F3EA; --amber:#7A4A05; --amber-bg:#FDF1DC; --amber-soft:#FDF6E3;
  --blue:#1F5682; --blue-bg:#E4EEF6;
  --r-lg:20px; --r:14px; --r-sm:10px;
  --shadow:0 1px 2px rgba(31,26,25,.04),0 8px 24px rgba(31,26,25,.05);
  font-family:'Figtree',system-ui,sans-serif;color:var(--ink);font-size:15px;line-height:1.45;-webkit-font-smoothing:antialiased;
}
.cg-page,.cg-page *{box-sizing:border-box}
.cg-page a{color:inherit;text-decoration:none}
.cg-page button{font-family:inherit;cursor:pointer;color:inherit}
.cg-page .cg-ic{width:16px;height:16px;flex:none;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.cg-page :focus-visible{outline:2px solid var(--brand);outline-offset:2px;border-radius:6px}
.cg-page h1,.cg-page h2,.cg-page h3{font-family:inherit;color:var(--ink)}
.cg-page .cg-loc-menu-item{width:100%;display:flex;align-items:center;gap:10px;min-height:52px;padding:6px 10px;border:0;border-radius:10px;background:transparent;text-align:left}
.cg-page .cg-loc-menu-item:hover{background:var(--sunk)}
.cg-page .cg-loc-menu-item[aria-checked="true"]{background:var(--brand-soft)}
.cg-page .cg-loc-menu-item b{display:block;font-size:14px;font-weight:600}
.cg-page .cg-loc-menu-item small{font-size:12.5px;color:var(--faint)}
.cg-page .cg-loc-menu-item .cg-chk{margin-left:auto;color:var(--brand);visibility:hidden}
.cg-page .cg-loc-menu-item[aria-checked="true"] .cg-chk{visibility:visible}
.cg-page .cg-row-btn{width:100%;display:grid;grid-template-columns:minmax(0,1fr) 150px 130px 24px;align-items:center;gap:14px;min-height:64px;padding:10px 22px;border:0;background:transparent;text-align:left}
.cg-page .cg-row-btn:hover,.cg-page .cg-row.cg-open .cg-row-btn{background:#FCFAF8}
.cg-page .cg-pill-help{display:block;margin-top:6px;font-size:12.5px;color:var(--muted)}
.cg-page .cg-nap-line{display:flex;flex-wrap:wrap;gap:6px 14px;margin:0 0 14px;font-size:13px;color:var(--ink-2)}
.cg-page .cg-nap-line .cg-field{display:inline-flex;align-items:center;gap:6px}
.cg-page .cg-nap-line .cg-field--diff{color:var(--amber);font-weight:600}
.cg-page .cg-nap-line .cg-field--ok{color:var(--green)}
.cg-page .cg-nap-line .cg-field--none{color:var(--faint)}
.cg-page .cg-label{font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--faint)}
.cg-page .cg-stat-nap{display:flex;gap:10px;align-items:flex-start;flex:1 1 100%;font-size:13px;color:var(--muted)}
.cg-page .cg-filter-empty{padding:36px 22px;text-align:center;color:var(--muted);font-size:14px}
.cg-page .cg-hint-block{display:block;font-size:13px;color:var(--faint)}
.cg-page .cg-flex-col{display:flex;flex-direction:column;gap:14px}
.cg-page .cg-note-inline{font-size:13px;color:var(--muted)}
.cg-page .cg-btn.cg-danger{color:#8C2A22}
.cg-page .cg-contents{display:contents}
.cg-page .cg-group{display:block}
.cg-page .cg-btn{text-decoration:none}
.cg-page{width:100%;max-width:1180px;margin:0 auto;padding:24px 32px 64px;display:flex;flex-direction:column;gap:22px}
.cg-page .cg-head{display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px}
.cg-page .cg-head h1{margin:0;font-size:30px;font-weight:800;letter-spacing:-.025em}
.cg-page .cg-head p{margin:4px 0 0;color:var(--muted);font-size:15px}
.cg-page .cg-loc{position:relative;margin-left:auto}
.cg-page .cg-loc>button{display:inline-flex;align-items:center;gap:10px;min-height:44px;padding:0 12px 0 10px;border:1px solid var(--field);border-radius:12px;background:#fff;font-size:14px;font-weight:600}
.cg-page .cg-loc>button .cg-pin{width:28px;height:28px;border-radius:8px;background:var(--brand-tint);color:var(--brand-dk);display:flex;align-items:center;justify-content:center}
.cg-page .cg-loc>button small{display:block;font-size:11.5px;font-weight:500;color:var(--faint);line-height:1.1}
.cg-page .cg-loc>button span.cg-t{display:flex;flex-direction:column;align-items:flex-start;line-height:1.2}
.cg-page .cg-menu-pop{position:absolute;right:0;top:calc(100% + 6px);width:280px;padding:6px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 48px rgba(31,26,25,.16);z-index:30;display:none}
.cg-page .cg-menu-pop.cg-open{display:block}
.cg-page .cg-menu-pop button{width:100%;display:flex;align-items:center;gap:10px;min-height:52px;padding:6px 10px;border:0;border-radius:10px;background:transparent;text-align:left}
.cg-page .cg-menu-pop button:hover{background:var(--sunk)}
.cg-page .cg-menu-pop button[aria-checked="true"]{background:var(--brand-soft)}
.cg-page .cg-menu-pop button b{display:block;font-size:14px;font-weight:600}
.cg-page .cg-menu-pop button small{font-size:12.5px;color:var(--faint)}
.cg-page .cg-menu-pop .cg-chk{margin-left:auto;color:var(--brand);visibility:hidden}
.cg-page .cg-menu-pop button[aria-checked="true"] .cg-chk{visibility:visible}
.cg-page .cg-card{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--shadow)}
.cg-page .cg-grid-top{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(0,1fr);gap:20px;align-items:start}
.cg-page .cg-hero{position:relative;overflow:hidden;padding:30px 32px 28px;display:flex;flex-direction:column;gap:20px}
.cg-page .cg-hero::before{content:"";position:absolute;inset:0 0 auto 0;height:5px;background:linear-gradient(90deg,var(--brand),#D9958E)}
.cg-page .cg-hero.cg-clear::before{background:linear-gradient(90deg,#1F8A55,#7CC9A0)}
.cg-page .cg-hero.cg-prog::before{background:linear-gradient(90deg,#2F6FA3,#8CB6D8)}
.cg-page .cg-eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--brand-dk)}
.cg-page .cg-eyebrow .cg-dot{width:22px;height:22px;border-radius:7px;background:var(--brand-tint);display:flex;align-items:center;justify-content:center}
.cg-page .cg-hero.cg-clear .cg-eyebrow{color:var(--green)}
.cg-page .cg-hero.cg-clear .cg-eyebrow .cg-dot{background:var(--green-bg)}
.cg-page .cg-hero.cg-prog .cg-eyebrow{color:var(--blue)}
.cg-page .cg-hero.cg-prog .cg-eyebrow .cg-dot{background:var(--blue-bg)}
.cg-page .cg-hero h2{margin:0;font-size:28px;line-height:1.15;font-weight:800;letter-spacing:-.025em;max-width:560px}
.cg-page .cg-hero .cg-why{margin:0;font-size:15.5px;line-height:1.55;color:var(--muted);max-width:600px}
.cg-page .cg-facts{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cg-page .cg-facts>div{padding:14px 16px;border-radius:var(--r);background:var(--sunk);border:1px solid var(--line-2)}
.cg-page .cg-facts h3{margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6B625E}
.cg-page .cg-facts ul{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:7px}
.cg-page .cg-facts li{display:flex;gap:9px;font-size:14px;line-height:1.4;color:var(--ink-2)}
.cg-page .cg-facts li .cg-ic{width:16px;height:16px;margin-top:1px}
.cg-page .cg-facts .cg-k .cg-ic{color:var(--green)}
.cg-page .cg-facts .cg-u .cg-ic{color:var(--faint)}
.cg-page .cg-steps{display:flex;gap:6px;align-items:center}
.cg-page .cg-steps span{flex:1;height:6px;border-radius:999px;background:#E6E0DA}
.cg-page .cg-steps span.cg-on{background:#2F6FA3}
.cg-page .cg-cta-row{display:flex;flex-wrap:wrap;align-items:center;gap:12px}
.cg-page .cg-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 18px;border-radius:12px;border:1px solid transparent;font-size:14.5px;font-weight:600;white-space:nowrap;transition:transform .12s ease, box-shadow .15s ease, background .15s}
.cg-page .cg-btn:active{transform:translateY(1px)}
.cg-page .cg-btn.cg-primary{background:var(--brand);color:#fff;box-shadow:0 6px 16px rgba(180,81,74,.28)}
.cg-page .cg-btn.cg-primary:hover{background:#A6463F}
.cg-page .cg-btn.cg-lg{min-height:52px;padding:0 24px;font-size:15.5px;border-radius:14px}
.cg-page .cg-btn.cg-ghost{background:#fff;border-color:var(--field);color:var(--ink-2)}
.cg-page .cg-btn.cg-ghost:hover{background:var(--sunk)}
.cg-page .cg-btn.cg-sm{min-height:36px;padding:0 12px;font-size:13px;border-radius:10px}
.cg-page .cg-btn.cg-link{min-height:44px;padding:0 6px;color:var(--brand-dk);background:transparent}
.cg-page .cg-hint{font-size:13px;color:var(--faint)}
.cg-page .cg-kit{padding:22px 22px 14px}
.cg-page .cg-kit-h{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px}
.cg-page .cg-kit-h h2{margin:0;font-size:18px;font-weight:800;letter-spacing:-.01em}
.cg-page .cg-kit-h p{margin:2px 0 0;font-size:13px;color:var(--muted)}
.cg-page .cg-kit-h .cg-btn{margin-left:auto}
.cg-page .cg-kf{display:flex;align-items:center;gap:12px;padding:12px 2px;border-top:1px solid var(--line-2)}
.cg-page .cg-kf .cg-lab{font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--faint)}
.cg-page .cg-kf .cg-val{font-size:15px;font-weight:600;color:var(--ink);word-break:break-word}
.cg-page .cg-kf .cg-val.cg-miss{color:var(--faint);font-weight:500}
.cg-page .cg-kf .cg-val small{display:block;font-size:12.5px;font-weight:500;color:var(--muted);margin-top:2px}
.cg-page .cg-kf .cg-grow{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.cg-page .cg-copy{flex:none;display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border-radius:9px;border:1px solid var(--field);background:#fff;font-size:13px;font-weight:600;color:var(--ink-2);transition:all .15s}
.cg-page .cg-copy:hover{border-color:var(--brand);color:var(--brand-dk)}
.cg-page .cg-copy.cg-done{background:var(--green-bg);border-color:#BFE3CE;color:var(--green)}
.cg-page .cg-add{flex:none;font-size:13px;font-weight:600;color:var(--brand-dk);min-height:34px;display:inline-flex;align-items:center}
.cg-page .cg-pill{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 9px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
.cg-page .cg-pill i{width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.8}
.cg-page .cg-p-green{background:var(--green-bg);color:var(--green)}
.cg-page .cg-p-amber{background:var(--amber-bg);color:var(--amber)}
.cg-page .cg-p-blue{background:var(--blue-bg);color:var(--blue)}
.cg-page .cg-p-grey{background:#F1EDE9;color:var(--muted)}
.cg-page .cg-p-line{background:#fff;border:1px solid #E0DAD4;color:var(--faint)}
.cg-page .cg-p-brand{background:var(--brand-tint);color:var(--brand-dk)}
.cg-page .cg-priv{display:flex;gap:8px;align-items:flex-start;margin-top:4px;padding:10px 12px;border-radius:10px;background:var(--sunk);font-size:12.5px;color:var(--muted)}
.cg-page .cg-priv .cg-ic{width:14px;height:14px;margin-top:1px;color:var(--green)}
.cg-page .cg-prog-card{padding:20px 22px;display:flex;flex-wrap:wrap;align-items:center;gap:22px}
.cg-page .cg-prog-card .cg-big{display:flex;flex-direction:column;gap:2px;min-width:160px}
.cg-page .cg-prog-card .cg-big b{font-size:32px;font-weight:800;letter-spacing:-.03em;line-height:1}
.cg-page .cg-prog-card .cg-big span{font-size:13px;color:var(--muted)}
.cg-page .cg-bar{flex:1 1 360px;display:flex;flex-direction:column;gap:12px}
.cg-page .cg-bar .cg-track{display:flex;height:10px;border-radius:999px;overflow:hidden;background:#EEE9E4;gap:2px}
.cg-page .cg-bar .cg-track span{height:100%}
.cg-page .cg-legend{display:flex;flex-wrap:wrap;gap:8px 18px}
.cg-page .cg-legend button{display:inline-flex;align-items:center;gap:8px;border:0;background:transparent;padding:4px 0;font-size:13.5px;color:var(--ink-2)}
.cg-page .cg-legend button b{font-weight:700}
.cg-page .cg-legend button i{width:10px;height:10px;border-radius:3px}
.cg-page .cg-legend button:hover{color:var(--brand-dk)}
.cg-page .cg-how{flex:1 1 100%;display:flex;gap:10px;align-items:flex-start;padding-top:14px;border-top:1px solid var(--line-2);font-size:13px;color:var(--muted)}
.cg-page .cg-how .cg-ic{color:var(--faint);margin-top:1px}
.cg-page .cg-sec-h{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px}
.cg-page .cg-sec-h h2{margin:0;font-size:20px;font-weight:800;letter-spacing:-.015em}
.cg-page .cg-sec-h p{margin:2px 0 0;font-size:13.5px;color:var(--muted)}
.cg-page .cg-opps{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
.cg-page .cg-opp{padding:18px;display:flex;flex-direction:column;gap:12px;transition:transform .15s ease, box-shadow .15s ease}
.cg-page .cg-opp:hover{transform:translateY(-2px);box-shadow:0 2px 4px rgba(31,26,25,.04),0 14px 30px rgba(31,26,25,.08)}
.cg-page .cg-opp .cg-top-r{display:flex;align-items:center;gap:12px}
.cg-page .cg-tile{width:42px;height:42px;border-radius:12px;flex:none;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800}
.cg-page .cg-opp b.cg-n{font-size:15.5px;font-weight:700}
.cg-page .cg-opp p{margin:0;font-size:13.5px;line-height:1.45;color:var(--muted);flex:1}
.cg-page .cg-opp .cg-tags{display:flex;flex-wrap:wrap;gap:6px}
.cg-page .cg-opp .cg-btn{align-self:flex-start}
.cg-page .cg-inv{overflow:hidden}
.cg-page .cg-inv>summary{list-style:none;display:flex;align-items:center;gap:12px;padding:18px 22px;cursor:pointer}
.cg-page .cg-inv>summary::-webkit-details-marker{display:none}
.cg-page .cg-inv>summary h2{margin:0;font-size:17px;font-weight:700}
.cg-page .cg-inv>summary p{margin:1px 0 0;font-size:13px;color:var(--muted)}
.cg-page .cg-inv>summary .cg-chev{margin-left:auto;transition:transform .2s;color:var(--faint)}
.cg-page .cg-inv[open]>summary .cg-chev{transform:rotate(180deg)}
.cg-page .cg-inv-tools{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:0 22px 14px}
.cg-page .cg-chips{display:flex;flex-wrap:wrap;gap:6px}
.cg-page .cg-chip{height:34px;padding:0 12px;border-radius:999px;border:1px solid var(--line);background:#fff;font-size:13px;font-weight:500;color:var(--ink-2);display:inline-flex;align-items:center;gap:6px}
.cg-page .cg-chip span{font-size:11.5px;color:var(--faint);font-weight:600}
.cg-page .cg-chip[aria-pressed="true"]{border-color:var(--brand);background:var(--brand-soft);color:var(--brand-dk);font-weight:600}
.cg-page .cg-chip[aria-pressed="true"] span{color:var(--brand-dk)}
.cg-page .cg-isearch{margin-left:auto;display:flex;align-items:center;gap:8px;height:40px;padding:0 12px;width:240px;max-width:100%;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--faint)}
.cg-page .cg-isearch input{border:0;outline:0;font:inherit;font-size:14px;width:100%;background:transparent;color:var(--ink)}
.cg-page .cg-rows{border-top:1px solid var(--line)}
.cg-page .cg-grp{padding:10px 22px;font-size:11.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B625E;background:var(--sunk);border-bottom:1px solid var(--line-2)}
.cg-page .cg-row{border-bottom:1px solid var(--line-2)}
.cg-page .cg-row>button{width:100%;display:grid;grid-template-columns:minmax(0,1fr) 150px 130px 24px;align-items:center;gap:14px;min-height:64px;padding:10px 22px;border:0;background:transparent;text-align:left}
.cg-page .cg-row>button:hover{background:#FCFAF8}
.cg-page .cg-row .cg-nm{display:flex;align-items:center;gap:12px;min-width:0}
.cg-page .cg-row .cg-nm .cg-tile{width:36px;height:36px;border-radius:10px;font-size:13px}
.cg-page .cg-row .cg-nm b{display:block;font-size:14.5px;font-weight:600}
.cg-page .cg-row .cg-nm small{font-size:12.5px;color:var(--faint)}
.cg-page .cg-row .cg-lc{font-size:13px;color:var(--muted)}
.cg-page .cg-row .cg-ch{color:var(--faint);transition:transform .2s}
.cg-page .cg-row.cg-open .cg-ch{transform:rotate(180deg)}
.cg-page .cg-row.cg-open>button{background:#FCFAF8}
.cg-page .cg-det{display:none;padding:4px 22px 20px 70px;background:#FCFAF8}
.cg-page .cg-row.cg-open .cg-det{display:block}
.cg-page .cg-det-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px}
.cg-page .cg-det-grid div{padding:12px 14px;border-radius:12px;background:#fff;border:1px solid var(--line-2)}
.cg-page .cg-det-grid small{display:block;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--faint);margin-bottom:3px}
.cg-page .cg-det-grid span{font-size:14px;color:var(--ink-2);word-break:break-word}
.cg-page .cg-det .cg-acts{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.cg-page .cg-empty{padding:36px 22px;text-align:center;color:var(--muted);font-size:14px}
.cg-page .cg-foot{display:flex;gap:12px;align-items:flex-start;padding:16px 18px;border-radius:var(--r);background:rgba(255,255,255,.6);border:1px solid var(--line);font-size:13px;line-height:1.5;color:var(--muted)}
.cg-page .cg-foot .cg-ic{color:var(--faint);margin-top:2px}
.cg-page .cg-toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,20px);opacity:0;pointer-events:none;display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;background:var(--ink);color:#fff;font-size:14px;font-weight:500;box-shadow:0 12px 32px rgba(31,26,25,.25);transition:all .2s ease;z-index:50;max-width:calc(100% - 32px)}
.cg-page .cg-toast.cg-show{opacity:1;transform:translate(-50%,0)}
.cg-page .cg-toast .cg-ic{color:#7CD1A3}
.cg-page .cg-fade{animation:fd .25s ease}
@keyframes fd{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
@media (max-width:1060px){
    .cg-page .cg-grid-top{grid-template-columns:1fr}
}
@media (max-width:900px){
    .cg-page{padding:18px 16px 48px;gap:18px}
}
@media (max-width:640px){
    .cg-page .cg-head h1{font-size:26px}
    .cg-page .cg-loc{margin-left:0;width:100%}
    .cg-page .cg-loc>button{width:100%}
    .cg-page .cg-menu-pop{left:0;right:auto;width:100%}
    .cg-page .cg-hero{padding:24px 20px 22px}
    .cg-page .cg-hero h2{font-size:23px}
    .cg-page .cg-facts{grid-template-columns:1fr}
    .cg-page .cg-cta-row .cg-btn.cg-lg{width:100%}
    .cg-page .cg-kit{padding:18px 16px 10px}
    .cg-page .cg-kit-h .cg-btn span{display:none}
    .cg-page .cg-row>button{grid-template-columns:minmax(0,1fr) auto 20px;padding:10px 16px}
    .cg-page .cg-row .cg-lc{display:none}
    .cg-page .cg-det{padding:4px 16px 18px}
    .cg-page .cg-det-grid{grid-template-columns:1fr}
    .cg-page .cg-inv>summary, .cg-page .cg-inv-tools{padding-left:16px;padding-right:16px}
    .cg-page .cg-grp{padding-left:16px}
    .cg-page .cg-isearch{width:100%;margin-left:0}
    .cg-page .cg-prog-card{padding:18px 16px}
}
@media (prefers-reduced-motion:reduce){.cg-page *{animation:none!important;transition:none!important}}
</style>
