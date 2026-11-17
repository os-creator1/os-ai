@extends('layouts/contentLayoutMaster')

@section('title', 'Building your website')

@section('page-style')
    @include('partials.section-router._styles')
@endsection

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <h4 class="mb-0">Building your website</h4>
        </div>
    </div>

    <div id="generation-status" data-status-url="{{ $statusUrl }}" data-pages-url="{{ $pagesUrl }}">
        <div class="text-center my-5" data-state="running" role="status" aria-live="polite">
            <x-motiongrove />
            <p class="mt-3 mb-1" data-testid="generation-running-text">Your pages are being written. This usually takes a minute or two and keeps going in the background, so you can safely leave this page.</p>
            <p class="text-caption mb-0">Your current draft and published site stay as they are until the new pages are ready.</p>
        </div>

        <div class="text-center my-5" data-state="succeeded" hidden role="status" aria-live="polite">
            <h5 class="mb-2" data-testid="generation-success-title">Your draft is ready</h5>
            <p class="mb-3" data-message></p>
            <x-button variant="primary" href="{{ $doneUrl ?? $pagesUrl }}">Review your website</x-button>
        </div>

        <div class="text-center my-5" data-state="failed" hidden role="alert">
            <h5 class="mb-2" data-testid="generation-failed-title">We couldn't finish building your website</h5>
            <p class="mb-3" data-message></p>
            <x-button variant="primary" href="{{ $failUrl ?? $pagesUrl }}">Go back and try again</x-button>
        </div>
    </div>

    @verbatim
    <script>
        (function () {
            'use strict';
            var root = document.getElementById('generation-status');
            if (!root) { return; }
            var url = root.getAttribute('data-status-url');
            var misses = 0;

            function show(state, message) {
                root.querySelectorAll('[data-state]').forEach(function (el) {
                    el.hidden = el.getAttribute('data-state') !== state;
                });
                var target = root.querySelector('[data-state="' + state + '"] [data-message]');
                if (target && message) { target.textContent = message; }
            }

            function poll() {
                fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (response) { if (!response.ok) { throw new Error('status ' + response.status); } return response.json(); })
                    .then(function (data) {
                        misses = 0;
                        if (data.state === 'running') { return setTimeout(poll, 3000); }
                        show(data.state === 'succeeded' ? 'succeeded' : 'failed', data.message);
                    })
                    .catch(function () {
                        // A network blip is not a result: keep asking, and only give up honestly after repeated failures.
                        misses += 1;
                        if (misses >= 10) { return show('failed', 'We lost contact with the server, so we cannot tell how the build went. Open your pages to check the draft.'); }
                        setTimeout(poll, 4000);
                    });
            }

            poll();
        })();
    </script>
    @endverbatim
@endsection
