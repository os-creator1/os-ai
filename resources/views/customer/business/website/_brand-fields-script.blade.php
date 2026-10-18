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
            var picker = document.getElementById('look-brand-picker');
            var text = document.getElementById('look-brand-color');

            if (!picker || !text) { return; }

            if (event.target === picker) {
                text.value = picker.value;
            } else if (event.target === text && /^#[0-9a-fA-F]{6}$/.test(text.value)) {
                picker.value = text.value;
            }
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
