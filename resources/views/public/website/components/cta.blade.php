{{--
    Website Component Library — cta (contract §7.2). Plain escaped text only.
    A button written at generation time is kept only when it truly points
    somewhere; otherwise the site's resolved CTA is used, and with neither,
    no button is rendered (never a dead link).
--}}
@php
    $resolver = app(\App\Library\Website\Design\WebsiteCtaResolver::class);
    $buttons = collect($data['buttons'] ?? [])
        ->map(fn ($button) => $resolver->sectionCta($button, $siteCta ?? null, $pageUrls ?? []))
        ->filter()
        ->unique('url')
        ->values();
@endphp
<section class="website-section website-cta @if ($design ?? null) wd-cta @endif">
    <h2>{{ $data['heading'] ?? '' }}</h2>
    @if (! empty($data['body']))
        <p>{{ $data['body'] }}</p>
    @endif
    <div class="website-cta-buttons">
        @foreach ($buttons as $button)
            <a class="website-btn website-btn-primary @if ($design ?? null) wd-btn wd-btn-primary wd-btn-lg @endif" href="{{ $button['url'] }}">{{ $button['label'] }}</a>
        @endforeach
    </div>
</section>
