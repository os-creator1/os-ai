{{--
    Copy-link buttons: any element with data-copy-link="URL" copies that URL
    and shows a short "Copied" next to it. No page refresh.
--}}
<script>
(function () {
    'use strict';

    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(area);
        return ok;
    }

    function done(button, ok) {
        var note = button.parentNode.querySelector('[data-copy-feedback]');
        if (!note) { return; }
        note.textContent = ok ? 'Copied' : 'Press Ctrl+C to copy';
        note.hidden = false;
        clearTimeout(note._t);
        note._t = setTimeout(function () { note.hidden = true; }, 2000);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-copy-link]') : null;
        if (!button) { return; }
        var url = button.getAttribute('data-copy-link');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () { done(button, true); }, function () { done(button, fallbackCopy(url)); });
        } else {
            done(button, fallbackCopy(url));
        }
    });
})();
</script>
