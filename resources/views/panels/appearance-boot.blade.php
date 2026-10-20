{{--
    Light / Dark / System appearance — resolved BEFORE the first paint.

    Included at the top of panels/styles.blade.php so every authenticated shell
    runs it synchronously in <head>. The saved preference comes from the server
    (users.appearance), never from browser storage, so a new tab, another device
    or a different person signing in on the same browser always gets the right
    theme and nothing is cached across sign-out. "dark" is already on <html> from
    the server; this script only resolves "system" (which only the browser
    knows), keeps it in step with the operating system, and exposes
    window.MGAppearance.set() for the Settings control to apply a change at once.
--}}
@php $appearancePreference = \App\Library\Appearance\Appearance::forUser(Auth::user()); @endphp
<script>
(function () {
    var root = document.documentElement;
    var pref = @json($appearancePreference);
    var query = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var explicit = false;

    function apply() {
        // An unset/light preference leaves the server-rendered shell untouched.
        if (pref === 'light' && !explicit) { return; }
        var dark = pref === 'dark' || (pref === 'system' && !!query && query.matches);
        root.classList.toggle('dark-layout', dark);
        if (dark) { root.setAttribute('data-layout', 'dark-layout'); } else { root.removeAttribute('data-layout'); }
        root.style.colorScheme = dark ? 'dark' : 'light';
        root.setAttribute('data-appearance', pref);
        try { document.dispatchEvent(new CustomEvent('mg:appearance', { detail: { preference: pref, dark: dark } })); } catch (e) {}
    }

    window.MGAppearance = {
        preference: function () { return pref; },
        isDark: function () { return root.classList.contains('dark-layout'); },
        set: function (value) {
            if (value !== 'light' && value !== 'dark' && value !== 'system') { return; }
            pref = value; explicit = true; apply();
        }
    };

    // Charts read their colours from the tokens when they are built, so an
    // already-drawn ApexChart is refreshed in place when the appearance changes.
    document.addEventListener('mg:appearance', function () {
        try {
            var theme = window.PlatformTheme, registry = window.Apex && window.Apex._chartInstances;
            if (!theme || !registry) { return; }
            var axis = theme.chartAxis(), grid = theme.chartGrid();
            registry.forEach(function (entry) {
                var chart = entry && entry.chart, config = chart && chart.w && chart.w.config;
                if (!config) { return; }
                var yaxis = Array.isArray(config.yaxis) ? config.yaxis.map(function () { return { labels: { style: { colors: axis } } }; }) : { labels: { style: { colors: axis } } };
                chart.updateOptions({
                    chart: { foreColor: axis },
                    grid: { borderColor: grid },
                    xaxis: { labels: { style: { colors: axis } } },
                    yaxis: yaxis,
                    legend: { labels: { colors: axis } }
                }, false, false);
            });
        } catch (e) {}
    });

    if (query) {
        var onChange = function () { if (pref === 'system') { apply(); } };
        if (query.addEventListener) { query.addEventListener('change', onChange); } else if (query.addListener) { query.addListener(onChange); }
    }
    apply();
})();
</script>
{{-- Shell logos render a light and a dark <img> (components/branding-logo.blade.php, `adaptive`);
     the same html.dark-layout class that themes the page picks which one shows, so a theme switch is
     instant and needs no request. --}}
<style>.mg-logo-dark{display:none}html.dark-layout .mg-logo-light{display:none}html.dark-layout .mg-logo-dark{display:inline}</style>
