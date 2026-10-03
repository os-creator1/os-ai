<div class="marketing-faq">
    @forelse ($faqs as $faq)
        <details>
            <summary>{{ $faq->question }}</summary>
            <p>{{ $faq->answer }}</p>
        </details>
    @empty
        <p class="marketing-section__lede" style="margin:0 auto;text-align:center;">
            {{ __('No questions have been published yet.') }}
        </p>
    @endforelse
</div>
