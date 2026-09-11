@props([
    'toasts' => [],
])

{{--
    Customer notification cleanup — the one Business OS toast, used on every
    customer-facing page in place of the inherited Toastr notifications
    (App\Library\Feedback\PageToasts decides which pages and which toasts).

    Compact, text-only, token-coloured, in four variants. Success and info
    leave on their own; warnings and errors stay long enough to read, and
    every toast pauses while hovered or focused and can be dismissed.
    Toasts are added to a live region after the page has loaded, so
    assistive technology announces them: errors and warnings as alerts,
    everything else as status.

    Legacy page scripts keep calling toastr[variant](message, title, options):
    window.toastr here renders those calls as Business OS toasts. The title
    they pass is inherited Ultimate SMS boilerplate ("Attention", "Opps...")
    and the options only position Toastr, so both are ignored.
--}}
<style>
    .ds-toast-region {
        position: fixed;
        /* below the fixed header, so its account and notification controls stay reachable */
        top: calc(4.5rem + var(--space-3, 0.75rem));
        inset-inline-end: var(--space-4, 1rem);
        z-index: 1090;
        display: flex;
        flex-direction: column;
        gap: var(--space-2, 0.5rem);
        width: min(22.5rem, calc(100vw - 2 * var(--space-4, 1rem)));
        pointer-events: none;
    }
    .ds-toast {
        --ds-toast-accent: var(--color-status-info-icon, #00cfe8);
        pointer-events: auto;
        display: flex;
        align-items: flex-start;
        gap: var(--space-3, 0.75rem);
        padding: var(--space-3, 0.75rem) var(--space-3, 0.75rem) var(--space-3, 0.75rem) var(--space-4, 1rem);
        background: var(--color-surface, #fff);
        color: var(--color-text-primary, #262522);
        border: 1px solid var(--color-border, #e5e1da);
        border-inline-start: 4px solid var(--ds-toast-accent);
        border-radius: var(--radius-md, 0.625rem);
        box-shadow: var(--shadow-lg, 0 8px 24px rgba(0, 0, 0, 0.08));
        font-family: var(--font-family-app, inherit);
        font-size: 1rem;
        line-height: 1.45;
    }
    .ds-toast--success { --ds-toast-accent: var(--color-status-success-icon, #28c76f); }
    .ds-toast--warning { --ds-toast-accent: var(--color-status-warning-icon, #ff9f43); }
    .ds-toast--error { --ds-toast-accent: var(--color-status-danger-icon, #ea5455); }
    .ds-toast__icon {
        flex-shrink: 0;
        display: inline-flex;
        padding-top: 0.0625rem;
        color: var(--ds-toast-accent);
    }
    .ds-toast__body {
        flex: 1 1 auto;
        min-width: 0;
        overflow-wrap: anywhere;
    }
    .ds-toast__title {
        display: block;
        font-weight: 600;
    }
    .ds-toast__title + .ds-toast__message {
        color: var(--color-text-secondary, #676664);
    }
    .ds-toast__close {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.5rem;
        height: 1.5rem;
        padding: 0;
        border: 0;
        border-radius: var(--radius-sm, 0.375rem);
        background: transparent;
        color: var(--color-text-muted, #6f6d67);
        cursor: pointer;
    }
    .ds-toast__close:hover {
        color: var(--color-text-primary, #262522);
        background: var(--color-row-hover, rgba(0, 0, 0, 0.04));
    }
    .ds-toast__close:focus-visible {
        outline: 2px solid var(--color-focus-ring, #b5524c);
        outline-offset: 1px;
    }
    @media (prefers-reduced-motion: no-preference) {
        .ds-toast {
            animation: ds-toast-in var(--duration-base, 180ms) var(--ease-standard, ease-out);
        }
        .ds-toast.is-leaving {
            opacity: 0;
            transform: translateY(-0.25rem);
            transition: opacity var(--duration-base, 180ms) var(--ease-standard, ease-out), transform var(--duration-base, 180ms) var(--ease-standard, ease-out);
        }
    }
    @keyframes ds-toast-in {
        from { opacity: 0; transform: translateY(-0.25rem); }
        to { opacity: 1; transform: none; }
    }
    @media (max-width: 575.98px) {
        .ds-toast-region {
            inset-inline: var(--space-3, 0.75rem);
            width: auto;
        }
    }
</style>

<div class="ds-toast-region" data-role="toast-region" aria-live="polite" aria-relevant="additions"></div>

<template data-role="toast-icons">
    <span data-variant="success"><x-ds-icon name="check-circle" size="18" aria-hidden="true" /></span>
    <span data-variant="info"><x-ds-icon name="info" size="18" aria-hidden="true" /></span>
    <span data-variant="warning"><x-ds-icon name="alert-triangle" size="18" aria-hidden="true" /></span>
    <span data-variant="error"><x-ds-icon name="alert-circle" size="18" aria-hidden="true" /></span>
    <span data-variant="close"><x-ds-icon name="x" size="16" aria-hidden="true" /></span>
</template>

<script type="application/json" data-role="toast-initial">@json(array_values($toasts))</script>

<script>
    (function () {
        'use strict';

        var region = document.querySelector('[data-role="toast-region"]');
        var icons = document.querySelector('template[data-role="toast-icons"]');
        var VISIBLE_AT_MOST = 3;
        var DURATION = { success: 5000, info: 6000, warning: 10000, error: 10000 };
        var SPOKEN_PREFIX = { warning: 'Warning: ', error: 'Error: ' };

        function icon(name) {
            var holder = icons ? icons.content.querySelector('[data-variant="' + name + '"]') : null;
            return holder ? holder.firstElementChild.cloneNode(true) : document.createElement('span');
        }

        // Toastr treated its message as HTML; this toast shows text only.
        // DOMParser builds an inert document, so nothing in the string runs.
        function plainText(value) {
            var text = value === null || value === undefined ? '' : String(value);
            if (/[<&]/.test(text)) {
                text = new DOMParser().parseFromString(text, 'text/html').body.textContent || '';
            }
            return text.replace(/\s+/g, ' ').trim();
        }

        function dismiss(toast) {
            if (!toast.parentNode || toast.classList.contains('is-leaving')) {
                return;
            }
            window.clearTimeout(toast.dsToastTimer);
            toast.classList.add('is-leaving');
            var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.setTimeout(function () { toast.remove(); }, reduceMotion ? 0 : 200);
        }

        function startTimer(toast) {
            window.clearTimeout(toast.dsToastTimer);
            toast.dsToastTimer = window.setTimeout(function () { dismiss(toast); }, DURATION[toast.dataset.variant]);
        }

        function show(options) {
            options = options || {};
            var variant = Object.prototype.hasOwnProperty.call(DURATION, options.variant) ? options.variant : 'info';
            var title = plainText(options.title);
            var message = plainText(options.message);

            if (!region || (title === '' && message === '')) {
                return null;
            }

            var key = variant + '|' + title + '|' + message;
            var shown = Array.prototype.find.call(region.children, function (existing) {
                return existing.dataset.key === key && !existing.classList.contains('is-leaving');
            });
            if (shown) {
                startTimer(shown);
                return shown;
            }

            var toast = document.createElement('div');
            toast.className = 'ds-toast ds-toast--' + variant;
            toast.dataset.variant = variant;
            toast.dataset.key = key;
            toast.setAttribute('role', SPOKEN_PREFIX[variant] ? 'alert' : 'status');

            var iconHolder = document.createElement('span');
            iconHolder.className = 'ds-toast__icon';
            iconHolder.appendChild(icon(variant));

            var body = document.createElement('div');
            body.className = 'ds-toast__body';
            if (SPOKEN_PREFIX[variant]) {
                var prefix = document.createElement('span');
                prefix.className = 'visually-hidden';
                prefix.textContent = SPOKEN_PREFIX[variant];
                body.appendChild(prefix);
            }
            if (title !== '') {
                var heading = document.createElement('strong');
                heading.className = 'ds-toast__title';
                heading.textContent = title;
                body.appendChild(heading);
            }
            if (message !== '') {
                var text = document.createElement('div');
                text.className = 'ds-toast__message';
                text.textContent = message;
                body.appendChild(text);
            }

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'ds-toast__close';
            close.setAttribute('aria-label', 'Dismiss notification');
            close.appendChild(icon('close'));
            close.addEventListener('click', function () { dismiss(toast); });

            toast.appendChild(iconHolder);
            toast.appendChild(body);
            toast.appendChild(close);

            toast.addEventListener('mouseenter', function () { window.clearTimeout(toast.dsToastTimer); });
            toast.addEventListener('mouseleave', function () { startTimer(toast); });
            toast.addEventListener('focusin', function () { window.clearTimeout(toast.dsToastTimer); });
            toast.addEventListener('focusout', function () { startTimer(toast); });

            region.appendChild(toast);
            while (region.children.length > VISIBLE_AT_MOST) {
                region.firstElementChild.remove();
            }
            startTimer(toast);

            return toast;
        }

        function clear() {
            Array.prototype.slice.call(region ? region.children : []).forEach(dismiss);
        }

        window.BusinessOsToast = { show: show, clear: clear };

        function legacy(variant) {
            return function (message) {
                return show({ variant: variant, message: message });
            };
        }

        window.toastr = {
            success: legacy('success'),
            info: legacy('info'),
            warning: legacy('warning'),
            error: legacy('error'),
            clear: clear,
            remove: clear,
            options: {}
        };

        var initial = document.querySelector('script[data-role="toast-initial"]');
        var pending = [];
        try {
            pending = JSON.parse(initial ? initial.textContent : '[]') || [];
        } catch (error) {
            pending = [];
        }

        // Added shortly after load, into the region already on the page, so
        // screen readers announce them.
        document.addEventListener('DOMContentLoaded', function () {
            window.setTimeout(function () { pending.forEach(show); }, 150);
        });
    })();
</script>
