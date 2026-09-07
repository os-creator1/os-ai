{{-- Website Component Library — services (contract §7.2). Plain escaped text only. --}}
<section class="website-section website-services">
    @if (! empty($data['heading']))
        <h2>{{ $data['heading'] }}</h2>
    @endif
    <div class="website-services-grid">
        @foreach (($data['items'] ?? []) as $item)
            <div class="website-service-card">
                @if (! empty($item['image']) && isset($assetsByUid[$item['image']]))
                    <img src="{{ $assetsByUid[$item['image']]['url'] }}" alt="{{ $assetsByUid[$item['image']]['alt_text'] ?? '' }}">
                @endif
                <h3>{{ $item['name'] ?? '' }}</h3>
                @if (! empty($item['description']))
                    <p>{{ $item['description'] }}</p>
                @endif
                @if (! empty($item['price_label']))
                    <span class="website-service-price">{{ $item['price_label'] }}</span>
                @endif
            </div>
        @endforeach
    </div>
</section>
