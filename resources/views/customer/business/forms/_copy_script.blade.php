{{-- Tiny copy-to-clipboard for [data-copy] buttons outside the builder page. --}}
<script>
(function () {
    [].forEach.call(document.querySelectorAll('[data-copy]'), function (b) {
        b.addEventListener('click', function () {
            var text = b.getAttribute('data-copy');
            var done = function () { var t = b.innerHTML; b.textContent = 'Copied'; setTimeout(function () { b.innerHTML = t; }, 1400); };
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, done); return; }
            var ta = document.createElement('textarea'); ta.style.cssText = 'position:fixed;opacity:0'; ta.value = text; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) { /* ignore */ } document.body.removeChild(ta); done();
        });
    });
})();
</script>
