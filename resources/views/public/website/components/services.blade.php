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
@endphp
<section class="website-section website-services @if ($wd) wd-services wd-services-{{ $variant }} @if ($isPackages) wd-packages @endif @endif" @if ($isPackages) data-testid="packages-section" @endif>
    @if (! empty($data['heading']))
        <h2 class="wd-section-title">{{ $data['heading'] }}</h2>
    @endif
    <div class="website-services-grid @if ($wd) wd-grid @endif">
        @foreach ($items as $item)
            @php($itemImage = ! empty($item['image']) && isset($assetsByUid[$item['image']]) ? $assetsByUid[$item['image']] : null)
            <div class="website-service-card @if ($wd) wd-card @endif @if (! empty($item['featured'])) wd-card-featured @endif" @if ($isPackages) data-package-uid="{{ $item['catalog_item_uid'] ?? '' }}" @endif>
                @if ($itemImage)
                    <img src="{{ $itemImage['url'] }}" alt="{{ $itemImage['alt_text'] ?? '' }}" loading="lazy">
                @endif
                @if ($wd && ! empty($item['featured']))
                    <span class="wd-badge">Featured</span>
                @endif
                <h3>{{ $item['name'] ?? '' }}</h3>
                @if (! empty($item['description']))
                    <p>{{ $item['description'] }}</p>
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
