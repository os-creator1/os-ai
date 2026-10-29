{{-- Each choice has a separate verified, Business-bound token and quoted price. --}}
<div data-role="number-candidate-list">
    <p class="text-section-heading mb-2">Choose a number for this Business</p>
    @foreach($candidateOffers as $offer)
        <div class="border rounded p-3 mb-2" data-role="number-candidate">
            <p class="h4 mb-2" data-role="candidate-phone-number">{{ $offer['candidate']->phoneNumber }}</p>
            <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.number.order', [$workspaceUid, $businessUid]) }}">
                @csrf
                <input type="hidden" name="candidate_token" value="{{ $offer['token'] }}">
                @include('customer.settings.text-messaging._charge-summary', ['preview' => $offer['preview'], 'oneTimeLabel' => 'this number, charged when you buy it: the carrier\'s upfront cost plus the first month\'s rental at the price quoted today', 'recurringLabel' => 'rental of this number, billed each month starting next month at that same quoted price', 'unconfiguredText' => 'Number pricing isn\'t set up on our side yet, so numbers can\'t be purchased right now. Nothing will be charged.'])
                <p class="text-caption text-muted mb-2" data-role="number-cost-notice">
                    Number rental and SMS usage are charged.
                    @if(($criteria['number_type'] ?? null) === 'toll_free')
                        {{ \App\Library\Messaging\BusinessMessagingRegistrationService::TOLL_FREE_DISCLOSURE }}
                    @endif
                </p>
                <x-button type="submit" variant="primary" :disabled="! $available || ! $offer['preview']['configured'] || ! $offer['preview']['sufficient']">Get this number</x-button>
            </form>
        </div>
    @endforeach
</div>
