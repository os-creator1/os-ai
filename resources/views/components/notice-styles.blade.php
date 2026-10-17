{{--
    The one stylesheet behind every customer-facing notification: inline
    page alerts (<x-alert>, <x-flash-alert>) and floating toasts
    (<x-toast-region>) both render the same ".ds-notice" card, so a new
    module that raises either one inherits this design automatically.

    Emitted once per page whichever component renders first.

    Centring: the status icon is a fixed 1.5rem flex box; the glyph inside is
    a block-level SVG with an explicit size, zero margin, zero line-height
    and no inherited baseline alignment, so it cannot drift off-centre.
    The first text line is 1.25rem tall with 0.125rem above it, which puts
    its midline on the icon's centre; extra lines flow below, so the icon
    keeps the same position however long the message is.
--}}
@once('ds-notice-styles')
<style>
    .ds-notice {
        --ds-notice-color: var(--color-text-muted, #6f6d67);
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: flex-start;
        gap: var(--space-3, 0.75rem);
        box-sizing: border-box;
        width: 100%;
        margin: 0 0 var(--space-3, 0.75rem);
        padding: var(--space-3, 0.75rem) var(--space-3, 0.75rem) var(--space-3, 0.75rem) var(--space-4, 1rem);
        background: var(--color-surface, #fff);
        color: var(--color-text-primary, #262522);
        border: 1px solid var(--color-border, #e5e1da);
        border-radius: var(--radius-md, 0.625rem);
        box-shadow: 0 1px 2px rgba(38, 37, 34, 0.04), 0 4px 12px rgba(38, 37, 34, 0.06);
        font-family: var(--font-family-app, inherit);
        font-size: 0.875rem;
        font-weight: 400;
        line-height: 1.25rem;
        text-align: start;
    }
    .ds-notice::before {
        content: "";
        position: absolute;
        inset-block: 0;
        inset-inline-start: 0;
        width: 4px;
        background: var(--ds-notice-color);
    }
    .ds-notice--success { --ds-notice-color: var(--color-status-success, #28c76f); }
    .ds-notice--danger { --ds-notice-color: var(--color-status-danger, #ea5455); }
    .ds-notice--warning { --ds-notice-color: var(--color-status-warning, #ff9f43); }
    .ds-notice--info { --ds-notice-color: #2f6fed; }

    .ds-notice__icon {
        flex: 0 0 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 1.5rem;
        height: 1.5rem;
        margin: 0;
        padding: 0;
        border-radius: 50%;
        background: var(--ds-notice-color);
        color: #fff;
        line-height: 0;
        font-size: 0;
        vertical-align: top;
    }
    .ds-notice__icon svg {
        display: block;
        flex: none;
        width: 0.875rem;
        height: 0.875rem;
        margin: 0;
        padding: 0;
        vertical-align: baseline;
        line-height: 0;
        stroke: currentColor;
    }
    .ds-notice__body {
        flex: 1 1 auto;
        min-width: 0;
        padding-top: 0.125rem;
        overflow-wrap: anywhere;
        word-break: normal;
    }
    .ds-notice__title,
    .ds-notice__text > strong.d-block {
        display: block;
        margin: 0;
        font-size: 0.9375rem;
        font-weight: 600;
        line-height: 1.25rem;
        color: var(--color-text-primary, #262522);
    }
    .ds-notice__text {
        margin: 0;
        color: var(--color-text-primary, #262522);
    }
    .ds-notice__title + .ds-notice__text {
        color: var(--color-text-secondary, #676664);
        margin-top: 0.125rem;
        font-size: 0.8125rem;
    }
    .ds-notice__text > :last-child { margin-bottom: 0; }
    .ds-notice__close {
        flex: 0 0 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 1.5rem;
        height: 1.5rem;
        margin: 0;
        padding: 0;
        border: 0;
        border-radius: var(--radius-sm, 0.375rem);
        background: transparent;
        color: var(--color-text-muted, #6f6d67);
        line-height: 0;
        cursor: pointer;
    }
    .ds-notice__close svg {
        display: block;
        width: 0.875rem;
        height: 0.875rem;
        stroke: currentColor;
    }
    .ds-notice__close:hover {
        color: var(--color-text-primary, #262522);
        background: var(--color-row-hover, rgba(0, 0, 0, 0.04));
    }
    .ds-notice__close:focus-visible {
        outline: 2px solid var(--color-focus-ring, #b5524c);
        outline-offset: 1px;
    }
</style>
<script>
    // Dismiss for every inline notice; toasts bind their own button.
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-ds-notice-dismiss]') : null;
        var notice = button ? button.closest('.ds-notice') : null;
        if (notice) {
            notice.remove();
        }
    });
</script>
@endonce
