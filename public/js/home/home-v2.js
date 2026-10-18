/*
 * Home V2 (final) — the one small client behaviour the page needs: the short "New contacts per day" chart.
 *
 * It draws ONLY a genuine payload from the existing series endpoint, for the same range the cards show. Fewer than a week of
 * observations is decided on the server (no chart is requested at all). Here: every day zero means no chart, just a line saying so,
 * and a failed read says it is unavailable — it is never drawn as zeros. Built with DOM nodes and textContent only.
 * The period switch is the shared async-region form: after it swaps the section, the chart is mounted again.
 */
(function () {
    'use strict';

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined) { n.textContent = text; }
        return n;
    }

    function say(plot, text, cls) {
        plot.textContent = '';
        plot.className = 'hv2-chart__empty';
        plot.appendChild(el('p', cls || 'mb-0', text));
    }

    function mount(box) {
        var plot = box.querySelector('[data-role="chart-plot"]');
        var url = box.getAttribute('data-series-url');
        if (!plot || !url || plot.getAttribute('data-mounted') === '1') { return; }
        plot.setAttribute('data-mounted', '1');

        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (payload) {
                var chart = payload && payload.charts ? payload.charts.new_contacts : null;
                var values = chart && chart.series ? chart.series.new_contacts : null;
                if (!chart || !Array.isArray(values) || !Array.isArray(chart.labels)) { return Promise.reject('shape'); }
                if (!document.body.contains(box)) { return; }

                var total = values.reduce(function (sum, n) { return sum + (Number(n) || 0); }, 0);
                if (total === 0) {
                    // An empty chart adds nothing the cards do not already say; a business under a week old is told when a trend will appear.
                    say(plot, box.getAttribute('data-too-early') === '1' ? 'Your trend will appear after your first week.' : 'No new contacts in this period yet.');
                    return;
                }

                var max = Math.max.apply(null, values.map(Number));
                plot.textContent = '';
                plot.className = 'hv2-chart__plot';

                values.forEach(function (n, i) {
                    var bar = el('span', 'hv2-chart__bar' + (Number(n) === 0 ? ' is-zero' : ''));
                    bar.style.height = Math.max(6, Math.round(Number(n) / max * 100)) + '%';
                    bar.title = (chart.tooltips && chart.tooltips[i] ? chart.tooltips[i] : chart.labels[i]) + ': ' + Number(n);
                    plot.appendChild(bar);
                });

                var axis = box.querySelector('[data-role="chart-axis"]');
                if (!axis) {
                    axis = el('div', 'hv2-chart__axis');
                    axis.setAttribute('data-role', 'chart-axis');
                    axis.setAttribute('aria-hidden', 'true');
                    plot.parentNode.insertBefore(axis, plot.nextSibling);
                }
                axis.textContent = '';
                // Up to ~9 evenly spaced date labels, the first and last always included.
                var count = chart.labels.length;
                var step = Math.max(1, Math.ceil(count / 9));
                var picks = [];
                for (var p = 0; p < count; p += step) { picks.push(p); }
                if (picks[picks.length - 1] !== count - 1 && count - 1 - picks[picks.length - 1] >= Math.ceil(step / 2)) { picks.push(count - 1); }
                // One slot per day, so each label sits under its own bar; only the picked days carry text.
                for (var d = 0; d < count; d++) { axis.appendChild(el('span', null, picks.indexOf(d) !== -1 ? chart.labels[d] : '')); }

                var label = box.querySelector('[data-role="chart-total"]');
                if (label) { label.textContent = '· ' + total + ' in ' + box.getAttribute('data-days') + ' days'; }
                plot.setAttribute('aria-label', 'New contacts per day: ' + total + ' in ' + box.getAttribute('data-days') + ' days');
            })
            .catch(function () {
                if (document.body.contains(box)) { say(plot, 'Chart data is unavailable right now.'); }
            });
    }

    function mountAll(root) {
        (root || document).querySelectorAll('[data-role="home-chart"]').forEach(mount);
    }

    mountAll(document);
    document.addEventListener('async-region:updated', function (event) {
        if (event.detail && event.detail.name === 'business-performance') { mountAll(event.target); }
    });
})();
