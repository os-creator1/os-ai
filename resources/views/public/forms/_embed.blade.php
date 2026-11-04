{{-- Forms module form placed on a Website page: the renderer contract.
     $embed is the array FormWebsiteEmbed::resolve() returns (url, title, height).
     The form itself is the real public form in an iframe — one implementation,
     whoever hosts it. Escaped; no script. The fallback link below it is the same saved deployment URL ($embed['url']),
     so a browser or network that will not frame the form still reaches it in a new tab. --}}
@if (! empty($embed))
    <section class="website-section website-forms-module-form" data-form-uid="{{ $embed['form_uid'] }}">
        <iframe src="{{ $embed['url'] }}" title="{{ $embed['title'] }}" loading="lazy" style="width:100%;height:{{ (int) $embed['height'] }}px;border:0"></iframe>
        <p class="website-forms-module-fallback" data-role="form-fallback">Having trouble viewing the form? <a href="{{ $embed['url'] }}" target="_blank" rel="noopener">Open it here.</a></p>
    </section>
@endif
