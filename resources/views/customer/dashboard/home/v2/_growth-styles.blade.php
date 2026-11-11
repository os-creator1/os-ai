{{--
    Growth Home (approved Variant B) — scoped styles. Values are the mockup's own (type scale, radii, shadows, spacing, colours).
    Everything is under `.hv2 .gh-root`, so nothing can reach another page; the brand red is the runtime design token with the mockup's
    value as its fallback, so a per-tenant theme still applies.
--}}
<style>
    .hv2 .hv2-layout.gh-root { --gh-brand: var(--color-primary, #B4514A); --gh-brand-dark: #8F3B35; --gh-text: #1F1A19; --gh-muted: #6B625E; --gh-line: #E9E4DF;
        grid-template-columns: minmax(0, 1fr) minmax(17.5rem, 22.5rem); gap: 0 28px; align-items: start; color: var(--gh-text); }
    .hv2 .gh-root .hv2-main { display: flex; flex-direction: column; gap: 30px; min-width: 0; }
    .hv2 .gh-root .gh-sec { display: flex; flex-direction: column; gap: 12px; margin: 0; animation: gh-in .4s ease both; }
    .hv2 .gh-root .home-ask { margin: 0 !important; }
    .hv2 .gh-root .gh-side { display: flex; flex-direction: column; gap: 20px; padding-top: 4px; min-width: 0; }
    .hv2 .gh-root .gh-ic { width: 16px; height: 16px; flex: none; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .hv2 .gh-root .gh-ic--lg { width: 26px; height: 26px; }
    .hv2 .gh-root .gh-chev { transition: transform .2s; }
    .hv2 .gh-root details[open] > summary > .gh-chev { transform: rotate(180deg); }
    .hv2 .gh-root summary { list-style: none; cursor: pointer; }
    .hv2 .gh-root summary::-webkit-details-marker { display: none; }
    .hv2 .gh-root a { text-decoration: none; }
    .hv2 .gh-root :focus-visible { outline: 2px solid var(--gh-brand-dark); outline-offset: 2px; border-radius: 6px; }
    .hv2 .gh-root .gh-nbm :focus-visible { outline-color: #fff; }
    @keyframes gh-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    @keyframes gh-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }

    /* Type */
    .hv2 .gh-root .gh-h1 { margin: 0; font-size: 28px; font-weight: 700; letter-spacing: -.025em; line-height: 1.2; }
    .hv2 .gh-root .gh-h2 { margin: 0; font-size: 17px; font-weight: 700; letter-spacing: -.01em; }
    .hv2 .gh-root .gh-eyebrow { margin: 0; font-size: 12px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--gh-muted); }
    .hv2 .gh-root .gh-sh { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
    .hv2 .gh-root .gh-sh a { font-size: 13.5px; font-weight: 600; color: var(--gh-brand-dark); }
    .hv2 .gh-root .gh-quiet-note { margin: 0; font-size: 13px; color: var(--gh-muted); }
    .hv2 .gh-root .gh-greet { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; margin-bottom: 2px; }
    .hv2 .gh-root .gh-period { font-size: 13px; color: var(--gh-muted); }

    /* Performance: ONE compact card (hairlines come from a 1px grid gap) */
    .hv2 .gh-root .gh-metrics { display: grid; grid-template-columns: repeat(var(--gh-cols, 3), minmax(0, 1fr)); gap: 1px; background: #EEE9E4; border: 1px solid var(--gh-line); border-radius: 16px; overflow: hidden; }
    .hv2 .gh-root .gh-met { display: flex; flex-direction: column; gap: 3px; min-width: 0; padding: 18px 20px; background: #fff; color: inherit; transition: background .15s; }
    .hv2 .gh-root a.gh-met:hover { background: #FBF9F7; }
    .hv2 .gh-root .gh-met__k { font-size: 13px; font-weight: 600; color: #5E5652; }
    .hv2 .gh-root .gh-met__v { font-size: 26px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums; line-height: 1.25; }
    .hv2 .gh-root .gh-met__row { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
    .hv2 .gh-root .gh-met__state { font-size: 17px; font-weight: 700; padding: 6px 0 2px; color: #3A3330; }
    .hv2 .gh-root .gh-met__s { font-size: 12.5px; color: var(--gh-muted); overflow-wrap: anywhere; }
    .hv2 .gh-root .gh-met__link { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--gh-brand-dark); }

    /* Best Next Move: the strongest card on the page */
    .hv2 .gh-root .gh-nbm { position: relative; background: var(--gh-brand); color: #fff; border-radius: 24px; box-shadow: 0 24px 48px -26px rgba(120, 40, 34, .65); overflow: hidden; }
    .hv2 .gh-root .gh-nbm__deco { position: absolute; border-radius: 50%; background: rgba(255, 255, 255, .07); pointer-events: none; }
    .hv2 .gh-root .gh-nbm__deco--a { right: -120px; top: -150px; width: 420px; height: 420px; }
    .hv2 .gh-root .gh-nbm__deco--b { right: 60px; top: -60px; width: 220px; height: 220px; }
    .hv2 .gh-root .gh-nbm__head { position: relative; display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; padding: 28px 30px 8px; }
    .hv2 .gh-root .gh-nbm__tile { width: 60px; height: 60px; flex: none; display: flex; align-items: center; justify-content: center; border-radius: 18px; background: #fff; color: var(--gh-brand-dark); box-shadow: 0 10px 20px -10px rgba(60, 20, 16, .5); }
    .hv2 .gh-root .gh-nbm__text { flex: 1 1 320px; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
    .hv2 .gh-root .gh-nbm__title { margin: 0; font-size: 24px; line-height: 1.2; font-weight: 700; letter-spacing: -.02em; overflow-wrap: anywhere; color: #fff; }
    .hv2 .gh-root .gh-nbm__sub { margin: 0; font-size: 15px; line-height: 1.55; color: #FBE9E6; }
    .hv2 .gh-root .gh-pills { display: flex; flex-wrap: wrap; gap: 8px; padding-top: 2px; }
    .hv2 .gh-root .gh-pill { display: inline-flex; align-items: center; height: 26px; padding: 0 10px; border-radius: 999px; background: rgba(255, 255, 255, .18); color: #fff; font-size: 12.5px; font-weight: 600; white-space: nowrap; }
    .hv2 .gh-root .gh-evidence { font-size: 13.5px; color: #FBE9E6; }
    .hv2 .gh-root .gh-evidence summary { display: inline-block; font-weight: 600; text-decoration: underline; color: #fff; }
    .hv2 .gh-root .gh-evidence ul { margin: 8px 0 0; padding-left: 1.1rem; }
    .hv2 .gh-root .gh-evidence p { margin: 6px 0 0; }
    .hv2 .gh-root .gh-nbm__actions { position: relative; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 18px 30px 24px; }

    /* Buttons */
    .hv2 .gh-root .gh-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 18px; border-radius: 11px; border: 1px solid transparent; font: inherit; font-size: 14.5px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: transform .15s, box-shadow .15s, background .15s, border-color .15s; }
    .hv2 .gh-root .gh-btn:hover { transform: translateY(-1px); }
    .hv2 .gh-root .gh-btn[disabled] { opacity: .6; cursor: not-allowed; transform: none; }
    .hv2 .gh-root .gh-btn--light { background: #fff; color: var(--gh-brand-dark); min-height: 50px; padding: 0 26px; font-size: 15.5px; box-shadow: 0 10px 20px -10px rgba(40, 10, 8, .6); }
    .hv2 .gh-root .gh-btn--lg { min-height: 50px; padding: 0 26px; font-size: 15.5px; }
    .hv2 .gh-root .gh-btn--ghost { background: transparent; color: #fff; border-color: rgba(255, 255, 255, .5); min-height: 50px; padding: 0 16px; }
    .hv2 .gh-root .gh-btn--ghost:hover { background: rgba(255, 255, 255, .1); }
    .hv2 .gh-root .gh-btn--line { background: #fff; color: #2E2826; border-color: #D6CFC8; min-height: 40px; }
    .hv2 .gh-root .gh-btn--sm { min-height: 40px; padding: 0 16px; font-size: 14px; }

    /* Description proposal (inside the red card) */
    .hv2 .gh-root .gh-desc { position: relative; }
    .hv2 .gh-root .gh-desc__panel { margin: 16px 30px 0; padding: 16px 18px; border-radius: 14px; background: #fff; color: var(--gh-text); box-shadow: 0 12px 28px -18px rgba(40, 10, 8, .6); display: flex; flex-direction: column; gap: 8px; }
    .hv2 .gh-root .gh-desc__label { margin: 0; font-size: 12px; font-weight: 600; color: var(--gh-muted); }
    .hv2 .gh-root .gh-desc__text { display: block; width: 100%; resize: none; overflow: hidden; border: 0; outline: 0; padding: 0; background: transparent; font: inherit; font-size: 15.5px; line-height: 1.6; color: var(--gh-text); }
    .hv2 .gh-root .gh-desc.is-editing .gh-desc__panel { box-shadow: 0 0 0 3px rgba(255, 255, 255, .55), 0 12px 28px -18px rgba(40, 10, 8, .6); }
    .hv2 .gh-root .gh-desc.is-editing .gh-desc__text { border-bottom: 1px dashed #D6CFC8; }
    .hv2 .gh-root .gh-desc__actions { position: relative; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 18px 30px 24px; }
    .hv2 .gh-root .gh-desc__actions[hidden] { display: none; }
    .hv2 .gh-root .gh-desc__spacer { flex: 1; min-width: 12px; }
    .hv2 .gh-root .gh-desc__note { font-size: 12.5px; color: #FBE3DF; flex: 0 1 auto; }
    .hv2 .gh-root .gh-desc__result { position: relative; display: flex; align-items: center; gap: 16px; margin: 16px 30px 0; padding: 18px 20px; border-radius: 14px; background: #fff; color: var(--gh-text); }
    .hv2 .gh-root .gh-desc__result[hidden] { display: none; }
    .hv2 .gh-root .gh-desc__result:last-child { margin-bottom: 24px; }
    .hv2 .gh-root .gh-desc__result-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .hv2 .gh-root .gh-desc__result-text b { font-size: 16px; font-weight: 700; letter-spacing: -.01em; }
    .hv2 .gh-root .gh-desc__result-text span { font-size: 14px; color: #4A4340; }
    .hv2 .gh-root .gh-desc__badge { flex: none; width: 40px; height: 40px; border-radius: 50%; background: #EFEBE7 center / 20px no-repeat; }
    .hv2 .gh-root .gh-desc__result[data-state="completed"] .gh-desc__badge { background-color: #1F5E4A; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12.5l4.5 4.5L19 7.5'/%3E%3C/svg%3E"); box-shadow: 0 0 0 6px rgba(31, 94, 74, .12); }
    .hv2 .gh-root .gh-desc__result[data-state="pending"] .gh-desc__badge, .hv2 .gh-root .gh-desc__result[data-state="unconfirmed"] .gh-desc__badge, .hv2 .gh-root .gh-desc__result[data-state="working"] .gh-desc__badge { background-color: #FBEFD9; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%237A4A0A' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='8'/%3E%3Cpath d='M12 8v4l3 2'/%3E%3C/svg%3E"); }
    .hv2 .gh-root .gh-desc__result[data-state="failed"] .gh-desc__badge { background-color: #FBEAE8; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23A3302A' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M12 6v7M12 17h.01'/%3E%3C/svg%3E"); }
    .hv2 .gh-root .gh-desc__refresh { align-self: flex-start; margin-top: 6px; padding: 0; border: 0; background: none; font: inherit; font-size: 14px; font-weight: 600; text-decoration: underline; color: var(--gh-brand-dark); cursor: pointer; }
    .hv2 .gh-root .gh-desc__refresh[hidden] { display: none; }
    .hv2 .gh-root .gh-desc__next { position: relative; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 16px 30px 22px; color: #fff; }
    .hv2 .gh-root .gh-desc__next[hidden] { display: none; }
    .hv2 .gh-root .gh-desc__next .gh-eyebrow { color: #FBE3DF; }
    .hv2 .gh-root .gh-desc__next-title { flex: 1; min-width: 200px; font-size: 15px; font-weight: 600; }
    .hv2 .gh-root .gh-desc__next .gh-btn--light { min-height: 44px; padding: 0 18px; font-size: 14.5px; }

    /* Other opportunities: folded inside the red card */
    .hv2 .gh-root .gh-nbm__more { position: relative; border-top: 1px solid rgba(255, 255, 255, .2); background: rgba(0, 0, 0, .06); }
    .hv2 .gh-root .gh-nbm__more summary { display: flex; align-items: center; justify-content: space-between; min-height: 48px; padding: 0 28px; font-size: 13.5px; font-weight: 600; color: #fff; }
    .hv2 .gh-root .gh-nbm__more-body { padding: 0 28px 10px; }
    .hv2 .gh-root .gh-nbm__more-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-top: 1px solid rgba(255, 255, 255, .15); font-size: 14px; }
    .hv2 .gh-root .gh-nbm__more-row span { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .hv2 .gh-root .gh-nbm__more-row a { font-size: 13.5px; font-weight: 600; color: #fff; text-decoration: underline; }

    /* Quiet (nothing urgent / unavailable): honest, deliberately not the red hero */
    .hv2 .gh-root .gh-quiet { padding: 18px 22px; border-radius: 16px; background: #fff; border: 1px solid var(--gh-line); }
    .hv2 .gh-root .gh-quiet p { margin: 2px 0 0; font-size: 14px; color: #5E5652; }
    .hv2 .gh-root .gh-quiet--warn { background: #FBEFD9; border-color: #F0D9A8; }

    /* Ask MotionGrove: the existing card, re-skinned while collapsed. The open conversation keeps its own layout. */
    .hv2 .gh-root .gh-ask-title { margin: 0 0 14px; }
    .hv2 .gh-root .home-ask.is-conversation-open .gh-ask-title { display: none; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-card { background: #fff; border: 1px solid #E2DBD4; border-radius: 18px; box-shadow: 0 1px 2px rgba(31, 26, 25, .04), 0 12px 32px -20px rgba(31, 26, 25, .25); transition: border-color .15s, box-shadow .15s; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-card:focus-within { border-color: #C98A84; box-shadow: 0 0 0 4px rgba(180, 81, 74, .10); }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-card .card-body { padding: 16px 16px 14px 20px; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-form { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 8px; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-icon { display: none; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-input { order: 0; flex: 1 1 100%; width: 100%; padding: 4px 0; font-family: inherit; font-size: 16px; line-height: 1.5; min-height: 1.5rem; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .hv2-ask-chips { order: 1; flex: 1 1 auto; flex-wrap: wrap; gap: 8px; margin: 0; padding: 0; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-resume { order: 2; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .gh-ask-history { order: 3; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-submit { order: 4; width: 44px; height: 44px; padding: 0; border: 0; border-radius: 12px; background: var(--gh-brand); color: #fff; display: inline-flex; align-items: center; justify-content: center; transition: transform .15s, filter .15s; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-submit:hover { transform: translateY(-1px); filter: brightness(.95); }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-submit-icon { display: none; }
    .hv2 .gh-root .home-ask:not(.is-conversation-open) .gh-ask-send { width: 18px; height: 18px; stroke-width: 2.2; }
    .hv2 .gh-root .home-ask.is-conversation-open .gh-ask-send, .hv2 .gh-root .home-ask.is-conversation-open .gh-ask-history { display: none; }
    .hv2 .gh-root .hv2-ask-chips .home-chip { display: inline-flex; align-items: center; min-height: 36px; padding: 0 13px; border: 1px solid #E6DFD8; border-radius: 999px; background: #FBF9F7; color: #3A3330; font-size: 13.5px; white-space: normal; max-width: 100%; text-align: left; transition: border-color .15s, background .15s; }
    .hv2 .gh-root .hv2-ask-chips .home-chip:hover { border-color: #C9A49E; background: #fff; }
    .hv2 .gh-root .gh-ask-history { display: inline-flex; align-items: center; gap: 6px; min-height: 40px; padding: 0 6px; border: 0; background: transparent; font: inherit; font-size: 13px; font-weight: 600; color: var(--gh-muted); cursor: pointer; margin-left: auto; }
    .hv2 .gh-root .gh-ask-history:hover { color: var(--gh-brand-dark); }
    .hv2 .gh-root .gh-ask-note { display: block !important; margin-top: 10px; font-size: 12px; color: #8A817C; }
    .hv2 .gh-root .home-ask.is-conversation-open .gh-ask-note { text-align: center; }
    .hv2 .gh-root .gh-ask-note summary { display: inline; }
    .hv2 .gh-root .gh-ask-note__link { text-decoration: underline; }
    .hv2 .gh-root .gh-ask-note p { max-width: 46rem; }

    /* MotionGrove at work */
    .hv2 .gh-root .gh-card { background: #fff; border: 1px solid var(--gh-line); border-radius: 16px; overflow: hidden; }
    .hv2 .gh-root .gh-tabs { display: flex; flex-wrap: wrap; gap: 4px; padding: 12px 14px; }
    .hv2 .gh-root .gh-tab { min-height: 34px; padding: 0 12px; border: 0; border-radius: 999px; background: transparent; color: #5E5652; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .15s, color .15s; }
    .hv2 .gh-root .gh-tab:hover { background: #F4F0EC; }
    .hv2 .gh-root .gh-tab[aria-pressed="true"] { background: var(--gh-text); color: #fff; }
    .hv2 .gh-root .gh-row { display: flex; align-items: flex-start; gap: 14px; padding: 14px 20px; border-top: 1px solid #F0EBE6; }
    .hv2 .gh-root .gh-row[hidden] { display: none; }
    .hv2 .gh-root .gh-row__ico { width: 36px; height: 36px; flex: none; display: flex; align-items: center; justify-content: center; border-radius: 10px; }
    .hv2 .gh-root .gh-row__ico .gh-ic { width: 17px; height: 17px; }
    .hv2 .gh-root .gh-row__t { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .hv2 .gh-root .gh-row__t b { font-size: 14.5px; font-weight: 600; overflow-wrap: anywhere; }
    .hv2 .gh-root .gh-row__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding-top: 4px; }
    .hv2 .gh-root .gh-row__meta a { font-size: 13px; font-weight: 600; color: var(--gh-brand-dark); }
    .hv2 .gh-root .gh-row__when { font-size: 12.5px; color: var(--gh-muted); white-space: nowrap; text-align: right; min-width: 72px; }
    .hv2 .gh-root .gh-st { display: inline-flex; align-items: center; gap: 6px; height: 24px; padding: 0 9px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .hv2 .gh-root .gh-st i { width: 6px; height: 6px; border-radius: 50%; background: currentColor; display: inline-block; }
    .hv2 .gh-root .gh-st i.gh-live { animation: gh-pulse 1.6s ease-in-out infinite; }
    .hv2 .gh-root .gh-tone-approval { background: #FBEFD9; color: #7A4A0A; }
    .hv2 .gh-root .gh-tone-running { background: #E8F0FB; color: #1F4E8C; }
    .hv2 .gh-root .gh-tone-attention { background: #FBEAE8; color: #A3302A; }
    .hv2 .gh-root .gh-tone-done { background: #EFEBE7; color: #4A4340; }
    .hv2 .gh-root .gh-row__ico.gh-tone-done { background: #F4F0EC; }
    .hv2 .gh-root .gh-empty { margin: 0; padding: 28px 20px; text-align: center; font-size: 14px; color: var(--gh-muted); }
    .hv2 .gh-root .gh-empty--filtered { border-top: 1px solid #F0EBE6; }
    .hv2 .gh-root .gh-more { display: block; width: 100%; min-height: 44px; border: 0; border-top: 1px solid #F0EBE6; background: transparent; font: inherit; font-size: 14px; font-weight: 600; color: var(--gh-muted); cursor: pointer; transition: background .15s; }
    .hv2 .gh-root .gh-more:hover { background: #FBF9F7; }
    .hv2 .gh-root .gh-more[hidden] { display: none; }
    .hv2 .gh-root .gh-waiting { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }
    .hv2 .gh-root .gh-waiting ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; font-size: 14px; }
    .hv2 .gh-root .gh-waiting li { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; }
    .hv2 .gh-root .gh-waiting li a { font-weight: 600; color: var(--gh-brand-dark); }

    /* Right column */
    .hv2 .gh-root .gh-side-card { padding: 18px 20px; display: flex; flex-direction: column; gap: 12px; }
    .hv2 .gh-root .gh-side-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
    .hv2 .gh-root .gh-side-head h2 { margin: 0; font-size: 15px; font-weight: 700; }
    .hv2 .gh-root .gh-side-head span { font-size: 13px; font-weight: 600; color: #4A4340; }
    .hv2 .gh-root .gh-side-head a { font-size: 13px; font-weight: 600; color: var(--gh-brand-dark); }
    .hv2 .gh-root .gh-side-head a[hidden] { display: none; }
    .hv2 .gh-root .gh-seg { display: grid; grid-template-columns: repeat(var(--gh-seg, 6), minmax(0, 1fr)); gap: 4px; }
    .hv2 .gh-root .gh-seg span { height: 5px; border-radius: 3px; background: #EDE8E3; }
    .hv2 .gh-root .gh-seg span.is-done { background: #1F5E4A; }
    .hv2 .gh-root .gh-checklist summary { display: flex; align-items: center; justify-content: space-between; min-height: 36px; font-size: 13.5px; font-weight: 600; color: #5E5652; }
    .hv2 .gh-root .gh-checklist ul { list-style: none; margin: 4px 0 0; padding: 0; display: flex; flex-direction: column; font-size: 13.5px; }
    .hv2 .gh-root .gh-checklist li { display: flex; align-items: center; gap: 10px; padding: 8px 0; }
    .hv2 .gh-root .gh-checklist li.is-done { color: var(--gh-muted); }
    .hv2 .gh-root .gh-checklist li a { color: var(--gh-text); font-weight: 600; }
    .hv2 .gh-root .gh-checklist li a:hover { color: var(--gh-brand-dark); }
    .hv2 .gh-root .gh-tick { color: #1F5E4A; display: inline-flex; }
    .hv2 .gh-root .gh-tick .gh-ic { width: 15px; height: 15px; stroke-width: 2.4; }
    .hv2 .gh-root .gh-ring { width: 15px; height: 15px; border-radius: 50%; border: 2px solid #D6CFC8; box-sizing: border-box; flex: none; }
    .hv2 .gh-root .gh-chat { display: flex; flex-direction: column; gap: 2px; padding: 10px 0; border-top: 1px solid #F0EBE6; color: inherit; transition: color .15s; }
    .hv2 .gh-root .gh-chat:hover .gh-chat__title { color: var(--gh-brand-dark); }
    .hv2 .gh-root .gh-chat__title { font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }
    .hv2 .gh-root .gh-chat__when { font-size: 12.5px; color: var(--gh-muted); }

    /* Tablet: the single column keeps the main flow's priority; the two side cards sit next to each other. */
    @media (max-width: 1199.98px) {
        .hv2 .hv2-layout.gh-root { grid-template-columns: minmax(0, 1fr); gap: 30px 0; }
        .hv2 .gh-root .gh-side { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; padding-top: 0; align-items: start; }
    }
    /* Mobile: one clean column — greeting, numbers, next move, Ask, work, then setup and recent chats. */
    @media (max-width: 767.98px) {
        .hv2 .gh-root .gh-side { grid-template-columns: minmax(0, 1fr); }
        .hv2 .gh-root .gh-h1 { font-size: 24px; }
        .hv2 .gh-root .gh-metrics { grid-template-columns: minmax(0, 1fr); }
        .hv2 .gh-root .gh-nbm { border-radius: 20px; }
        .hv2 .gh-root .gh-nbm__head { padding: 22px 20px 6px; gap: 16px; }
        .hv2 .gh-root .gh-nbm__title { font-size: 21px; }
        .hv2 .gh-root .gh-desc__panel, .hv2 .gh-root .gh-desc__result { margin-left: 16px; margin-right: 16px; }
        .hv2 .gh-root .gh-nbm__actions, .hv2 .gh-root .gh-desc__actions { padding: 16px 16px 20px; }
        .hv2 .gh-root .gh-desc__next { padding: 14px 16px 20px; }
        .hv2 .gh-root .gh-nbm__actions .gh-btn, .hv2 .gh-root .gh-desc__actions .gh-btn { flex: 1 1 100%; }
        .hv2 .gh-root .gh-desc__note { flex: 1 1 100%; }
        .hv2 .gh-root .gh-desc__spacer { display: none; }
        .hv2 .gh-root .gh-nbm__more summary { padding: 0 20px; }
        .hv2 .gh-root .gh-nbm__more-body { padding: 0 20px 8px; }
        .hv2 .gh-root .gh-row { padding: 14px 16px; flex-wrap: wrap; }
        .hv2 .gh-root .gh-row__when { order: 3; flex: 1 1 100%; text-align: left; min-width: 0; padding-left: 50px; }
        .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-card .card-body { padding: 14px 14px 12px 16px; }
        .hv2 .gh-root .home-ask:not(.is-conversation-open) .home-ask-input { min-height: 3rem; }
        .hv2 .gh-root .gh-tabs { overflow-x: auto; flex-wrap: nowrap; }
        .hv2 .gh-root .gh-tab { flex: none; }
    }
    @media (prefers-reduced-motion: reduce) {
        .hv2 .gh-root .gh-sec, .hv2 .gh-root .gh-st i.gh-live { animation: none; }
        .hv2 .gh-root .gh-btn, .hv2 .gh-root .gh-chev, .hv2 .gh-root .home-ask-submit, .hv2 .gh-root .gh-tab, .hv2 .gh-root .gh-met { transition: none; }
        .hv2 .gh-root .gh-btn:hover, .hv2 .gh-root .home-ask-submit:hover { transform: none; }
    }
</style>
