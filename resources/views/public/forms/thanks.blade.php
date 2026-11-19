<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $context->form->name }}</title>
    {{-- The style of the version the visitor completed, like their thank-you message. --}}
    @include('public.forms._style', ['design' => $version->style()])
    <style>body{margin:0;background:var(--pf-bg)}</style>
</head>
<body class="pf-scope">
<main class="pf-shell" data-role="public-form-thanks">
    <div class="pf-card">
        <h1 class="pf-title">{{ $context->form->name }}</h1>
        {{-- The thank-you of the version the visitor completed (see PublicFormController::thanks). --}}
        <p>{{ $version->success_message }}</p>
    </div>
</main>
@if (! empty($announce))
    {{-- Where it may be announced: this app's own origin plus the exact origins allowed to frame the form (never a wildcard). --}}
    @php($announceOrigins = array_values(array_unique(array_merge([request()->getSchemeAndHttpHost()], (array) request()->attributes->get('form_frame_origins', [])))))
    {{-- A stored submission, announced once to each allowed embedding origin (a message to any other origin is dropped by the
         browser). The payload is two random ids: no contact details, answers or consent. The parent decides what to do. --}}
    <script>
        (function () {
            if (window.parent === window) { return; }
            var message = {type: 'motiongrove:lead_submitted', version: 1, deployment_uid: @json($announce['deployment_uid']), submission_uid: @json($announce['submission_uid'])};
            @json($announceOrigins).forEach(function (origin) { window.parent.postMessage(message, origin); });
        }());
    </script>
@endif
</body>
</html>
