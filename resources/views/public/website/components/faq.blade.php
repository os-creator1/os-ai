{{--
    Website Component Library — faq (contract §7.2/§7.1). Explicitly authored content only — no FAQ model exists to source from. Plain escaped text
    only; an answer's own paragraph breaks are kept (CSS). A FAQ shown on a page other than the site's FAQ page links to that page, at its CURRENT
    address (the navigation entry), so "all questions" is one click away; a site without a FAQ page shows no such link.
--}}
@php
    $faqPageUid = $pageUid ?? null;
    $faqPage = collect($navigationPages ?? [])->first(fn ($candidate) => ! empty($candidate['url']) && ! empty($candidate['uid'])
        && (($candidate['role'] ?? null) === 'faq' || \App\Library\Website\WebsiteNicheVocabulary::roleForSlug((string) ($candidate['slug'] ?? '')) === 'faq'));
    $faqItems = array_values(array_filter((array) ($data['items'] ?? []), fn ($item) => is_array($item)));
@endphp
<section class="website-section website-faq">
    @if (! empty($data['heading']))
        <h2 class="wd-section-title">{{ $data['heading'] }}</h2>
    @endif
    <div class="website-faq-list">
        @foreach ($faqItems as $item)
            <details class="website-faq-item">
                <summary>{{ $item['question'] ?? '' }}</summary>
                <p>{{ $item['answer'] ?? '' }}</p>
            </details>
        @endforeach
    </div>
    @if ($faqItems !== [] && $faqPage !== null && ($faqPage['uid'] ?? null) !== $faqPageUid)
        <p class="website-faq-more"><a href="{{ $faqPage['url'] }}">All questions &amp; answers &rarr;</a></p>
    @endif
</section>
