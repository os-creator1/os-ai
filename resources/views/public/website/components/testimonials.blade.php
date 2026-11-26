{{-- Website Component Library — testimonials (contract §7.2/§7.1). Explicitly authored content only — never sourced from any review platform. Plain escaped text only. --}}
<section class="website-section website-testimonials">
    @if (! empty($data['heading']))
        <h2 class="wd-section-title">{{ ($design ?? null) && ($design->variants['accent_titles'] ?? false) ? $design->accentHeading((string) $data['heading']) : $data['heading'] }}</h2>
    @endif
    @if (! empty($data['badge']['rating']))
        {{-- Owner-entered rating only (validated 1-5 / count); never fetched from or implied by a review platform. --}}
        <p class="wd-review-badge" data-testid="review-badge"><span class="wd-review-score">{{ $data['badge']['rating'] }}</span> <span class="wd-review-stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span> <span class="wd-review-meta">{{ $data['badge']['count'] }} {{ $data['badge']['source'] ?? 'Google' }} reviews</span></p>
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
