{{--
    Slice 6A — the inquiry panel of a service/event page: ONE service-specific call to action to the contact page (which carries the
    Forms-module lead form), the business's verified phone, and a few CONFIRMED owner facts. Plain escaped text; the call to action
    resolves exactly like a `cta` button (same-site path -> this surface's real page address), so it never renders a dead link. The
    phone is the publish-time frozen value ($data['resolved']); Preview falls back to the live business, like `contact_details`.
    Renders nothing when it has no destination, phone or fact to offer.
--}}
@php
    $panelCta = app(\App\Library\Website\Design\WebsiteCtaResolver::class)->sectionCta($data['cta'] ?? null, $siteCta ?? null, $pageUrls ?? []);
    $panelPhone = $data['resolved']['phone'] ?? (($data['show_phone'] ?? false) ? $website->business?->phone : null);
    $panelFacts = array_values(array_filter((array) ($data['facts'] ?? []), fn ($fact) => is_string($fact) && trim($fact) !== ''));
@endphp
@if ($panelCta !== null || ! empty($panelPhone) || $panelFacts !== [])
    <aside class="website-section website-inquiry-panel @if ($design ?? null) wd-inquiry-panel @endif" aria-labelledby="inquiry-panel-title" data-testid="inquiry-panel">
        <h2 id="inquiry-panel-title" class="wd-inquiry-title">{{ $data['heading'] ?? '' }}</h2>
        @if (! empty($data['body']))
            <p class="wd-inquiry-body">{{ $data['body'] }}</p>
        @endif
        @if ($panelCta !== null)
            <a class="website-btn website-btn-primary @if ($design ?? null) wd-btn wd-btn-primary wd-btn-lg @endif" href="{{ $panelCta['url'] }}">{{ $panelCta['label'] }}</a>
        @endif
        @if (! empty($panelPhone))
            <p class="wd-inquiry-phone">Prefer to talk? <a href="tel:{{ \App\Library\Website\Design\PhoneDisplay::dial($panelPhone) }}">{{ \App\Library\Website\Design\PhoneDisplay::format($panelPhone) }}</a></p>
        @endif
        @if ($panelFacts !== [])
            <ul class="wd-inquiry-facts">
                @foreach ($panelFacts as $fact)
                    <li>{{ $fact }}</li>
                @endforeach
            </ul>
        @endif
    </aside>
@endif
