<!doctype html>
{{--
    The calling shell (see App\Http\Middleware\SoftphoneFrame): this document owns the softphone and the call and never
    navigates; the application runs in the frame below. Inner pages report their address so the browser's address
    bar and tab title stay truthful, and the browser's own Back/Forward keep working through the frame's history.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.title') }}</title>
    <x-branding-favicon />
    <style>
        html, body { margin: 0; height: 100%; background: #f6f4f1; }
        #mg-app-frame { position: fixed; inset: 0; width: 100%; height: 100%; border: 0; background: #f6f4f1; }
    </style>
</head>
<body>
<iframe id="mg-app-frame" name="mg-app-frame" title="{{ config('app.title') }}" src="{{ $src }}" allow="clipboard-write"></iframe>
@include('customer.people._softphone', ['browserCalling' => $browserCalling])
<script>
    (function () {
        var frame = document.getElementById('mg-app-frame');
        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin || event.source !== frame.contentWindow) { return; }
            var data = event.data;
            if (!data || data.type !== 'mg:nav' || typeof data.href !== 'string') { return; }
            try {
                var url = new URL(data.href, window.location.href);
                if (url.origin === window.location.origin) { window.history.replaceState(null, '', url.pathname + url.search + url.hash); }
            } catch (e) { /* an unreadable address leaves the bar as it was */ }
            if (typeof data.title === 'string' && data.title !== '') { document.title = data.title; }
        });
    })();
</script>
</body>
</html>
