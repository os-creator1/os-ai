<div class="marketing-testimonials">
    @foreach ($testimonials as $testimonial)
        @php
            $youtubeId = $testimonial->youtubeVideoId();
            $isExternalVideo = $testimonial->video_url && $youtubeId === null;
            $posterUrl = $testimonial->poster_image_path
                ? asset($testimonial->poster_image_path)
                : ($youtubeId ? \App\Library\Marketing\YoutubeUrlParser::thumbnailUrl($youtubeId) : null);
        @endphp
        <div class="marketing-testimonial-card">
            @if ($youtubeId)
                {{--
                    Clicking swaps this button for an <iframe> via the script
                    below — no iframe exists in the markup until then, so no
                    request to youtube-nocookie.com happens before a click.
                --}}
                <button type="button"
                        class="marketing-testimonial-card__poster marketing-testimonial-card__poster--playable"
                        style="background-image:url('{{ $posterUrl }}')"
                        data-marketing-youtube-id="{{ $youtubeId }}"
                        aria-label="{{ __('Play feedback from :name', ['name' => $testimonial->name]) }}">
                    <span class="marketing-testimonial-card__play"><span aria-hidden="true">&#9654;</span></span>
                </button>
            @elseif ($isExternalVideo)
                {{--
                    Correction: never render a clickable "watch" play button
                    for a testimonial that has no video_url — a disabled-link
                    pointing at "#" is still a visible, misleading play
                    button. Only a real, external (non-YouTube) link gets
                    this treatment; it opens in a new tab, since it cannot
                    be safely embedded inline the way a YouTube link can.
                --}}
                <a href="{{ $testimonial->video_url }}"
                   class="marketing-testimonial-card__poster"
                   style="background-image:url('{{ $posterUrl }}')"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="{{ __('Watch feedback from :name', ['name' => $testimonial->name]) }}">
                    <span class="marketing-testimonial-card__play"><span aria-hidden="true">&#9654;</span></span>
                </a>
            @else
                <div class="marketing-testimonial-card__poster"
                     style="background-image:url('{{ $posterUrl }}')"
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
<script>
    // Public Marketing Homepage contract — inline YouTube playback. The
    // player is never present in the initial markup; it is created only on
    // click, and only after re-validating the id's shape client-side too
    // (defense in depth — the server already only ever emits a value that
    // matched this exact pattern).
    (function () {
        var idPattern = /^[A-Za-z0-9_-]{11}$/;

        document.querySelectorAll('[data-marketing-youtube-id]').forEach(function (button) {
            button.addEventListener('click', function () {
                var id = button.getAttribute('data-marketing-youtube-id') || '';

                if (! idPattern.test(id)) {
                    return;
                }

                var iframe = document.createElement('iframe');
                iframe.className = 'marketing-testimonial-card__iframe';
                iframe.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
                iframe.title = button.getAttribute('aria-label') || 'YouTube video player';
                iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
                iframe.setAttribute('allowfullscreen', '');
                iframe.setAttribute('frameborder', '0');

                button.replaceWith(iframe);
            });
        });
    })();
</script>
