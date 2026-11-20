@once
<script>
(function () {
    // Filter selects apply as soon as they change. The (visually hidden) Apply button still works for keyboard users.
    document.querySelectorAll('form[data-autosubmit]').forEach(function (f) {
        f.querySelectorAll('select').forEach(function (el) { el.addEventListener('change', function () { f.submit(); }); });
    });
})();
</script>
@endonce
