{{--
    Native website analytics for a PUBLISHED page. $analytics is built server-side by AnalyticsClientConfig, which returns null
    for any preview, draft, editor or internal render — then nothing is emitted. One inert JSON block (hex-escaped) plus
    MotionGrove's own first-party script; no third-party code, no Google Analytics account.
--}}
@if (! empty($analytics))
    <script type="application/json" id="mg-analytics-config">{!! json_encode($analytics, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
    <script src="{{ asset('js/website-analytics.js') }}?v={{ is_file(public_path('js/website-analytics.js')) ? filemtime(public_path('js/website-analytics.js')) : 1 }}" defer></script>
@endif
