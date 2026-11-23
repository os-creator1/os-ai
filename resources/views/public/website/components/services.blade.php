{{--
    Website Component Library — services (contract §7.2). Plain escaped text
    only. The same section renders the services list AND the packages list
    (package items carry a catalog_item_uid); a template-driven site presents
    each the way the template owns (WebsiteDesign variants `services` and
    `packages`).
--}}
@php
    $wd = $design ?? null;
    $items = $data['items'] ?? [];
    $isPackages = collect($items)->contains(fn ($candidate) => ! empty($candidate['catalog_item_uid']));
    $variant = $wd ? $wd->variant($isPackages ? 'packages' : 'services') : null;
    // The semantic role (events / process / areas ...) is only used by a design that lays roles out (Emerald Gold); every other design ignores it.
    $role = ($wd && $variant === 'photo-cards') ? \App\Library\Website\Design\SectionRole::of(['type' => 'services', 'data' => $data]) : null;
    $featureIndex = null;
    if ($role === 'booths') {
        foreach (array_values($items) as $position => $candidate) {
            if (! empty($candidate['image']) && isset($assetsByUid[$candidate['image']])) { $featureIndex = $position; break; }
        }
    }
@endphp
<section class="website-section website-services @if ($wd) wd-services wd-services-{{ $variant }} @if ($isPackages) wd-packages @endif @if ($role) wd-role wd-role-{{ $role }} @endif @endif" @if ($isPackages) data-testid="packages-section" @endif>
    @if (! empty($data['heading']))
        <h2 class="wd-section-title">{{ $data['heading'] }}</h2>
    @endif
    <div class="website-services-grid @if ($wd) wd-grid @endif">
        @foreach (array_values($items) as $itemIndex => $item)
            @php($detailUrl = ! $isPackages && ! empty($item['service_uid']) ? (($servicePageUrls ?? [])[$item['service_uid']] ?? null) : null)
            {{-- An "Areas we serve" entry links to the page about that place, when the site has one (by place identity). --}}
            @php($detailUrl = $detailUrl ?? (! $isPackages && ! empty($item['place']) ? (($placePageUrls ?? [])[\Illuminate\Support\Str::slug((string) $item['place'])] ?? null) : null))
            @php($itemImage = ! empty($item['image']) && isset($assetsByUid[$item['image']]) ? $assetsByUid[$item['image']] : null)
            <div class="website-service-card @if ($wd) wd-card @endif @if (! empty($item['featured'])) wd-card-featured @endif @if ($detailUrl) wd-card-linked @endif @if ($featureIndex === $itemIndex) wd-card-feature @endif" @if ($isPackages) data-package-uid="{{ $item['catalog_item_uid'] ?? '' }}" @endif>
                @if ($itemImage)
                    {{ \App\Library\Website\Media\ResponsiveImage::tag($itemImage, '(min-width: 960px) 360px, 100vw') }}
                @endif
                @if ($role === 'events' && ! $itemImage)
                    {{-- No photo of this event type: a composed plate with its own number, never a stock picture or an empty box. --}}
                    <span class="wd-card-plate" aria-hidden="true">{{ str_pad((string) ($itemIndex + 1), 2, '0', STR_PAD_LEFT) }}</span>
                @endif
                @if ($role === 'process')
                    <span class="wd-step-num" aria-hidden="true">{{ str_pad((string) ($itemIndex + 1), 2, '0', STR_PAD_LEFT) }}</span>
                @endif
                @if ($wd && ! empty($item['featured']))
                    <span class="wd-badge">Featured</span>
                @endif
                <h3>
                    @if ($detailUrl)
                        {{-- A booth card leads to that booth's own detail page, found by its service id in this site's published pages. --}}
                        <a class="website-service-link" href="{{ $detailUrl }}">{{ $item['name'] ?? '' }}</a>
                    @else
                        {{ $role === 'process' ? preg_replace('/^\s*\d+[\.\)]\s*/', '', (string) ($item['name'] ?? '')) : ($item['name'] ?? '') }}
                    @endif
                </h3>
                @php([$descriptionText, $featureList] = \App\Library\Catalog\CatalogFeatureList::split($item['description'] ?? null))
                @if ($descriptionText !== null)
                    <p>{{ $descriptionText }}</p>
                @endif
                @if ($featureList !== [])
                    <ul class="website-service-features">
                        @foreach ($featureList as $feature)
                            <li>{{ $feature }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (! empty($item['price_label']))
                    <span class="website-service-price">{{ $item['price_label'] }}</span>
                @endif
                @if ($wd && $isPackages && ($siteCta ?? null))
                    <a class="wd-btn wd-btn-primary wd-card-cta" href="{{ $siteCta['url'] }}">{{ $siteCta['label'] }}</a>
                @endif
            </div>
        @endforeach
    </div>
</section>
