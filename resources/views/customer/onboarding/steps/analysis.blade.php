@php($status = $onboarding->status->value)

<div id="analysis-status" role="status" aria-live="polite">
    @if($status === 'analysis_pending')
        <p>We're analyzing your business profile. This usually takes a few seconds.</p>
    @elseif($status === 'failed')
        <p>{{ $onboarding->analysis_error ?? 'We could not finish the analysis. Please retry.' }}</p>
        <form method="POST" action="{{ route('customer.onboarding.analysis.request') }}">
            @csrf
            <x-button type="submit" variant="primary">Retry analysis</x-button>
        </form>
    @elseif($status === 'results_ready')
        <p>Your analysis is ready.</p>
        <x-button :href="route('customer.onboarding.show', ['step' => 'results'])" variant="primary">View results</x-button>
    @else
        <p>Your business profile is saved. Ready to see how complete it is?</p>
        <form method="POST" action="{{ route('customer.onboarding.analysis.request') }}">
            @csrf
            <x-button type="submit" variant="primary">Run analysis</x-button>
        </form>
    @endif
</div>

@if($status === 'analysis_pending')
    <script>
        (function () {
            var attempt = 0;
            var maxDelayMs = 15000;

            function poll() {
                fetch('{{ route('customer.onboarding.analysis.status') }}', {
                    headers: {'Accept': 'application/json'}
                })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (data) {
                        if (data.completed && data.redirect_url) {
                            window.location.href = data.redirect_url;
                            return;
                        }

                        if (data.status === 'failed') {
                            window.location.reload();
                            return;
                        }

                        attempt++;
                        var delay = Math.min(2000 * Math.pow(2, attempt), maxDelayMs);
                        setTimeout(poll, delay);
                    })
                    .catch(function () {
                        setTimeout(poll, maxDelayMs);
                    });
            }

            setTimeout(poll, 2000);
        })();
    </script>
@endif
