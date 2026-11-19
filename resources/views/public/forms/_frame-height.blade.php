{{-- When this form is framed by a Website page, tell that page how tall the form is, so it can size its frame to fit and the submit
     button is never hidden under a nested scrollbar. Sent only to the origins allowed to frame this form (the forms.frame
     middleware's list) — never a wildcard — and carries only the form's own id and a number. --}}
@php($frameOrigins = array_values(array_unique(array_merge([request()->getSchemeAndHttpHost()], (array) request()->attributes->get('form_frame_origins', [])))))
<script>
    (function () {
        if (window.parent === window) { return; }
        var origins = @json($frameOrigins);
        var deployment = @json((string) $context->deployment->uid);
        function report() {
            var height = Math.ceil(Math.max(document.documentElement.scrollHeight, document.body.scrollHeight));
            origins.forEach(function (origin) {
                window.parent.postMessage({type: 'motiongrove:form_height', version: 1, deployment_uid: deployment, height: height}, origin);
            });
        }
        window.addEventListener('load', report);
        if (window.ResizeObserver) { new ResizeObserver(report).observe(document.body); }
    }());
</script>
