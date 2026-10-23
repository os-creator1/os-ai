{{--
    A short legal disclosure beside a workflow, linking the governing document. Shown only while that document is
    live (published, or staging preview) so a page never points at text that is not in force. $doc = slug, $text = sentence.
--}}
@php($legalDoc = app(\App\Library\Legal\LegalRegistry::class)->visible($doc))
@if ($legalDoc)
    <p class="text-muted small mt-1 mb-0" data-role="legal-notice" data-legal-document="{{ $doc }}">
        {{ $text }}
        <a href="{{ app(\App\Library\Legal\LegalRegistry::class)->url($doc) }}" target="_blank" rel="noopener">{{ $legalDoc->title }}</a>.
    </p>
@endif
