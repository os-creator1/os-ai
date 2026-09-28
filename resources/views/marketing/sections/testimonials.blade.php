<div class="marketing-testimonials">
    @foreach ($testimonials as $testimonial)
        <div class="marketing-testimonial-card">
            {{--
                Correction: never render a clickable "watch" play button
                for a testimonial that has no video_url — a disabled-link
                pointing at "#" is still a visible, misleading play button.
                A testimonial with only a poster image renders as a plain
                photo, no play affordance at all.
            --}}
            @if ($testimonial->video_url)
                <a href="{{ $testimonial->video_url }}"
                   class="marketing-testimonial-card__poster"
                   style="background-image:url('{{ asset($testimonial->poster_image_path) }}')"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="{{ __('Watch feedback from :name', ['name' => $testimonial->name]) }}">
                    <span class="marketing-testimonial-card__play"><span aria-hidden="true">&#9654;</span></span>
                </a>
            @else
                <div class="marketing-testimonial-card__poster"
                     style="background-image:url('{{ asset($testimonial->poster_image_path) }}')"
                     role="img"
                     aria-label="{{ __(':name', ['name' => $testimonial->name]) }}">
                </div>
            @endif
            <div class="marketing-testimonial-card__body">
                <p class="marketing-testimonial-card__name">{{ $testimonial->name }}</p>
                <p class="marketing-testimonial-card__context">{{ $testimonial->business_context_label }}</p>
                @if ($testimonial->transcript_text)
                    <p class="marketing-testimonial-card__transcript">{{ \Illuminate\Support\Str::limit($testimonial->transcript_text, 140) }}</p>
                @endif
            </div>
        </div>
    @endforeach
</div>
