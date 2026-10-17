{{--
    Home V2 — the command center's small visual layer.

    Inline and token-only, on the same footing as the Calendar module's
    _styles: every colour is a `var(--color-*)` runtime custom property from the
    shared token namespace (so a per-tenant theme override still applies), and
    no hex value is introduced here beyond each var()'s fallback. Surfaces are
    white cards on the off-white canvas, subtle borders, one accent.
--}}
<style>
    .home-command .home-greeting-title { font-size: 1.5rem; font-weight: 600; line-height: 1.25; color: var(--color-text-primary, #1F1D1A); }
    .home-command .home-briefing { color: var(--color-text-muted, #6F6D67); max-width: 60ch; }

    .home-command .home-move { border: 1px solid var(--color-border, #E5E1DA); border-left: 4px solid var(--color-primary, #B5472D); border-radius: .75rem; background: var(--color-surface, #fff); }
    .home-command .home-move[data-severity="blocking"] { border-left-color: var(--color-status-danger, #B3261E); }
    .home-command .home-move-headline { font-size: 1.375rem; font-weight: 600; line-height: 1.3; }
    .home-command .home-eyebrow { font-size: .75rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
    .home-command .home-status { display: inline-flex; align-items: center; gap: .375rem; font-size: .8125rem; padding: .125rem .5rem; border-radius: 999px; background: var(--color-primary-soft-bg, #F7E9E4); color: var(--color-text-primary, #1F1D1A); }
    .home-command .home-evidence { list-style: none; padding: 0; margin: 0; }
    .home-command .home-evidence li { padding-left: 1rem; position: relative; font-size: .875rem; color: var(--color-text-muted, #6F6D67); }
    .home-command .home-evidence li::before { content: ""; position: absolute; left: 0; top: .55em; width: .35rem; height: .35rem; border-radius: 50%; background: var(--color-border-strong, #C9C3B8); }

    .home-command .home-status[data-status="attention"] { background: var(--color-primary-soft-bg, #F7E9E4); color: var(--color-primary, #B5472D); }
    .home-command .home-status[data-status="clear"] { background: var(--color-status-success-soft-bg, #E6F3EA); color: var(--color-status-success, #1E7B3C); }
    .home-command .home-status[data-status="unavailable"] { background: var(--color-surface-secondary, #F4F1EC); color: var(--color-text-muted, #6F6D67); }
    .home-command .home-status[data-status="setup"] { background: var(--color-surface-secondary, #F4F1EC); color: var(--color-text-primary, #1F1D1A); }

    .home-command .home-attn-item { border-top: 1px solid var(--color-border-subtle, #EEEAE3); padding: .75rem 0; }
    .home-command .home-attn-item:first-child { border-top: 0; padding-top: 0; }
    .home-command .home-kind { font-size: .6875rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; padding: .0625rem .375rem; border-radius: .25rem; }
    .home-command .home-kind[data-kind="problem"] { background: var(--color-status-danger-soft-bg, #FBE9E7); color: var(--color-status-danger, #B3261E); }
    .home-command .home-kind[data-kind="approval"] { background: var(--color-primary-soft-bg, #F7E9E4); color: var(--color-primary, #B5472D); }
    .home-command .home-kind[data-kind="info"], .home-command .home-kind[data-kind="processing"] { background: var(--color-surface-secondary, #F4F1EC); color: var(--color-text-muted, #6F6D67); }

    .home-command .home-step { display: flex; gap: .75rem; padding: .75rem 0; border-top: 1px solid var(--color-border-subtle, #EEEAE3); }
    .home-command .home-step:first-child { border-top: 0; }
    .home-command .home-step-mark { flex: 0 0 1.75rem; height: 1.75rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: .8125rem; font-weight: 600; border: 1px solid var(--color-border, #E5E1DA); color: var(--color-text-muted, #6F6D67); }
    .home-command .home-step[data-done="true"] .home-step-mark { background: var(--color-status-success-soft-bg, #E6F3EA); border-color: var(--color-status-success, #1E7B3C); color: var(--color-status-success, #1E7B3C); }
    .home-command .home-step[data-next="true"] .home-step-mark { border-color: var(--color-primary, #B5472D); color: var(--color-primary, #B5472D); }

    .home-command .home-ask-form { display: flex; gap: .5rem; flex-wrap: wrap; }
    .home-command .home-ask-form input[type="text"] { flex: 1 1 14rem; min-width: 0; }
    .home-command .home-chip { border: 1px solid var(--color-border, #E5E1DA); background: var(--color-surface, #fff); color: var(--color-text-primary, #1F1D1A); border-radius: 999px; padding: .25rem .75rem; font-size: .8125rem; }
    .home-command .home-chip:hover { background: var(--color-row-hover, #F7F4EF); }


    /* Ask MotionGrove — the conversation lives inside the Ask card. */
    .home-command .home-ask-panel { border-top: 1px solid var(--color-border-subtle, #EEEAE3); padding-top: .75rem; }
    .home-command .home-ask-thread { overflow-y: auto; display: flex; flex-direction: column; gap: .625rem; padding: .25rem 0 .5rem; }
    .home-command .home-ask-msg[data-from="user"] { align-self: flex-end; max-width: 85%; }
    .home-command .home-ask-msg[data-from="assistant"] { align-self: flex-start; max-width: 100%; }
    .home-command .home-ask-bubble { border-radius: .75rem; padding: .625rem .875rem; background: var(--color-surface-secondary, #F4F1EC); color: var(--color-text-primary, #1F1D1A); overflow-wrap: anywhere; }
    .home-command .home-ask-msg[data-from="user"] .home-ask-bubble { background: var(--color-primary-soft-bg, #F7E9E4); }
    .home-command .home-ask-bubble.is-limit { background: var(--color-primary-soft-bg, #F7E9E4); border: 1px solid var(--color-border, #E5E1DA); }
    .home-command .home-ask-bubble.is-error { background: var(--color-status-danger-soft-bg, #FBE9E7); }
    .home-command .home-ask-title { font-weight: 600; }
    .home-command .home-ask-label { font-size: .6875rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
    .home-command .home-ask-facts, .home-command .home-ask-limits, .home-command .home-ask-linkbox { margin-top: .625rem; padding-top: .5rem; border-top: 1px solid var(--color-border-subtle, #EEEAE3); font-size: .875rem; }
    .home-command .home-ask-fact { display: flex; justify-content: space-between; gap: .75rem; padding: .125rem 0; }
    .home-command .home-ask-fact dt { font-weight: 400; color: var(--color-text-muted, #6F6D67); }
    .home-command .home-ask-fact dd { margin: 0; font-weight: 600; text-align: right; }
    .home-command .home-ask-proposal { margin-top: .625rem; padding: .75rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .625rem; background: var(--color-surface, #fff); }
    .home-command .home-ask-result { margin: .5rem 0; font-size: .875rem; }
    .home-command .home-ask-result:empty { display: none; }
    .home-command .home-ask-result[data-kind="success"] { color: var(--color-status-success, #1E7B3C); }
    .home-command .home-ask-result[data-kind="error"] { color: var(--color-status-danger, #B3261E); }
    .home-command .home-ask-result[data-kind="muted"] { color: var(--color-text-muted, #6F6D67); }
    .home-command .home-ask-banner { margin: .5rem 0; padding: .5rem .75rem; border-radius: .5rem; font-size: .875rem; background: var(--color-surface-secondary, #F4F1EC); }
    .home-command .home-ask-banner[data-kind="limit"] { background: var(--color-primary-soft-bg, #F7E9E4); }
    .home-command .home-ask-links a { text-decoration: underline; }


    .home-command .home-handled-row { display: flex; gap: .75rem; align-items: baseline; padding: .5rem 0; border-top: 1px solid var(--color-border-subtle, #EEEAE3); }
    .home-command .home-handled-row:first-child { border-top: 0; }
    .home-command .home-handled-state { flex: 0 0 8.5rem; font-size: .8125rem; font-weight: 600; }
    .home-command .home-handled-state[data-status="completed"] { color: var(--color-status-success, #1E7B3C); }
    .home-command .home-handled-state[data-status="failed"] { color: var(--color-status-danger, #B3261E); }
    .home-command .home-handled-state[data-status="progress"], .home-command .home-handled-state[data-status="approval"] { color: var(--color-primary, #B5472D); }

    .home-drawer { --bs-offcanvas-width: min(32rem, 100vw); }
    .home-command .home-drawer-form label { font-weight: 500; }
    .home-command .home-drawer-result { border-radius: .5rem; padding: .75rem; }
    .home-command .home-drawer-result[data-kind="success"] { background: var(--color-status-success-soft-bg, #E6F3EA); }
    .home-command .home-drawer-result[data-kind="error"] { background: var(--color-status-danger-soft-bg, #FBE9E7); }

    @media (max-width: 575.98px) {
        .home-command .home-greeting-title { font-size: 1.25rem; }
        .home-command .home-move-headline { font-size: 1.1875rem; }
        .home-command .home-handled-row { flex-direction: column; gap: .125rem; }
        .home-command .home-handled-state { flex-basis: auto; }
    }
    /* ── Home redesign: greeting row, move card with metric, compact Ask, panels, performance ─────────────────────── */
    .home-command .home-greeting-row { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .5rem 1rem; }
    .home-command .home-greeting-text { min-width: 0; flex: 1 1 20rem; }
    .home-command .home-status-pill { display: inline-flex; align-items: center; gap: .4rem; white-space: nowrap; font-weight: 600; padding: .3rem .75rem; border: 1px solid var(--color-border-subtle, #EEEAE3); }
    .home-command .home-status-dot { width: .4rem; height: .4rem; border-radius: 50%; background: currentColor; }

    .home-command .home-move { border: 1px solid var(--color-primary-soft-border, #EBCFC8); border-left-width: 1px; border-radius: .75rem; background: var(--color-surface, #fff); overflow: hidden; }
    .home-command .home-move[data-severity="blocking"] { border-left: 4px solid var(--color-status-danger, #B3261E); }
    .home-command .home-move-main { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.25rem; padding: 1.25rem 1.5rem; }
    .home-command .home-move-main.has-metric { grid-template-columns: minmax(0, 1fr) minmax(14rem, 21rem); align-items: center; }
    .home-command .home-move-metric { border: 1px solid var(--color-border-subtle, #EEEAE3); border-radius: .75rem; background: var(--color-surface-secondary, #F7F4EF); padding: 1rem 1.25rem; }
    .home-command .home-metric-figure { font-size: 2.5rem; font-weight: 600; line-height: 1; color: var(--color-primary, #B5472D); }
    .home-command .home-metric-caption { font-size: .875rem; color: var(--color-text-muted, #6F6D67); }
    .home-command .home-metric-bar { display: flex; gap: .25rem; margin: .625rem 0 .5rem; }
    .home-command .home-metric-bar span { flex: 1 1 0; height: .375rem; border-radius: 999px; background: var(--color-border, #E5E1DA); }
    .home-command .home-metric-bar span.on { background: var(--color-primary, #B5472D); }
    .home-command .home-move-foot { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .625rem 1.5rem; border-top: 1px solid var(--color-border-subtle, #EEEAE3); }
    .home-command .home-move-foot summary { cursor: pointer; }
    .home-command .home-move-note { padding: 0 1.5rem 1rem; }
    .home-command .home-ai-spark { color: var(--color-primary, #B5472D); }

    .home-command .home-ask-card .card-body { padding: .875rem 1.25rem; }
    .home-command .home-ask-form { display: flex; align-items: center; gap: .75rem; flex-wrap: nowrap; }
    .home-command .home-ask-icon { color: var(--color-primary, #B5472D); flex: 0 0 auto; }
    .home-command .home-ask-input { flex: 1 1 auto; min-width: 0; border: 0; outline: 0; background: transparent; padding: .5rem 0; font-size: .9375rem; color: var(--color-text-primary, #1F1D1A); }
    .home-command .home-ask-input::placeholder { color: var(--color-text-muted, #6F6D67); }
    .home-command .home-ask-resume { white-space: nowrap; font-size: .8125rem; }
    .home-command .home-chip { color: var(--color-primary, #B5472D); border-color: var(--color-primary-soft-border, #EBCFC8); }

    .home-panel-title { font-size: 1rem; font-weight: 600; color: var(--color-text-primary, #1F1D1A); }
    .home-rows .home-row { display: flex; align-items: center; gap: .75rem; padding: .75rem 0; border-top: 1px solid var(--color-border-subtle, #EEEAE3); color: inherit; text-decoration: none; }
    .home-rows a.home-row:hover { background: var(--color-row-hover, #F7F4EF); }
    .home-row-icon { flex: 0 0 2.25rem; height: 2.25rem; border-radius: .5rem; display: inline-flex; align-items: center; justify-content: center; background: var(--color-primary-soft-bg, #F7E9E4); color: var(--color-primary, #B5472D); }
    .home-row-text { display: flex; flex-direction: column; flex: 1 1 auto; min-width: 0; }
    .home-row-chevron { color: var(--color-text-muted, #6F6D67); font-size: 1.25rem; }
    .home-row-link { white-space: nowrap; font-size: .8125rem; font-weight: 600; color: var(--color-primary, #B5472D); }
    .home-bottom-row .home-panel { min-height: 100%; }

    .home-performance .home-perf-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .75rem 1rem; margin-bottom: 1rem; }
    .home-performance .home-perf-controls { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
    .home-performance .home-range-form { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
    .home-performance .home-segmented { display: inline-flex; padding: .1875rem; border-radius: .625rem; background: var(--color-surface-secondary, #F4F1EC); }
    .home-performance .home-segmented button { border: 0; background: transparent; border-radius: .5rem; padding: .3rem .75rem; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); }
    .home-performance .home-segmented button.is-active { background: var(--color-surface, #fff); color: var(--color-text-primary, #1F1D1A); font-weight: 600; box-shadow: 0 1px 2px rgba(0, 0, 0, .08); }
    .home-performance .home-metrics { display: grid; grid-template-columns: repeat(var(--home-metric-count, 3), minmax(0, 1fr)); border: 1px solid var(--color-border-subtle, #EEEAE3); border-radius: .75rem; overflow: hidden; }
    .home-performance .home-metric-cell + .home-metric-cell { border-left: 1px solid var(--color-border-subtle, #EEEAE3); }
    .home-performance .home-metric { padding: .875rem 1rem; }
    .home-performance .home-metric-label { font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
    .home-performance .home-metric-value { font-size: 1.75rem; font-weight: 600; line-height: 1.2; color: var(--color-text-primary, #1F1D1A); }
    .home-performance .home-metric-compare { font-size: .8125rem; color: var(--color-text-muted, #6F6D67); }
    .home-performance .home-chart { margin-top: 1rem; }
    .home-performance .home-chart-title { font-size: .8125rem; font-weight: 600; }
    .home-chart-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .125rem; min-height: 120px; text-align: center; border-radius: .75rem; background: var(--color-surface-secondary, #F7F4EF); color: var(--color-text-muted, #6F6D67); padding: 1rem; }
    .home-chart-empty strong { color: var(--color-text-primary, #1F1D1A); font-size: .875rem; }
    .home-chart-empty span { font-size: .8125rem; }

    @media (max-width: 991.98px) {
        .home-command .home-move-main.has-metric { grid-template-columns: minmax(0, 1fr); }
        .home-performance .home-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .home-performance .home-metric-cell:nth-child(odd) { border-left: 0; }
        .home-performance .home-metric-cell:nth-child(n+3) { border-top: 1px solid var(--color-border-subtle, #EEEAE3); }
    }
    @media (max-width: 575.98px) {
        .home-command .home-move-main { padding: 1rem; }
        .home-command .home-move-foot, .home-command .home-move-note { padding-left: 1rem; padding-right: 1rem; }
        .home-command .home-ask-form { flex-wrap: wrap; }
        .home-command .home-ask-input { flex-basis: calc(100% - 2rem); }
        .home-performance .home-metric-value { font-size: 1.5rem; }
    }
    /* ── Ask MotionGrove: one card, two states. Collapsed = the compact box; `.is-conversation-open` = the full conversation. ─── */
    .home-command .home-ask-card { view-transition-name: home-ask-card; }
    .home-command .home-ask-head, .home-command .home-ask-foot { display: none; }
    .home-command .home-ask-heading { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; margin: 0; font-size: 1rem; }
    .home-command .home-ask-input { resize: none; overflow-y: auto; line-height: 1.5; max-height: 10.5rem; }
    .home-command .home-ask-submit-icon { display: none; }
    .home-command .home-ask-panel[hidden] { display: none; }

    /* Everything else on Home recedes; the Ask card is the page. */
    .home-command.is-chat-open > *:not(.home-ask) { display: none !important; }
    .home-command.is-chat-open .home-ask { margin: 0 !important; }
    .home-command .home-ask.is-conversation-open .home-ask-card { display: flex; flex-direction: column; height: calc(100vh - 14.5rem); height: calc(100dvh - 14.5rem); min-height: 26rem; border-radius: 1rem; }
    .home-command .home-ask.is-conversation-open .home-ask-card > .card-body { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; padding: 0; }
    .home-command .home-ask.is-conversation-open .home-ask-head { display: grid; grid-template-columns: auto auto 1fr auto; align-items: center; gap: .75rem; padding: .75rem 1rem; border-bottom: 1px solid var(--color-border-subtle, #EEEAE3); flex: 0 0 auto; }
    .home-command .home-ask.is-conversation-open .home-ask-heading { position: static; width: auto; height: auto; clip: auto; grid-column: 3; justify-self: center; font-size: .9375rem; font-weight: 600; margin: 0; }
    .home-command .home-ask-head-actions { display: flex; align-items: center; gap: .5rem; grid-column: 4; }
    .home-command .home-ask-newchat { background: var(--color-surface-secondary, #F4F1EC); border: 0; font-weight: 500; }
    .home-command .home-ask-esc kbd { font-size: .6875rem; padding: .0625rem .375rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .25rem; background: var(--color-surface, #fff); color: inherit; }
    .home-command .home-ask.is-conversation-open .home-ask-panel { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; border: 0; padding: 0; margin: 0 !important; }
    .home-command .home-ask.is-conversation-open .home-ask-thread { flex: 1 1 auto; min-height: 0; max-height: none; overflow-y: auto; overscroll-behavior: contain; width: 100%; padding: 1.25rem max(1rem, calc((100% - 46rem) / 2)) 1rem; gap: 1.25rem; }
    .home-command .home-ask.is-conversation-open .home-ask-banner { margin: .5rem 1rem 0; }
    .home-command .home-ask.is-conversation-open .home-ask-working { max-width: 46rem; width: 100%; margin: 0 auto !important; padding: 0 1rem .5rem; font-size: .875rem; }
    .home-command .home-ask.is-conversation-open [data-role="ask-suggestions"] { display: none !important; }
    .home-command .home-ask.is-conversation-open .home-ask-icon { display: none; }
    .home-command .home-ask.is-conversation-open .home-ask-resume { display: none; }
    .home-command .home-ask.is-conversation-open .home-ask-form { flex: 0 0 auto; align-items: flex-end; width: calc(100% - 2rem); max-width: 46rem; margin: 0 auto; padding: .625rem .75rem .625rem 1rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: 1rem; background: var(--color-surface, #fff); box-shadow: 0 2px 8px rgba(0, 0, 0, .06); }
    .home-command .home-ask.is-conversation-open .home-ask-form:focus-within { border-color: var(--color-border-strong, #C9C3B8); }
    .home-command .home-ask.is-conversation-open .home-ask-submit { width: 2.25rem; height: 2.25rem; padding: 0; border-radius: 50%; background: var(--color-text-primary, #1F1D1A) !important; border-color: var(--color-text-primary, #1F1D1A) !important; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; }
    .home-command .home-ask.is-conversation-open .home-ask-submit-label { display: none; }
    .home-command .home-ask.is-conversation-open .home-ask-submit-icon { display: inline-block; width: .75rem; height: .75rem; border-top: 2px solid #fff; border-left: 2px solid #fff; transform: translateY(2px) rotate(45deg); }
    .home-command .home-ask.is-conversation-open.is-busy .home-ask-submit-icon { width: .625rem; height: .625rem; border: 0; background: #fff; border-radius: .125rem; transform: none; animation: home-ask-pulse 1.1s ease-in-out infinite; }
    .home-command .home-ask.is-conversation-open .home-ask-foot { display: block; text-align: center; padding: .5rem 1rem .75rem; flex: 0 0 auto; }
    .home-command .home-ask-foot summary { cursor: pointer; display: inline-block; text-decoration: underline; list-style: none; }
    .home-command .home-ask-foot summary::-webkit-details-marker { display: none; }
    .home-command .home-ask-foot p { max-width: 46rem; margin-left: auto !important; margin-right: auto !important; text-align: left; }

    /* Messages: the customer's on the right in a neutral bubble; MotionGrove's on the left as plain text with its evidence beneath. */
    .home-command .home-ask-msg[data-from="user"] .home-ask-bubble { background: var(--color-surface-secondary, #F4F1EC); border-radius: 1rem; }
    .home-command .home-ask-msg[data-from="assistant"] .home-ask-bubble { background: transparent; padding: .125rem 0; border-radius: 0; }
    .home-command .home-ask-msg[data-from="assistant"] .home-ask-bubble.is-limit,
    .home-command .home-ask-msg[data-from="assistant"] .home-ask-bubble.is-error,
    .home-command .home-ask-msg[data-from="assistant"] .home-ask-bubble.is-notice { padding: .75rem 1rem; border-radius: .75rem; }
    .home-command .home-ask-msg[data-from="assistant"] .home-ask-bubble.is-notice { background: var(--color-surface-secondary, #F4F1EC); }
    .home-command .home-ask-facts { border: 1px solid var(--color-border-subtle, #EEEAE3); border-radius: .75rem; background: var(--color-surface-secondary, #F7F4EF); padding: .5rem 1rem .625rem; margin-top: .75rem; }
    .home-command .home-ask-facts .home-ask-fact { border-top: 1px solid var(--color-border-subtle, #EEEAE3); padding: .5rem 0; }
    .home-command .home-ask-facts .home-ask-fact:first-child { border-top: 0; }
    .home-command .home-ask-actions { display: flex; gap: .5rem; margin-top: .375rem; }
    .home-command .home-ask-action { border: 0; background: transparent; padding: .125rem .375rem; border-radius: .375rem; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
    .home-command .home-ask-action:hover { background: var(--color-surface-secondary, #F4F1EC); }
    .home-command .home-ask-empty { text-align: center; padding: 3rem 0 1rem; }
    .home-command .home-ask-empty ul { justify-content: center; }
    @keyframes home-ask-pulse { 50% { opacity: .45; } }
    @keyframes home-ask-fade { from { opacity: 0; } to { opacity: 1; } }
    .home-command .home-ask.is-conversation-open .home-ask-head, .home-command .home-ask.is-conversation-open .home-ask-thread, .home-command .home-ask.is-conversation-open .home-ask-foot { animation: home-ask-fade .22s ease both; }
    ::view-transition-group(home-ask-card) { animation-duration: .28s; animation-timing-function: cubic-bezier(.2, .7, .2, 1); }
    ::view-transition-old(root), ::view-transition-new(root) { animation-duration: .2s; }
    @media (prefers-reduced-motion: reduce) {
        .home-command .home-ask.is-conversation-open .home-ask-head, .home-command .home-ask.is-conversation-open .home-ask-thread, .home-command .home-ask.is-conversation-open .home-ask-foot { animation: none; }
        ::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) { animation-duration: 0s !important; }
    }

    /* On a phone the open conversation is the whole screen: the card becomes a sheet and the composer rides above the keyboard. */
    @media (max-width: 767.98px) {
        .home-command .home-ask.is-conversation-open { position: fixed; inset: 0; z-index: 1045; margin: 0 !important; background: var(--color-canvas, #FAF8F5); }
        .home-command .home-ask.is-conversation-open .home-ask-card { height: 100%; border-radius: 0; border: 0; }
        .home-command .home-ask.is-conversation-open .home-ask-head { grid-template-columns: auto 1fr auto; padding: .5rem .75rem; }
        .home-command .home-ask.is-conversation-open .home-ask-esc { display: none; }
        .home-command .home-ask.is-conversation-open .home-ask-heading { grid-column: 2; }
        .home-command .home-ask.is-conversation-open .home-ask-head-actions { grid-column: 3; }
        .home-command .home-ask.is-conversation-open .home-ask-form { width: calc(100% - 1rem); margin-bottom: .25rem; }
        .home-command .home-ask-msg[data-from="user"] { max-width: 92%; }
        .home-command .home-ask.is-conversation-open .home-ask-head { gap: .5rem; }
        .home-command .home-ask.is-conversation-open .home-ask-back { white-space: nowrap; }
        .home-command .home-ask.is-conversation-open .home-ask-heading { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .home-command .home-ask.is-conversation-open .home-ask-newchat { white-space: nowrap; }
        .home-command .home-ask.is-conversation-open [data-role="ask-delete"] { display: none; }
        .home-command .home-ask.is-conversation-open .home-ask-form { flex-wrap: nowrap; }
        .home-command .home-ask.is-conversation-open .home-ask-input { flex: 1 1 auto; }
    }
    /* ── Chat history panel (inside the open conversation) and the older-messages marker ───────────────────────────────── */
    .home-command .home-ask-panel { position: relative; }
    .home-command .home-ask-iconbtn { border: 0; background: transparent; color: var(--color-text-muted, #6F6D67); padding: .375rem .5rem; border-radius: .5rem; display: inline-flex; align-items: center; }
    .home-command .home-ask-iconbtn:hover, .home-command .home-ask-iconbtn[aria-expanded="true"] { background: var(--color-surface-secondary, #F4F1EC); color: var(--color-text-primary, #1F1D1A); }
    .home-command .home-ask-history { position: absolute; inset: 0; z-index: 4; display: flex; flex-direction: column; background: var(--color-surface, #fff); overflow-y: auto; overscroll-behavior: contain; animation: home-ask-fade .18s ease both; }
    .home-command .home-ask-history[hidden] { display: none; }
    .home-command .home-ask-history-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .875rem max(1rem, calc((100% - 46rem) / 2)) .5rem; position: sticky; top: 0; background: var(--color-surface, #fff); }
    .home-command .home-ask-history-list { padding: 0 max(.5rem, calc((100% - 46rem) / 2)) .5rem; }
    .home-command .home-ask-hist-row { position: relative; border-top: 1px solid var(--color-border-subtle, #EEEAE3); }
    .home-command .home-ask-hist-row:first-child { border-top: 0; }
    .home-command .home-ask-hist-item { display: flex; flex-direction: column; gap: .125rem; width: 100%; text-align: left; border: 0; background: transparent; padding: .75rem 4.5rem .75rem .5rem; border-radius: .5rem; color: inherit; }
    .home-command .home-ask-hist-item:hover { background: var(--color-row-hover, #F7F4EF); }
    .home-command .home-ask-hist-item.is-current { background: var(--color-primary-soft-bg, #F7E9E4); }
    .home-command .home-ask-hist-top { display: flex; justify-content: space-between; gap: .75rem; align-items: baseline; }
    .home-command .home-ask-hist-title { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
    .home-command .home-ask-hist-when { white-space: nowrap; flex: 0 0 auto; }
    .home-command .home-ask-hist-preview { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
    .home-command .home-ask-hist-del { position: absolute; right: .5rem; bottom: .625rem; border: 0; background: transparent; font-size: .75rem; color: var(--color-text-muted, #6F6D67); padding: .125rem .375rem; border-radius: .375rem; }
    .home-command .home-ask-hist-del:hover, .home-command .home-ask-hist-del:focus-visible { color: var(--color-status-danger, #B3261E); background: var(--color-status-danger-soft-bg, #FBE9E7); }
    .home-command .home-ask-hist-confirm { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; padding: .625rem .5rem .75rem; font-size: .875rem; background: var(--color-status-danger-soft-bg, #FBE9E7); border-radius: .5rem; margin: 0 .25rem .5rem; }
    .home-command .home-ask-hist-row.is-confirming .home-ask-hist-del { display: none; }
    .home-command .home-ask-older { padding: .25rem 0; }
    @media (max-width: 767.98px) {
        .home-command .home-ask-hist-item { padding-right: 4rem; }
    }
</style>
