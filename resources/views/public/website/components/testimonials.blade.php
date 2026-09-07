{{-- Website Component Library — testimonials (contract §7.2/§7.1). Explicitly authored content only — never sourced from any review platform. Plain escaped text only. --}}
<section class="website-section website-testimonials">
    @if (! empty($data['heading']))
        <h2>{{ $data['heading'] }}</h2>
    @endif
    <div class="website-testimonials-grid">
        @foreach (($data['items'] ?? []) as $item)
            <blockquote class="website-testimonial-card">
                <p>&ldquo;{{ $item['quote'] ?? '' }}&rdquo;</p>
                <footer>
                    <strong>{{ $item['author_name'] ?? '' }}</strong>
                    @if (! empty($item['author_title']))
                        <span>{{ $item['author_title'] }}</span>
                    @endif
                </footer>
            </blockquote>
        @endforeach
    </div>
</section>
