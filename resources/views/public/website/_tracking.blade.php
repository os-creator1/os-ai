{{--
    Owner tracking for a PUBLISHED page (Website → Tracking). $tracking is built by WebsitePageComposer from
    WebsiteTrackingSettings::effective(), which returns null for any preview, so nothing marketing-related is ever emitted in
    the dashboard, the editor or an unpublished preview. This page itself loads NO third-party script: it emits one inert JSON
    data block (hex-escaped, so owner text can never close the element) and MotionGrove's own loader, which waits for consent
    when the setting requires it, then loads each Google tag / Meta Pixel ONCE however many settings named it.
--}}
@if (! empty($tracking))
    <script type="application/json" id="mg-tracking-config">{!! json_encode($tracking, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
    <script src="{{ asset('js/website-tracking.js') }}?v={{ is_file(public_path('js/website-tracking.js')) ? filemtime(public_path('js/website-tracking.js')) : 1 }}" defer></script>
@endif
