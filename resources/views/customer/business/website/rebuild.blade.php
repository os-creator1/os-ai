@extends('layouts/contentLayoutMaster')

@section('title', 'Change template or rebuild')

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <h4 class="mb-1">Change your template or rebuild</h4>
            <p class="text-caption mb-0">Pick a template for your website. You can keep every page exactly as it is and change only the look, or rebuild every page from your current answers.</p>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($errors->any())
        <x-alert variant="danger" class="mb-3">{{ $errors->first() }}</x-alert>
    @endif

    <x-alert variant="warning" class="mb-3" data-testid="layout-change-warning">
        <strong>A different template changes your website's layout.</strong>
        The header, the order of sections on your home page, fonts, cards and footer all change with the template. Your pages, words, photos and package prices stay the same.
    </x-alert>

    <x-alert variant="{{ $isPublished ? 'accent' : 'neutral' }}" class="mb-3">
        @if ($isPublished)
            Your site is currently <strong>published and live</strong>. Nothing you do here changes it for visitors until you come back and click Publish. The version that is live right now stays available to roll back to afterward.
        @else
            Your site has {{ $currentPageCount }} draft page(s) and has never been published, so nothing is currently live to protect.
        @endif
    </x-alert>

    <form method="POST" action="{{ route('customer.workspaces.businesses.website.rebuild', [$workspaceUid, $businessUid]) }}">
        @csrf
        {{-- A fresh nonce per page render — see WebsiteController::runGuidedGeneration()'s own docblock. --}}
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

        <fieldset class="mb-4">
            <legend class="h5 mb-2">Choose a template</legend>
            <div class="row g-3" role="radiogroup" aria-label="Website template">
                @foreach ($templateCards as $card)
                    @php($design = $card['design'])
                    @continue($design === null)
                    <div class="col-md-6 col-xl-3">
                        <label class="d-block h-100 border rounded p-2" for="rebuild-template-{{ $card['key'] }}" style="cursor:pointer; @if ($card['current']) box-shadow: 0 0 0 2px var(--bs-primary, #7367f0); @endif" data-template-card="{{ $card['key'] }}">
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
                <label class="form-check-label" for="rebuild-mode-look"><strong>Change the look only</strong> (recommended) &mdash; keep every page, word, photo and price. Nothing is regenerated.</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="mode" id="rebuild-mode-full" value="full">
                <label class="form-check-label" for="rebuild-mode-full"><strong>Rebuild every page</strong> &mdash; write all {{ $currentPageCount }} page(s) again from your current answers. This replaces every current draft page.</label>
            </div>
        </fieldset>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="confirm_rebuild" value="1" id="confirm-rebuild">
            <label class="form-check-label" for="confirm-rebuild">
                I understand this changes my website's layout{{ $isPublished ? ' (my published site will not change until I publish)' : '' }}, and that rebuilding replaces every current draft page.
            </label>
        </div>

        <x-button type="submit" variant="primary">Apply</x-button>
        <x-button type="button" variant="ghost" onclick="window.history.back()">Cancel</x-button>
    </form>

    <script>
        (function () {
            function fit(frame) { frame.style.transform = 'scale(' + (frame.parentElement.clientWidth / 1280) + ')'; }
            var frames = document.querySelectorAll('[data-template-iframe]');
            function fitAll() { frames.forEach(fit); }
            fitAll();
            window.addEventListener('resize', fitAll);
        })();
    </script>
@endsection
