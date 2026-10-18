@if($mode === 'public')
    @if($signatureHtml)
        <div class="doc-signature-slot" data-role="signature-slot">{{ $signatureHtml }}</div>
    @endif
@else
    <div class="doc-placeholder" data-role="signature-placeholder">{{ $data['label'] ?? 'Signature' }} &mdash; the signer types their name here.</div>
@endif
