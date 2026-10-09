{{--
    Website V1 final — "Your website's look" on the Review screen. Four REAL
    template previews (the actual renderer, fed this business), a single
    brand colour, and a logo / hero image. Changing the template here never
    touches an answer. After a website is generated the layout can only change
    through the rebuild flow (with its layout-change warning).
--}}
<div class="card mb-3" data-testid="look-panel">
    <div class="card-body">
        <h6 class="mb-1">Choose your website's look</h6>
        <p class="text-caption mb-3">Pick one of four designs — each preview shows your own business. You can change it any time before you build your website; your answers stay exactly as they are.</p>

        <form method="POST" action="{{ route('customer.workspaces.businesses.website.look.update', [$workspaceUid, $businessUid]) }}" enctype="multipart/form-data" id="look-form" data-testid="look-form">
            @csrf

            <div class="row g-3 mb-4" role="radiogroup" aria-label="Website template">
                @foreach ($templateCards as $card)
                    @php($design = $card['design'])
                    @continue($design === null)
                    <div class="col-md-6">
                        <label class="d-block h-100 border rounded p-2 template-card @if ($card['selected']) border-primary @endif" data-template-card="{{ $card['key'] }}" style="cursor:pointer; @if ($card['selected']) box-shadow: 0 0 0 2px var(--bs-primary, #7367f0); @endif">
                            <input class="visually-hidden" type="radio" name="template_key" value="{{ $card['key'] }}" @checked($card['selected']) data-template-radio>
                            <div class="template-frame position-relative overflow-hidden rounded mb-2" style="aspect-ratio: 16 / 10; background:#f1f1f1;">
                                <iframe
                                    srcdoc="{{ $card['html'] }}"
                                    title="Preview of the {{ $design->label }} template"
                                    sandbox tabindex="-1" aria-hidden="true"
                                    style="position:absolute; top:0; left:0; width:1280px; height:800px; border:0; transform-origin:0 0; pointer-events:none;"
                                    data-template-iframe></iframe>
                            </div>
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-bold">Template {{ $design->number }} &mdash; {{ $design->label }}</div>
                                    <div class="small text-muted">{{ $design->tagline }}</div>
                                    <div class="small mt-1"><span class="text-muted">Best for:</span> {{ $design->bestFor }}</div>
                                    <a class="small" href="{{ route('customer.workspaces.businesses.website.template-preview', [$workspaceUid, $businessUid, $card['key']]) }}" target="_blank" rel="noopener">Open full preview</a>
                                </div>
                                @if ($card['selected'])
                                    <span class="badge bg-primary" data-testid="template-selected">Selected</span>
                                @endif
                            </div>
                        </label>
                    </div>
                @endforeach
            </div>

            @include('customer.business.website._brand-fields', ['lookHeading' => 'Brand colour, logo and hero image', 'withScript' => true])

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary" data-testid="look-save">Save look</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        function fit(frame) {
            var wrap = frame.parentElement;
            frame.style.transform = 'scale(' + (wrap.clientWidth / 1280) + ')';
        }
        var frames = document.querySelectorAll('[data-template-iframe]');
        function fitAll() { frames.forEach(fit); }
        fitAll();
        window.addEventListener('resize', fitAll);

        var form = document.getElementById('look-form');
        form.querySelectorAll('[data-template-radio]').forEach(function (radio) {
            radio.addEventListener('change', function () { form.requestSubmit(); });
        });

    })();
</script>
