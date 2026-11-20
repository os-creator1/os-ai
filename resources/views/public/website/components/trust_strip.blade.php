{{--
    Slice 6B — the homepage trust strip: two to four short, OWNER-CONFIRMED business advantages, verbatim and escaped, in a slim band under the
    hero. The facts are stored in the page itself (so a published page is frozen by its revision like any section). Fewer than two non-empty
    facts renders nothing: one claim on its own is not a strip.
--}}
@php
    $trustFacts = array_slice(array_values(array_filter((array) ($data['facts'] ?? []), fn ($fact) => is_string($fact) && trim($fact) !== '')), 0, 4);
@endphp
@if (count($trustFacts) >= 2)
    <section class="website-section website-trust-strip @if ($design ?? null) wd-trust-strip @endif" aria-label="Why customers choose us" data-testid="trust-strip">
        <ul class="wd-trust-list">
            @foreach ($trustFacts as $fact)
                <li>{{ $fact }}</li>
            @endforeach
        </ul>
    </section>
@endif
