{{-- Website Component Library — cta (contract §7.2). Plain escaped text only. --}}
<section class="website-section website-cta">
    <h2>{{ $data['heading'] ?? '' }}</h2>
    @if (! empty($data['body']))
        <p>{{ $data['body'] }}</p>
    @endif
    <div class="website-cta-buttons">
        @foreach (($data['buttons'] ?? []) as $button)
            @if (! empty($button['url']))
                <a class="website-btn website-btn-primary" href="{{ $button['url'] }}">{{ $button['label'] ?? '' }}</a>
            @endif
        @endforeach
    </div>
</section>
