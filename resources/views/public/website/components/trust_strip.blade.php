{{--
    Slice 6B — the homepage trust strip: two to four short, OWNER-CONFIRMED business advantages, verbatim and escaped, in a slim band under the
    hero. The facts are stored in the page itself (so a published page is frozen by its revision like any section). Fewer than two non-empty
    facts renders nothing: one claim on its own is not a strip.
--}}
@php
    $isMarquee = ($data['ref'] ?? null) === 'marquee';
    $trustFacts = array_slice(array_values(array_filter((array) ($data['facts'] ?? []), fn ($fact) => is_string($fact) && trim($fact) !== '')), 0, $isMarquee ? 12 : 4);
@endphp
@if ($isMarquee && count($trustFacts) >= 2)
    {{-- Event-category ticker: the owner's real event types, drifting slowly. The track is doubled for a seamless loop (the copy is hidden from
         assistive technology); with prefers-reduced-motion it stops and wraps as a static, centred list. --}}
    <section class="website-section website-trust-strip wd-marquee" aria-label="Events we serve" data-testid="event-marquee">
        @php($reps = max(1, (int) ceil(10 / count($trustFacts))))
        <div class="wd-marquee-track">
            {{-- Each list repeats the categories until it is wider than any screen (a short list would leave a gap in the loop); only the
                 first occurrence is exposed to assistive technology. --}}
            <ul class="wd-marquee-list">
                @foreach (range(1, $reps) as $repeat)
                    @foreach ($trustFacts as $fact)
                        <li @if ($repeat > 1) aria-hidden="true" @endif>{{ $fact }}</li>
                    @endforeach
                @endforeach
            </ul>
            <ul class="wd-marquee-list wd-marquee-copy" aria-hidden="true">
                @foreach (range(1, $reps) as $repeat)
                    @foreach ($trustFacts as $fact)
                        <li>{{ $fact }}</li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    </section>
@elseif (count($trustFacts) >= 2)
    <section class="website-section website-trust-strip @if ($design ?? null) wd-trust-strip @endif" aria-label="Why customers choose us" data-testid="trust-strip">
        <ul class="wd-trust-list">
            @foreach ($trustFacts as $fact)
                <li>{{ $fact }}</li>
            @endforeach
        </ul>
    </section>
@endif
