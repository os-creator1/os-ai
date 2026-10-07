{{--
    Website Component Library — contact_details (contract §7.2/§7.3).
    The one deliberate live-read exception: the published snapshot
    carries pre-resolved values under $data['resolved'] (built at
    publish time by WebsiteSnapshotBuilder); preview (which has no
    snapshot) falls back to resolving the Website's Business live here.
    Phone/email render as tel:/mailto: links only (contract §25) — no
    map embed/iframe (contract §10 raw-content boundary), address is
    plain escaped text only.
--}}
@php
    // Preview shows live Business/Location state exactly as it will be
    // resolved at the NEXT publish (§7.3) — including this SAME address
    // privacy gate WebsiteSnapshotBuilder applies at publish time, so a
    // location the predicate would withhold never appears in preview
    // either, even with Show address checked.
    $previewLocation = $website->business?->primaryLocation;
    $addressPermitted = $previewLocation !== null
        && $previewLocation->isActive()
        && app(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileReadMask::class)->addressPermittedForLocation($previewLocation)
        // Same rule the published page applies: a "Serving <place>" page that is not the primary Location's own never shows the primary address.
        && \App\Library\Website\Seo\WebsiteLocationPageAddress::pageMayShowAddress($page->slug ?? null, $website->business);

    $resolved = $data['resolved'] ?? [
        'phone' => ($data['show_phone'] ?? false) ? $website->business?->phone : null,
        'email' => ($data['show_email'] ?? false) ? $website->business?->email : null,
        'address' => ($data['show_address'] ?? false) && $addressPermitted
            ? collect([
                $previewLocation?->address_line_1,
                $previewLocation?->address_line_2,
                $previewLocation?->city,
                $previewLocation?->region,
                $previewLocation?->postal_code,
            ])->filter()->implode(', ')
            : null,
    ];
@endphp
<section class="website-section website-contact-details @if ($design ?? null) wd-contact @endif">
    <h2 class="wd-section-title">Contact Us</h2>
    <ul class="website-contact-list">
        @if (! empty($resolved['phone']))
            <li><a href="tel:{{ \App\Library\Website\Design\PhoneDisplay::dial($resolved['phone']) }}">{{ \App\Library\Website\Design\PhoneDisplay::format($resolved['phone']) }}</a></li>
        @endif
        @if (! empty($resolved['email']))
            <li><a href="mailto:{{ $resolved['email'] }}">{{ $resolved['email'] }}</a></li>
        @endif
        @if (! empty($resolved['address']))
            <li>{{ $resolved['address'] }}</li>
        @endif
    </ul>
    @if (($design ?? null) && ($siteCta ?? null) && ! empty($siteCta['url']))
        <a class="wd-btn wd-btn-primary wd-btn-lg" href="{{ $siteCta['url'] }}">{{ $siteCta['label'] }}</a>
    @endif
</section>
