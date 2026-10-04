{{--
    Copy-link helper for SEO > Reviews. Copies the value already printed in
    the page (data-copy-link) to the clipboard. No request is made and
    nothing is recorded.
--}}
<script>
    (function () {
        var buttons = document.querySelectorAll('[data-copy-link]');
        if (!buttons.length) { return; }

        function fallbackCopy(text) {
            var field = document.createElement('textarea');
            field.value = text;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(field);
            return ok;
        }

        function flash(button, message) {
            var label = button.querySelector('[data-copy-label]');
            if (!label) { return; }
            var original = label.getAttribute('data-original') || label.textContent;
            label.setAttribute('data-original', original);
            label.textContent = message;
            window.clearTimeout(button._copyTimer);
            button._copyTimer = window.setTimeout(function () { label.textContent = original; }, 1800);
        }

        function failed(button) {
            var field = button.closest('.rv-link-row').querySelector('input');
            if (field) { field.focus(); field.select(); }
            flash(button, 'Press Ctrl+C');
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var text = button.getAttribute('data-copy-link');
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(
                        function () { flash(button, 'Copied'); },
                        function () { (fallbackCopy(text) ? flash(button, 'Copied') : failed(button)); }
                    );
                } else {
                    (fallbackCopy(text) ? flash(button, 'Copied') : failed(button));
                }
            });
        });
    })();
</script>
