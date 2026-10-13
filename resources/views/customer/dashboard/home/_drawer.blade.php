{{--
    Home V2 §3 — the reusable in-Home action drawer and Ask MotionGrove client.

    PROGRESSIVE ENHANCEMENT. Every trigger is a real link to the module page
    (`href`), carrying `data-home-drawer` (the read-only fragment URL). With no
    JavaScript, or no Bootstrap offcanvas, the customer simply lands on the
    module — nothing is lost, and nothing is faked.

    WRITES. A drawer form posts to the MODULE's own endpoint (the form's own
    action) with the page's CSRF token and `Accept: application/json`. Success
    is announced only from that endpoint's own answer (`status: "success"`);
    a refusal or a validation error is shown as it was given, and the drawer
    stays open so nothing typed is lost. Closing a drawer after a confirmed
    write reloads Home, so every figure on it is current.

    Everything inserted from a fragment is server-rendered, escaped markup from
    this application; results of Ask are built with textContent.
--}}
<script src="{{ asset('js/home/command-center.js') }}?v={{ @filemtime(public_path('js/home/command-center.js')) }}" defer></script>
<script src="{{ asset('js/home/ask-assistant.js') }}?v={{ @filemtime(public_path('js/home/ask-assistant.js')) }}" defer></script>
