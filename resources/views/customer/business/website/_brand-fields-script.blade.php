{{--
    Keeps the brand-colour picker and its text box in step. Delegated on the document and guarded, so it
    works once per page whether the fields were part of the page or arrived in a swapped section.
--}}
@verbatim
<script>
    (function () {
        if (window.__websiteBrandColorSync) { return; }
        window.__websiteBrandColorSync = true;

        document.addEventListener('input', function (event) {
            var target = event.target;

            if (!target || !target.getAttribute) { return; }

            var textId = target.getAttribute('data-color-picker');
            var picker = textId ? target : null;
            var text = textId ? document.getElementById(textId) : null;

            if (picker && text) {
                text.value = picker.value;
                return;
            }

            if (target.name && /^[a-z_]*color$/.test(target.name)) {
                var swatch = document.querySelector('[data-color-picker="' + target.id + '"]');

                if (swatch && /^#[0-9a-fA-F]{6}$/.test(target.value)) { swatch.value = target.value; }
            }
        });

        // "Reset" blanks the HEX box; saving a blank colour returns that colour to the template's own.
        document.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('[data-color-reset]') : null;

            if (!button) { return; }

            var text = document.getElementById(button.getAttribute('data-color-reset'));

            if (text) { text.value = ''; }
        });

        // Shows the chosen file's name beside a styled "Choose file" button (Website Settings look card).
        document.addEventListener('change', function (event) {
            var input = event.target;

            if (!input || !input.hasAttribute || !input.hasAttribute('data-look-file')) { return; }

            var row = input.closest('.website-look-upload');
            var label = row ? row.querySelector('[data-look-filename]') : null;

            if (label && input.files && input.files[0]) { label.textContent = input.files[0].name; }
        });
    })();
</script>
@endverbatim
