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
    })();
</script>
@endverbatim
