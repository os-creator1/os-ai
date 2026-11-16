@extends('layouts/contentLayoutMaster')

@section('title', 'Change template or rebuild')

@section('page-style')
    @include('partials.section-router._styles')
    @include('customer.business.website.studio._styles')
@endsection

@section('content')
    @php
        $settingsUrl = route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid, 'settings']);
        $websiteUrl = route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]);
    @endphp
    <div class="wb">
        <a class="wb-back" href="{{ $settingsUrl }}"><x-ds-icon name="arrow-left" size="14" aria-hidden="true" /> Website</a>
        <div class="wb-head">
            <div class="wb-head-text">
                <h1 class="wb-title">Change template</h1>
                <div class="wb-subline"><span>Change your template or rebuild every page. Nothing goes live until you publish.</span></div>
            </div>
        </div>

        <x-flash-alert class="mb-3" />

        @if ($errors->any())
            <x-alert variant="danger" class="mb-3">{{ $errors->first() }}</x-alert>
        @endif

        <form method="POST" id="rebuild-form" class="wb-flow" action="{{ route('customer.workspaces.businesses.website.rebuild', [$workspaceUid, $businessUid]) }}">
            @csrf
            {{-- A fresh nonce per page render — see WebsiteController::runGuidedGeneration()'s own docblock. --}}
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            {{-- Set to 1 only by the confirmation dialog (below): the server still refuses an unconfirmed request. --}}
            <input type="hidden" name="confirm_rebuild" id="confirm-rebuild" value="">

            <fieldset>
                <legend class="wb-step"><span class="wb-step-n" aria-hidden="true">1</span> Choose a template</legend>
                <div class="wb-template-grid" role="radiogroup" aria-label="Website template">
                    @foreach ($templateCards as $card)
                        @php
                            $design = $card['design'];
                        @endphp
                        @continue($design === null)
                        <label class="wb-template" for="rebuild-template-{{ $card['key'] }}" data-template-card="{{ $card['key'] }}">
                            <input type="radio" name="template_key" id="rebuild-template-{{ $card['key'] }}" value="{{ $card['key'] }}" @checked($card['current'])>
                            <span class="wb-template-shot">
                                <iframe srcdoc="{{ $card['html'] }}" title="Preview of the {{ $design->label }} template" sandbox tabindex="-1" aria-hidden="true"
                                    style="position:absolute; top:0; left:0; width:1280px; height:800px; border:0; transform-origin:0 0; pointer-events:none;" data-template-iframe></iframe>
                            </span>
                            <span class="wb-template-meta">
                                <span class="wb-template-name">
                                    <span>Template {{ $design->number }} &mdash; {{ $design->label }}</span>
                                    @if ($card['current']) <span class="wb-template-tag" data-testid="template-current">Current</span> @endif
                                    <x-ds-icon name="check" size="16" class="check" aria-hidden="true" />
                                </span>
                                <span class="wb-template-tagline">{{ $design->tagline }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset>
                <legend class="wb-step"><span class="wb-step-n" aria-hidden="true">2</span> What should change?</legend>
                <div class="wb-options">
                    <label class="wb-option" for="rebuild-mode-look">
                        <input type="radio" name="mode" id="rebuild-mode-look" value="look_only" checked>
                        <span class="rd" aria-hidden="true"></span>
                        <span class="wb-option-text"><b>The look only</b><span class="wb-muted">Keep every page, word, photo and price. Fast.</span></span>
                    </label>
                    <label class="wb-option" for="rebuild-mode-full">
                        <input type="radio" name="mode" id="rebuild-mode-full" value="full">
                        <span class="rd" aria-hidden="true"></span>
                        <span class="wb-option-text"><b>Rebuild every page</b><span class="wb-muted">Rewrite all {{ $currentPageCount }} {{ $currentPageCount === 1 ? 'page' : 'pages' }} again from your answers. Takes a minute or two.</span></span>
                    </label>
                </div>
            </fieldset>

            <noscript>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="confirm_rebuild" value="1" id="confirm-rebuild-fallback">
                    <label class="form-check-label" for="confirm-rebuild-fallback">I understand this changes my draft website.</label>
                </div>
            </noscript>

            <div class="wb-bar">
                <span class="wb-bar-text" id="rebuild-summary"><b data-summary-template></b> <span class="wb-muted" data-summary-mode></span></span>
                <x-button variant="ghost" href="{{ $settingsUrl }}">Cancel</x-button>
                <x-button type="submit" variant="primary" id="rebuild-apply">Apply template</x-button>
            </div>
        </form>

        {{-- Safety at the moment of the action, not as permanent page furniture. --}}
        <x-dialog id="rebuild-confirm" size="sm">
            <span class="wb-modal-icon" aria-hidden="true"><x-ds-icon name="refresh-cw" size="20" /></span>
            <h2 class="wb-modal-title" id="rebuild-confirm-label">Change your template?</h2>
            <p class="wb-modal-text" data-testid="layout-change-warning" id="rebuild-confirm-text"></p>
            <ul class="wb-modal-points" id="rebuild-confirm-points"></ul>
            <x-slot:footer>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="rebuild-confirm-go">Continue</button>
            </x-slot:footer>
        </x-dialog>

        @include('customer.business.website._generation-progress')
    </div>

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
                    points: ['Your live site stays the same until you publish', 'Pages, words, photos and prices are kept'],
                    go: 'Change template'
                },
                full: {
                    title: 'Rebuild every page?',
                    text: 'Every draft page will be rewritten from your current answers, replacing what is there now. Visitors see nothing new until you publish.',
                    points: ['Your live site stays the same until you publish', 'Photos, packages and prices are kept', 'Edits you made to draft pages will be replaced'],
                    go: 'Rebuild pages'
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

                var points = document.getElementById('rebuild-confirm-points');
                points.textContent = '';
                copy.points.forEach(function (point) {
                    var li = document.createElement('li');
                    li.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
                    li.appendChild(document.createTextNode(point));
                    points.appendChild(li);
                });

                if (window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(dialogEl).show();
                } else if (window.confirm(copy.text)) {
                    submitConfirmed();
                }
            });

            document.getElementById('rebuild-confirm-go').addEventListener('click', submitConfirmed);

            // The sticky bar always says what "Apply" will do.
            var summaryName = document.querySelector('[data-summary-template]');
            var summaryMode = document.querySelector('[data-summary-mode]');
            function updateSummary() {
                var tpl = form.querySelector('input[name="template_key"]:checked');
                var mode = form.querySelector('input[name="mode"]:checked');
                var label = tpl && tpl.closest('label') ? tpl.closest('label').querySelector('.wb-template-name > span').textContent.replace(/^Template \d+\s*\W+\s*/, '') : '';
                summaryName.textContent = label;
                summaryMode.textContent = (mode && mode.value === 'full' ? '\u00b7 rebuild every page' : '\u00b7 change the look only') + ' \u00b7 nothing changes for visitors until you publish';
            }
            form.addEventListener('change', updateSummary);
            updateSummary();
        })();
    </script>
@endsection
