@extends('layouts/contentLayoutMaster')

@section('title', 'Change template or rebuild')

@section('page-style')
    @include('partials.section-router._styles')
@endsection

@section('content')
    <div class="row mb-3">
        <div class="col-12 d-flex justify-content-between align-items-center gap-2">
            <h4 class="mb-0">Change your template or rebuild</h4>
            <x-button variant="ghost" icon="arrow-left" href="{{ route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid, 'settings']) }}">Back</x-button>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($errors->any())
        <x-alert variant="danger" class="mb-3">{{ $errors->first() }}</x-alert>
    @endif

    <form method="POST" id="rebuild-form" action="{{ route('customer.workspaces.businesses.website.rebuild', [$workspaceUid, $businessUid]) }}">
        @csrf
        {{-- A fresh nonce per page render — see WebsiteController::runGuidedGeneration()'s own docblock. --}}
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        {{-- Set to 1 only by the confirmation dialog (below): the server still refuses an unconfirmed request. --}}
        <input type="hidden" name="confirm_rebuild" id="confirm-rebuild" value="">

        <fieldset class="mb-4">
            <legend class="h5 mb-2">Choose a template</legend>
            <div class="row g-3" role="radiogroup" aria-label="Website template">
                @foreach ($templateCards as $card)
                    @php($design = $card['design'])
                    @continue($design === null)
                    <div class="col-md-6 col-xl-3">
                        <label class="d-block h-100 border rounded p-2" for="rebuild-template-{{ $card['key'] }}" style="cursor:pointer; @if ($card['current']) box-shadow: 0 0 0 2px var(--color-primary, var(--bs-primary, #7367f0)); @endif" data-template-card="{{ $card['key'] }}">
                            <input class="form-check-input me-1" type="radio" name="template_key" id="rebuild-template-{{ $card['key'] }}" value="{{ $card['key'] }}" @checked($card['current'])>
                            <strong>Template {{ $design->number }} &mdash; {{ $design->label }}</strong>
                            @if ($card['current']) <span class="badge bg-primary ms-1" data-testid="template-current">Current</span> @endif
                            <div class="position-relative overflow-hidden rounded my-2" style="aspect-ratio: 16 / 10; background:#f1f1f1;">
                                <iframe srcdoc="{{ $card['html'] }}" title="Preview of the {{ $design->label }} template" sandbox tabindex="-1" aria-hidden="true"
                                    style="position:absolute; top:0; left:0; width:1280px; height:800px; border:0; transform-origin:0 0; pointer-events:none;" data-template-iframe></iframe>
                            </div>
                            <span class="text-caption d-block">{{ $design->tagline }}</span>
                        </label>
                    </div>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="mb-4">
            <legend class="h5 mb-2">What should change?</legend>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="mode" id="rebuild-mode-look" value="look_only" checked>
                <label class="form-check-label" for="rebuild-mode-look"><strong>The look only</strong> <span class="text-caption">&mdash; keep every page, word, photo and price</span></label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="mode" id="rebuild-mode-full" value="full">
                <label class="form-check-label" for="rebuild-mode-full"><strong>Rebuild every page</strong> <span class="text-caption">&mdash; write all {{ $currentPageCount }} {{ $currentPageCount === 1 ? 'page' : 'pages' }} again from your answers</span></label>
            </div>
        </fieldset>

        <noscript>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="confirm_rebuild" value="1" id="confirm-rebuild-fallback">
                <label class="form-check-label" for="confirm-rebuild-fallback">I understand this changes my draft website.</label>
            </div>
        </noscript>

        <x-button type="submit" variant="primary" id="rebuild-apply">Apply</x-button>
    </form>

    {{-- Safety at the moment of the action, not as permanent page furniture. --}}
    <x-dialog id="rebuild-confirm" title="Change your template?" size="sm">
        <p class="mb-0" data-testid="layout-change-warning" id="rebuild-confirm-text"></p>
        <x-slot:footer>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="rebuild-confirm-go">Continue</button>
        </x-slot:footer>
    </x-dialog>

    @include('customer.business.website._generation-progress')

    <script>
        (function () {
            function fit(frame) { frame.style.transform = 'scale(' + (frame.parentElement.clientWidth / 1280) + ')'; }
            var frames = document.querySelectorAll('[data-template-iframe]');
            function fitAll() { frames.forEach(fit); }
            fitAll();
            window.addEventListener('resize', fitAll);

            var form = document.getElementById('rebuild-form');
            var confirmField = document.getElementById('confirm-rebuild');
            var dialogEl = document.getElementById('rebuild-confirm');
            var COPY = {
                look_only: {
                    title: 'Change your template?',
                    text: 'Your header, section order, fonts and footer will change. Your pages, words, photos and prices stay as they are. Visitors see nothing new until you publish.',
                    go: 'Change template'
                },
                full: {
                    title: 'Rebuild every page?',
                    text: 'Every draft page will be rewritten from your current answers, replacing what is there now. Visitors see nothing new until you publish.',
                    go: 'Rebuild'
                }
            };

            function submitConfirmed() {
                confirmField.value = '1';

                // Only a full rebuild calls the AI (a look-only change is instant): show it is working, below the form.
                var mode = form.querySelector('input[name="mode"]:checked');
                if (mode && mode.value === 'full' && window.WebsiteGenerationProgress) {
                    if (window.bootstrap && window.bootstrap.Modal && window.bootstrap.Modal.getInstance(dialogEl)) {
                        window.bootstrap.Modal.getInstance(dialogEl).hide();
                    }

                    window.WebsiteGenerationProgress.start(form);
                }

                form.submit();
            }

            form.addEventListener('submit', function (event) {
                if (confirmField.value === '1') { return; }

                event.preventDefault();

                var mode = form.querySelector('input[name="mode"]:checked');
                var copy = COPY[mode && mode.value === 'full' ? 'full' : 'look_only'];

                document.getElementById('rebuild-confirm-label').textContent = copy.title;
                document.getElementById('rebuild-confirm-text').textContent = copy.text;
                document.getElementById('rebuild-confirm-go').textContent = copy.go;

                if (window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(dialogEl).show();
                } else if (window.confirm(copy.text)) {
                    submitConfirmed();
                }
            });

            document.getElementById('rebuild-confirm-go').addEventListener('click', submitConfirmed);
        })();
    </script>
@endsection
