{{--
    Each choice has a separate verified, Business-bound token and quoted price.
    One order form: the radio group posts the chosen candidate_token (same field
    the order action has always validated); the summary panel shows the quote for
    whichever number is selected. Every figure is the server's own quote
    (MessagingChargePreview) — nothing is computed or hardcoded here.
--}}
<form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.number.order', [$workspaceUid, $businessUid]) }}" id="number-order-form" data-role="number-candidate-list">
    @csrf
    <div class="pm-picker">
        <section aria-labelledby="pm-choose-number">
            <div class="d-flex justify-content-between align-items-baseline mb-2">
                <h2 class="pm-panel__title" id="pm-choose-number">Choose a number for this Business</h2>
                <span class="pm-counter">{{ count($candidateOffers) }} available</span>
            </div>
            <div role="radiogroup" aria-labelledby="pm-choose-number" class="pm-list">
                @foreach($candidateOffers as $i => $offer)
                    <label class="pm-number" for="pm-candidate-{{ $i }}" data-role="number-candidate">
                        <input type="radio" id="pm-candidate-{{ $i }}" name="candidate_token" value="{{ $offer['token'] }}" data-offer="{{ $i }}" @checked($i === 0)>
                        <span class="pm-number__num" data-role="candidate-phone-number">{{ $offer['candidate']->phoneNumber }}</span>
                        <span class="pm-number__price">{{ $offer['preview']['configured'] ? ($offer['preview']['recurring']['text'] ?? '') : 'Price unavailable' }}</span>
                    </label>
                @endforeach
            </div>
            <p class="pm-hint mt-2">The quote shown here is re-checked with the carrier before you are charged. Text messages are billed separately, per message.</p>
        </section>

        <aside class="pm-panel pm-summarybox" aria-label="Purchase summary" aria-live="polite">
            @foreach($candidateOffers as $i => $offer)
                @php($preview = $offer['preview'])
                <div class="pm-panel__body" data-offer-panel="{{ $i }}" @if($i !== 0) hidden @endif data-role="charge-summary" data-configured="{{ $preview['configured'] ? '1' : '0' }}" data-sufficient="{{ $preview['sufficient'] ? '1' : '0' }}">
                    <div>
                        <div class="pm-numbercard__label">Your number</div>
                        <div class="pm-summarybox__number">{{ $offer['candidate']->phoneNumber }}</div>
                    </div>
                    @if(! $preview['configured'])
                        <div class="sr-callout sr-callout--warn"><x-ds-icon name="clock" size="16" /><p>Number pricing isn't set up on our side yet, so numbers can't be purchased right now. Nothing will be charged.</p></div>
                    @else
                        <dl class="pm-kv">
                            <div><dt>Today <span class="pm-hint d-block">Carrier setup + first month</span></dt><dd>{{ $preview['one_time']['text'] }}</dd></div>
                            <div><dt>Then monthly <span class="pm-hint d-block">From next month, same price</span></dt><dd>{{ $preview['recurring']['text'] ?? 'Not priced yet' }}</dd></div>
                            <div><dt>Your balance</dt><dd>{{ $preview['balance_text'] }}</dd></div>
                        </dl>
                        <p class="pm-hint mb-0" data-role="number-cost-notice">
                            Charged from your balance when you buy. Number rental and SMS usage are charged.
                            @if(($criteria['number_type'] ?? null) === 'toll_free')
                                {{ \App\Library\Messaging\BusinessMessagingRegistrationService::TOLL_FREE_DISCLOSURE }}
                            @endif
                        </p>
                        @unless($preview['sufficient'])
                            <div class="sr-callout sr-callout--warn" role="alert" data-role="add-funds"><x-ds-icon name="alert-circle" size="16" /><p>Your balance doesn't cover today's charge yet. <a href="{{ route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid]) }}">Add funds</a> to continue.</p></div>
                        @endunless
                    @endif
                    <x-button type="submit" variant="primary" :disabled="! $available || ! $preview['configured'] || ! $preview['sufficient']">Get this number{{ $preview["configured"] ? " · " . $preview["one_time"]["text"] : "" }}</x-button>
                    @unless($available)
                        <p class="pm-hint mb-0">Preview only — number setup isn't turned on in this environment.</p>
                    @endunless
                </div>
            @endforeach
        </aside>
    </div>
</form>
<script>
    (function () {
        var form = document.getElementById('number-order-form');
        if (!form) { return; }
        function sync() {
            var checked = form.querySelector('input[name="candidate_token"]:checked');
            var idx = checked ? checked.getAttribute('data-offer') : null;
            form.querySelectorAll('[data-offer-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-offer-panel') !== idx;
            });
        }
        form.addEventListener('change', sync);
        sync();
    })();
</script>
