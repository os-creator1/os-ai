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
</style>
