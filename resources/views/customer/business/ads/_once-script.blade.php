{{--
    Google Ads Module V1 — double-submit guard for the mutation forms: the
    first submit disables the form's buttons and a second submit is ignored.
    This is only a convenience; GoogleAdsMutationService dedupes on its own
    (a repeat is answered from the ledger and never sent to Google twice).
--}}
<script>
    (function () {
        // A page restored from the back/forward cache must not stay locked.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) { return; }
            document.querySelectorAll('form[data-ads-once]').forEach(function (form) {
                form.removeAttribute('data-submitted');
                form.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = false; });
            });
        });

        document.querySelectorAll('form[data-ads-once]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.getAttribute('data-submitted') === '1') {
                    event.preventDefault();
                    return;
                }
                form.setAttribute('data-submitted', '1');
                // Disable after the browser has captured the submit, so the request still goes out.
                window.setTimeout(function () {
                    form.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = true; });
                }, 0);
            });
        });
    })();
</script>
