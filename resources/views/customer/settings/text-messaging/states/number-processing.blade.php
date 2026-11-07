@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@section('page-style')
    @include('customer.settings._phone-messaging-assets')
@endsection

@section('content')
<div class="sr-page sr-page--wide pm-page">
    @include('customer.settings._sr-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => 'Setting up your number',
        'chip' => 'In progress',
        'chipVariant' => '',
        'description' => 'Your purchase went through. The carrier is finishing the setup — this page updates by itself.',
    ])

    {{-- One order is already being set up: there is deliberately no purchase form here, so a
         refresh or a second visit can never place another order. --}}
    <x-card :padded="true">
        <div data-role="number-processing" data-status-url="{{ $statusUrl }}" data-show-url="{{ $showUrl }}" role="status" aria-live="polite">
            <p class="text-section-heading mb-1" data-role="processing-title">Setting up your number…</p>
            <p class="h4 mb-2" data-role="processing-number">{{ $order->phone_number }}</p>
            <p class="text-muted mb-0" data-role="processing-message">This usually takes a minute or two. You don't need to do anything, and you won't be charged again.</p>
        </div>
        <div class="mt-2 d-none" data-role="processing-actions">
            <a class="btn btn-primary btn-sm" href="{{ $showUrl }}" data-role="processing-continue">Continue</a>
        </div>
        <noscript><p class="text-muted mt-2">Reload this page in a minute to see your number.</p></noscript>
    </x-card>
</div>
@endsection

@section('page-script')
<script>
    (function () {
        var box = document.querySelector('[data-role="number-processing"]');
        if (!box) { return; }
        var statusUrl = box.getAttribute('data-status-url');
        var message = box.querySelector('[data-role="processing-message"]');
        var title = box.querySelector('[data-role="processing-title"]');
        var actions = document.querySelector('[data-role="processing-actions"]');
        var delay = 3000, stopped = false;

        function finish(text, headline, redirect) {
            stopped = true;
            title.textContent = headline;
            message.textContent = text;
            if (redirect) { window.location.href = redirect; }
        }

        function poll() {
            if (stopped) { return; }
            fetch(statusUrl, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin', cache: 'no-store'})
                .then(function (r) { if (!r.ok) { throw new Error('status ' + r.status); } return r.json(); })
                .then(function (data) {
                    if (data.state === 'completed') {
                        // Straight into the existing verification form, with the number shown.
                        finish('Your number is ready. Taking you to verification…', 'Your number is ready', data.redirect_url);
                        return;
                    }
                    if (data.state === 'failed') {
                        finish(data.message, 'We couldn\'t finish this number', null);
                        actions.classList.remove('d-none');
                        return;
                    }
                    if (data.state === 'none') { window.location.href = data.redirect_url; stopped = true; return; }
                    title.textContent = data.state === 'delayed' ? 'Still working on your number…' : 'Setting up your number…';
                    message.textContent = data.message;
                    delay = Math.min(delay + 1000, 10000);
                    setTimeout(poll, delay);
                })
                .catch(function () { delay = Math.min(delay * 2, 15000); setTimeout(poll, delay); });
        }

        setTimeout(poll, 1500);
    })();
</script>
@endsection
