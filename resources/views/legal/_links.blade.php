{{--
    Context-specific links to MotionGrove's legal documents (never an acceptance control: viewing a link is not acceptance).
    @include('legal._links', ['docs' => ['terms-of-service', 'privacy-policy']])
--}}
@php($legalDocs = app(\App\Library\Legal\LegalDocuments::class))
<p class="text-caption text-muted mt-2 mb-0" data-role="legal-links">
    {{ __('Legal documents:') }}
    @foreach ($docs as $slug)
        @if ($meta = $legalDocs->meta($slug))
            <a href="{{ $meta['url'] }}" target="_blank" rel="noopener">{{ $meta['nav'] }}</a>@if (! $loop->last), @endif
        @endif
    @endforeach
</p>
