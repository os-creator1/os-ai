{{--
    Daily trend chart shared by the Google Ads and Meta Ads overviews. Reads the
    page's own cached series endpoint (data-series-url) and draws, per tab:
    "spend" = spend per day and results per day as two stacked bar charts;
    "cpr" = cost per result per day as a line. Colours and grid come only from
    window.PlatformTheme (the shared token namespace). The server-rendered
    daily table is the fallback when the request or the library fails.

    The element carries data-results-key / data-results-label / data-cpr-key /
    data-cpr-label so each provider keeps its own series and terminology.
--}}
<script>
    (function () {
        var el = document.getElementById('ads-trend-chart');
        if (!el || !window.ApexCharts || !window.PlatformTheme) { return; }

        var theme = window.PlatformTheme;
        var palette = theme.chartPalette();
        var resultsKey = el.getAttribute('data-results-key');
        var resultsLabel = el.getAttribute('data-results-label');
        var cprKey = el.getAttribute('data-cpr-key');
        var cprLabel = el.getAttribute('data-cpr-label');
        var charts = [];
        var payload = null;
        var tab = 'spend';

        function money(value) {
            if (value === null || value === undefined) { return '—'; }
            try {
                return new Intl.NumberFormat(undefined, { style: 'currency', currency: payload.currency, maximumFractionDigits: 2 }).format(value);
            } catch (e) {
                return String(value);
            }
        }

        function base(height, caption) {
            var width = el.clientWidth || 600;
            var fitting = Math.max(2, Math.floor(width / 90));
            return {
                chart: { height: height, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit', parentHeightOffset: 0, animations: { enabled: false } },
                grid: { borderColor: theme.chartGrid(), padding: { left: 4, right: 4 } },
                title: { text: caption, style: { fontSize: '11px', fontWeight: 600, color: theme.chartAxis() } },
                xaxis: {
                    type: 'category', categories: payload.labels,
                    tickAmount: payload.labels.length > fitting ? fitting : undefined,
                    labels: { style: { colors: theme.chartAxis(), fontSize: '10px' }, rotate: 0, rotateAlways: false, hideOverlappingLabels: true, trim: false },
                    axisTicks: { show: false },
                    tooltip: { enabled: false }
                },
                tooltip: { theme: 'dark', x: { formatter: function (v, o) { return payload.tooltips[o.dataPointIndex] || v; } } },
                dataLabels: { enabled: false },
                legend: { show: false },
                plotOptions: { bar: { columnWidth: '70%', borderRadius: 2 } },
                noData: { text: 'No data for this period yet' }
            };
        }

        // Whole-number results: small maxima get one tick per whole result instead of repeated labels.
        function countAxis(values) {
            var top = Math.max.apply(null, (values || []).map(function (v) { return Number(v) || 0; }).concat([0]));
            var axis = { min: 0, forceNiceScale: true, decimalsInFloat: 0, labels: { style: { colors: theme.chartAxis(), fontSize: '10px' } } };
            if (top <= 5) { axis.max = Math.max(1, Math.ceil(top)); axis.tickAmount = axis.max; axis.forceNiceScale = false; }
            return axis;
        }

        function mount(options) {
            var host = document.createElement('div');
            host.className = 'ads-chart';
            el.appendChild(host);
            var chart = new ApexCharts(host, options);
            chart.render();
            charts.push(chart);
        }

        function draw() {
            charts.forEach(function (chart) { chart.destroy(); });
            charts = [];
            el.innerHTML = '';

            if (tab === 'cpr') {
                mount(Object.assign(base(240, cprLabel + ' per day'), {
                    chart: Object.assign({ type: 'line' }, base(240).chart),
                    colors: [palette[2] || palette[0]],
                    series: [{ name: cprLabel, data: payload.series[cprKey] }],
                    stroke: { curve: 'straight', width: 2 },
                    markers: { size: 3 },
                    yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: theme.chartAxis(), fontSize: '10px' }, formatter: money } }
                }));
                return;
            }

            mount(Object.assign(base(180, 'Spend per day'), {
                chart: Object.assign({ type: 'bar' }, base(180).chart),
                colors: [palette[0]],
                series: [{ name: 'Spend', data: payload.series.spend }],
                yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: theme.chartAxis(), fontSize: '10px' }, formatter: money } }
            }));
            mount(Object.assign(base(130, resultsLabel + ' per day'), {
                chart: Object.assign({ type: 'bar' }, base(130).chart),
                colors: [palette[1] || palette[0]],
                series: [{ name: resultsLabel, data: payload.series[resultsKey] }],
                yaxis: countAxis(payload.series[resultsKey])
            }));
        }

        document.querySelectorAll('[data-chart-tab]').forEach(function (button) {
            button.addEventListener('click', function () {
                tab = button.getAttribute('data-chart-tab');
                document.querySelectorAll('[data-chart-tab]').forEach(function (other) {
                    var on = other === button;
                    other.classList.toggle('is-active', on);
                    other.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                if (payload) { draw(); }
            });
        });

        var resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { if (payload) { draw(); } }, 200);
        });

        fetch(el.getAttribute('data-series-url'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
            .then(function (body) {
                if (!body || !body.series || !body.labels) { return Promise.reject('shape'); }
                payload = body;
                draw();
            })
            .catch(function () {
                el.innerHTML = '<p class="text-caption mb-0">The chart is unavailable right now. The daily figures below show the same data.</p>';
                var details = document.querySelector('[data-role="trend-table"]');
                if (details) { details.open = true; }
            });
    })();
</script>
