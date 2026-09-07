{{-- Website Component Library — faq (contract §7.2/§7.1). Explicitly authored content only — no FAQ model exists to source from. Plain escaped text only. --}}
<section class="website-section website-faq">
    @if (! empty($data['heading']))
        <h2>{{ $data['heading'] }}</h2>
    @endif
    <div class="website-faq-list">
        @foreach (($data['items'] ?? []) as $item)
            <details class="website-faq-item">
                <summary>{{ $item['question'] ?? '' }}</summary>
                <p>{{ $item['answer'] ?? '' }}</p>
            </details>
        @endforeach
    </div>
</section>
