{{--
    Generation runs inside one request (the AI call is bounded at 60s, plus one corrective retry), so the owner
    must be told it is working and must not be able to fire it twice. Any form marked `data-generation-form`
    switches to a calm in-page "building" state on submit: its button is disabled and the MotionGrove loader with
    one plain sentence appears right below it. The server-side generation lease remains the real protection
    against concurrent runs; this is the owner-facing half. Include once per page (the CSS comes from
    partials/section-router/_styles). A confirmed dialog can call window.WebsiteGenerationProgress.start(form).
--}}
<template id="generation-progress-template">
    <div class="text-center my-4" data-generation-progress role="status" aria-live="polite">
        <x-motiongrove />
        <p class="mt-3 mb-0" data-testid="generation-progress-text">Building your website&hellip; this can take a minute or two. Please keep this page open.</p>
    </div>
</template>

@verbatim
<script>
    (function () {
        'use strict';

        if (window.WebsiteGenerationProgress) {
            return;
        }

        var started = false;

        function start(form) {
            var template = document.getElementById('generation-progress-template');

            if (started || !template) {
                return !started;
            }

            started = true;

            Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"], input[type="submit"]'), function (button) {
                button.disabled = true;
                button.setAttribute('aria-disabled', 'true');
            });

            form.setAttribute('aria-busy', 'true');
            form.insertAdjacentElement('afterend', template.content.firstElementChild.cloneNode(true));

            return true;
        }

        window.WebsiteGenerationProgress = { start: start };

        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (form && form.hasAttribute && form.hasAttribute('data-generation-form') && !event.defaultPrevented && !start(form)) {
                // A second submit while the first is running never reaches the server.
                event.preventDefault();
            }
        });

        // Coming back with the Back button must not leave the page stuck in its "building" state.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                started = false;
                Array.prototype.forEach.call(document.querySelectorAll('[data-generation-progress]'), function (node) { node.remove(); });
                Array.prototype.forEach.call(document.querySelectorAll('[data-generation-form]'), function (form) {
                    form.removeAttribute('aria-busy');
                    Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"]'), function (button) {
                        button.disabled = false;
                        button.removeAttribute('aria-disabled');
                    });
                });
            }
        });
    })();
</script>
@endverbatim
