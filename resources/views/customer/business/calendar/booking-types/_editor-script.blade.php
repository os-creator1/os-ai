{{--
    Booking type editor behaviour: colour swatches and the live "What
    customers will get" summary. Both only read and write the form's own
    inputs; nothing here stores or submits a value of its own.
--}}
<script>
(function () {
    'use strict';

    var colorInput = document.querySelector('[data-bt-field="color"]');
    var preview = document.querySelector('[data-bt-color-preview]');
    var swatches = document.querySelectorAll('[data-bt-swatch]');
    var safeColor = /^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,20})$/;

    function syncColor() {
        if (!colorInput) { return; }
        var value = colorInput.value.trim();
        if (preview) { preview.style.setProperty('--bt-preview', safeColor.test(value) ? value : 'transparent'); }
        for (var i = 0; i < swatches.length; i++) {
            var on = value.toLowerCase() === swatches[i].getAttribute('data-bt-swatch').toLowerCase();
            swatches[i].setAttribute('aria-pressed', on ? 'true' : 'false');
        }
    }

    for (var s = 0; s < swatches.length; s++) {
        swatches[s].addEventListener('click', function () {
            if (!colorInput) { return; }
            colorInput.value = this.getAttribute('data-bt-swatch');
            colorInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }
    if (colorInput) { colorInput.addEventListener('input', syncColor); }
    syncColor();

    var summary = document.querySelector('[data-bt-summary]');
    if (!summary) { return; }

    function field(name) { return document.querySelector('[data-bt-field="' + name + '"]'); }
    function selectedText(name) {
        var el = field(name);
        return el && el.selectedIndex >= 0 ? el.options[el.selectedIndex].text : '';
    }
    function setText(key, text) {
        var el = summary.querySelector('[data-bt-summary-item="' + key + '"]');
        if (el) { el.textContent = text; }
    }

    function render() {
        var duration = parseInt((field('duration') || {}).value, 10);
        setText('duration', duration > 0 ? duration + ' minute appointment' : 'Enter a duration');

        var interval = selectedText('interval');
        setText('interval', interval ? 'Start times ' + interval.charAt(0).toLowerCase() + interval.slice(1) : '');

        var windowText = selectedText('window').replace(/ ahead$/, '');
        var noticeEl = field('notice');
        var notice = noticeEl && parseInt(noticeEl.value, 10) > 0 ? 'with at least ' + selectedText('notice') + ' notice' : 'with no minimum notice';
        setText('window', 'Bookable up to ' + windowText + ' ahead, ' + notice);
    }

    ['duration', 'interval', 'window', 'notice'].forEach(function (name) {
        var el = field(name);
        if (el) {
            el.addEventListener('input', render);
            el.addEventListener('change', render);
        }
    });
    render();
})();
</script>
