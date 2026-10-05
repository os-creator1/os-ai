{{-- Forms module form placed on a Website page: the renderer contract.
     $embed is the array FormWebsiteEmbed::resolve() returns (url, title, height).
     The form itself is the real public form in an iframe — one implementation,
     whoever hosts it. Escaped; no script. --}}
@if (! empty($embed))
    <section class="website-section website-forms-module-form" data-form-uid="{{ $embed['form_uid'] }}">
        <iframe src="{{ $embed['url'] }}" title="{{ $embed['title'] }}" loading="lazy" style="width:100%;height:{{ (int) $embed['height'] }}px;border:0"></iframe>
    </section>
@endif
