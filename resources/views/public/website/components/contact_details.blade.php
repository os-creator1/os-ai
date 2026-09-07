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
    $resolved = $data['resolved'] ?? [
        'phone' => ($data['show_phone'] ?? false) ? $website->business?->phone : null,
        'email' => ($data['show_email'] ?? false) ? $website->business?->email : null,
        'address' => ($data['show_address'] ?? false)
            ? collect([
                $website->business?->primaryLocation?->address_line_1,
                $website->business?->primaryLocation?->address_line_2,
                $website->business?->primaryLocation?->city,
                $website->business?->primaryLocation?->region,
                $website->business?->primaryLocation?->postal_code,
            ])->filter()->implode(', ')
            : null,
    ];
@endphp
<section class="website-section website-contact-details">
    <h2>Contact Us</h2>
    <ul class="website-contact-list">
        @if (! empty($resolved['phone']))
            <li><a href="tel:{{ $resolved['phone'] }}">{{ $resolved['phone'] }}</a></li>
        @endif
        @if (! empty($resolved['email']))
            <li><a href="mailto:{{ $resolved['email'] }}">{{ $resolved['email'] }}</a></li>
        @endif
        @if (! empty($resolved['address']))
            <li>{{ $resolved['address'] }}</li>
        @endif
    </ul>
</section>
