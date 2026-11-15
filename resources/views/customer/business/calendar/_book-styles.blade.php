{{--
    "Book appointment" screen — values lifted from the supplied BookReady / BookNoTypes
    design references (Figtree, MotionGrove red #B4514A, warm card chrome). Scoped
    under .bk so nothing leaks into the rest of the Calendar module or the shell.
    The design's own sidebar, top bar and View-As banner are NOT reproduced: the
    application shell supplies them.
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    .bk{font-family:'Figtree',system-ui,sans-serif;color:#1F1A19;display:flex;flex-direction:column;gap:16px}
    .bk *{box-sizing:border-box}
    .bk a{color:inherit;text-decoration:none}
    .bk a:hover{color:#8F3B35;text-decoration:none}
    .bk button{font-family:inherit;cursor:pointer}
    .bk .ic{width:16px;height:16px;flex:none;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
    .bk-head{display:flex;flex-direction:column;gap:4px}
    .bk-back{align-self:flex-start;display:inline-flex;align-items:center;gap:6px;min-height:36px;color:#8F3B35;font-size:13.5px;font-weight:600}
    .bk-back .ic{width:14px;height:14px}
    .bk h1{margin:0;font-size:26px;font-weight:700;letter-spacing:-.01em;color:#1F1A19}
    .bk-sub{margin:0;font-size:14.5px;color:#5E5652}
    .bk-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 16px;border-radius:10px;border:0;font-size:14px;font-weight:600;white-space:nowrap}
    .bk-card{background:#FFFFFF;border:1px solid #E9E4DF;border-radius:16px}
    .bk-lb{font-size:12px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B625E}
    .bk-alert{padding:12px 16px;border-radius:10px;font-size:14px;background:#FBF0EE;border:1px solid #F3DCD8;color:#8F3B35}

    .bk-contact{display:flex;flex-wrap:wrap;align-items:center;gap:14px;padding:16px 20px}
    .bk-avatar{width:46px;height:46px;border-radius:50%;background:#F8E9E6;color:#8F3B35;display:flex;align-items:center;justify-content:center;font-weight:700;flex:none}
    .bk-contact-body{flex:1 1 240px;display:flex;flex-direction:column;gap:2px;min-width:0}
    .bk-contact-body b{font-size:16.5px}
    .bk-contact-body small{font-size:13px;color:#6B625E}
    .bk .bk-change{border:1px solid #D6CFC8;background:#FFFFFF;color:#2E2826;min-height:40px}
    .bk .bk-change:hover{color:#8F3B35;border-color:#B4514A}

    .bk-grid{display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start}
    .bk-main{flex:3 1 520px;min-width:0;display:flex;flex-direction:column;gap:16px}
    .bk-panel{padding:20px;display:flex;flex-direction:column;gap:12px}
    .bk-types{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
    .bk-ty{display:flex;align-items:center;gap:14px;padding:14px 16px;border:1px solid #E9E4DF;border-radius:12px;background:#FFFFFF;cursor:pointer;margin:0}
    .bk-ty input{position:absolute;opacity:0;pointer-events:none}
    .bk-ty .rd{width:20px;height:20px;border-radius:50%;border:1.5px solid #CFC6BE;flex:none}
    .bk-ty .tx{flex:1;display:flex;flex-direction:column;gap:2px;min-width:0}
    .bk-ty b{font-size:15px;font-weight:600}
    .bk-ty small{font-size:13px;color:#6B625E}
    .bk-ty.on{border-color:#B4514A;background:#FBF0EE;box-shadow:0 0 0 3px #F8E9E6}
    .bk-ty.on .rd{border:6px solid #B4514A}
    .bk-ty:focus-within{outline:2px solid #B4514A;outline-offset:2px}

    .bk-week-head{display:flex;align-items:center;gap:10px}
    .bk-week-head .bk-lb{flex:1}
    .bk-week-head b{font-size:14px}
    .bk-nav{width:34px;height:34px;border-radius:9px;border:1px solid #E9E4DF;background:#FFFFFF;display:inline-flex;align-items:center;justify-content:center;padding:0;color:#1F1A19}
    .bk-nav:disabled{opacity:.4;cursor:not-allowed}
    .bk-days{display:flex;gap:8px}
    .bk-dy{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:2px;padding:10px 0;border:1px solid #E9E4DF;border-radius:12px;background:#FFFFFF;font-size:12px;color:#6B625E}
    .bk-dy b{font-size:18px;font-weight:700;color:#1F1A19}
    .bk-dy.on{background:#B4514A;border-color:#B4514A;color:#FFFFFF}
    .bk-dy.on b{color:#FFFFFF}
    .bk-dy:disabled{opacity:.45;cursor:not-allowed}
    .bk-times{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
    .bk-tm{height:42px;border:1px solid #E9E4DF;border-radius:10px;background:#FFFFFF;font-size:14px;font-weight:600;color:#2E2826;padding:0}
    .bk-tm.on{background:#B4514A;border-color:#B4514A;color:#FFFFFF}
    .bk-times-empty{grid-column:1/-1;padding:14px;border:1px dashed #D6CFC8;border-radius:10px;font-size:14px;color:#5E5652;text-align:center}
    .bk-tz{display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:13px;color:#6B625E}
    .bk-tz .ic{width:14px;height:14px}

    .bk-with{flex-direction:row;flex-wrap:wrap;gap:14px}
    .bk-field{flex:1 1 220px;display:flex;flex-direction:column;gap:6px;min-width:0}
    .bk-select{height:46px;width:100%;padding:0 14px;border:1px solid #D6CFC8;border-radius:10px;background:#FFFFFF;font-family:inherit;font-size:14.5px;font-weight:500;color:#2E2826}
    .bk-input{height:46px;width:100%;padding:0 14px;border:1px solid #D6CFC8;border-radius:10px;font-family:inherit;font-size:14.5px;color:#1F1A19;background:#FFFFFF}
    .bk-more summary{list-style:none;display:inline-flex;align-items:center;gap:6px;min-height:44px;cursor:pointer;font-size:13.5px;font-weight:500;color:#8A817C}
    .bk-more summary::-webkit-details-marker{display:none}
    .bk-more summary:hover{color:#5E5652}
    .bk-more[open] summary .ic{transform:rotate(180deg)}
    .bk-more-body{display:flex;flex-direction:column;gap:8px}

    .bk-summary{flex:2 1 300px;min-width:0;padding:20px;display:flex;flex-direction:column;gap:4px}
    .bk-summary .when{font-size:18px;font-weight:800}
    .bk-summary .what{font-size:13.5px;color:#6B625E;margin-bottom:8px}
    .bk-sr{display:flex;justify-content:space-between;gap:12px;font-size:13.5px;padding:10px 0;border-top:1px solid #F0EBE6}
    .bk-sr span:first-child{color:#6B625E}
    .bk-sr b{font-weight:600;text-align:right}
    .bk-submit{width:100%;min-height:50px;margin-top:12px;background:#B4514A;color:#FFFFFF;font-size:15.5px;box-shadow:0 6px 16px rgba(180,81,74,.28)}
    .bk-submit:hover{background:#8F3B35;color:#FFFFFF}
    .bk-submit:disabled{background:#D9B5B1;box-shadow:none;cursor:not-allowed}
    .bk-hint{font-size:12.5px;color:#8A817C;margin:8px 0 0;text-align:center}

    .bk-empty-wrap{max-width:760px;display:flex;flex-direction:column;gap:16px}
    .bk-empty{padding:36px 32px;display:flex;flex-direction:column;align-items:center;gap:14px;text-align:center}
    .bk-empty-ic{width:60px;height:60px;border-radius:18px;background:#F8E9E6;color:#B4514A;display:flex;align-items:center;justify-content:center}
    .bk-empty-ic .ic{width:28px;height:28px}
    .bk-empty-copy{display:flex;flex-direction:column;gap:6px;max-width:420px}
    .bk-empty-copy b{font-size:19px;font-weight:800}
    .bk-empty-copy span{font-size:14.5px;line-height:1.5;color:#5E5652}
    .bk .bk-primary{min-height:48px;padding:0 22px;background:#B4514A;color:#FFFFFF;font-size:15px}
    .bk .bk-primary:hover{background:#8F3B35;color:#FFFFFF}
    .bk-empty small{font-size:12.5px;color:#8A817C}

    .bk-find{padding:20px;display:flex;flex-direction:column;gap:12px;max-width:760px}
    .bk-find form{display:flex;flex-wrap:wrap;gap:10px}
    .bk-find form .bk-input{flex:1 1 240px}
    .bk-results{display:flex;flex-direction:column;gap:8px;margin:0;padding:0;list-style:none}
    .bk-result{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #E9E4DF;border-radius:12px;background:#FFFFFF}
    .bk-result:hover{border-color:#B4514A}
    .bk-result .bk-avatar{width:38px;height:38px;font-size:13px}
    .bk-result span.t{display:flex;flex-direction:column;gap:1px;min-width:0}
    .bk-result b{font-size:14.5px}
    .bk-result small{font-size:13px;color:#6B625E}

    @media (max-width:640px){
        .bk h1{font-size:22px}
        .bk-times{grid-template-columns:repeat(3,1fr)}
        .bk-days{gap:4px}
        .bk-dy{padding:8px 0}
        .bk-dy b{font-size:16px}
        .bk-panel,.bk-summary{padding:16px}
        .bk-empty{padding:28px 20px}
    }
</style>
