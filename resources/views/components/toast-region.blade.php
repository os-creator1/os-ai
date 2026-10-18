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
<x-notice-styles />
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
        pointer-events: auto;
        margin: 0;
        box-shadow: var(--shadow-lg, 0 8px 24px rgba(0, 0, 0, 0.08));
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
    <span data-variant="success"><x-notice-glyph variant="success" /></span>
    <span data-variant="info"><x-notice-glyph variant="info" /></span>
    <span data-variant="warning"><x-notice-glyph variant="warning" /></span>
    <span data-variant="error"><x-notice-glyph variant="danger" /></span>
    <span data-variant="close"><x-notice-glyph variant="close" /></span>
</template>

<script type="application/json" data-role="toast-initial">@json(array_values($toasts))</script>

<script>
    (function () {
        'use strict';

        var region = document.querySelector('[data-role="toast-region"]');
        var icons = document.querySelector('template[data-role="toast-icons"]');
        var VISIBLE_AT_MOST = 3;
        var DURATION = { success: 5000, info: 6000, warning: 10000, error: 10000 };
        var NOTICE_STATUS = { success: 'success', info: 'info', warning: 'warning', error: 'danger' };
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
            toast.className = 'ds-notice ds-toast ds-notice--' + NOTICE_STATUS[variant];
            toast.dataset.variant = variant;
            toast.dataset.key = key;
            toast.setAttribute('role', SPOKEN_PREFIX[variant] ? 'alert' : 'status');

            var iconHolder = document.createElement('span');
            iconHolder.className = 'ds-notice__icon';
            iconHolder.appendChild(icon(variant));

            var body = document.createElement('div');
            body.className = 'ds-notice__body';
            if (SPOKEN_PREFIX[variant]) {
                var prefix = document.createElement('span');
                prefix.className = 'visually-hidden';
                prefix.textContent = SPOKEN_PREFIX[variant];
                body.appendChild(prefix);
            }
            // A message on its own is the title; with a title it is the muted description.
            if (title === '') {
                title = message;
                message = '';
            }
            var heading = document.createElement('strong');
            heading.className = 'ds-notice__title';
            heading.textContent = title;
            body.appendChild(heading);
            if (message !== '') {
                var text = document.createElement('div');
                text.className = 'ds-notice__text';
                text.textContent = message;
                body.appendChild(text);
            }

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'ds-notice__close';
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
