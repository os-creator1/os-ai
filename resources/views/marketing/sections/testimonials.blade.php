<div class="marketing-testimonials">
    @foreach ($testimonials as $testimonial)
        <div class="marketing-testimonial-card">
            <a href="{{ $testimonial->video_url ?? '#' }}"
               class="marketing-testimonial-card__poster"
               style="background-image:url('{{ asset($testimonial->poster_image_path) }}')"
               @unless($testimonial->video_url) aria-disabled="true" onclick="return false;" @endunless
               aria-label="{{ __('Watch feedback from :name', ['name' => $testimonial->name]) }}">
                <span class="marketing-testimonial-card__play"><span aria-hidden="true">&#9654;</span></span>
            </a>
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
