{{--
    Messaging contract §13.4 — the exit path is discoverable, per retained
    (non-released, actually acquired) number, independent of registration
    status, suspension, or permission to buy a new number. $retainedNumbers
    and $portOutRequestsByNumberId are computed once in
    TextMessagingController::portOutContextFor() through
    PortOutRequestManager's ownership-safe lookup, never Slice 3's
    outbound-send resolver, so this section still lists a Suspended number
    even when the rest of the page renders the "no number"/"registration
    required" states. A Business may retain more than one number; each
    keeps its own independent request state, keyed by its own number id.
--}}
@if ($retainedNumbers->isNotEmpty())
    <x-card title="Port a number out" :padded="true">
        @foreach ($retainedNumbers as $retainedNumber)
            @php($portOutRequest = $portOutRequestsByNumberId[$retainedNumber->id] ?? null)
            <div class="mb-2 pb-2 @unless($loop->last) border-bottom @endunless" data-role="port-out-number-row" data-number-id="{{ $retainedNumber->id }}">
                <p class="mb-1">
                    <strong>{{ $retainedNumber->phone_number }}</strong>
                    @if ($retainedNumber->status->value !== 'active')
                        <span class="text-caption text-muted" data-role="retained-number-status">— {{ ucfirst($retainedNumber->status->value) }}</span>
                    @endif
                </p>

                @if ($portOutRequest !== null)
                    <p class="text-caption text-muted mb-2" data-role="port-out-status">
                        Request received on {{ $portOutRequest->created_at?->format('Y-m-d') }}. Our team will follow up with next steps.
                    </p>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.text-messaging.number.port-out.cancel', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <input type="hidden" name="number_id" value="{{ $retainedNumber->id }}">
                        <x-button type="submit" variant="outline" size="sm">Cancel request</x-button>
                    </form>
                @else
                    <p class="text-caption text-muted mb-2">You can move this number to a new carrier at any time.</p>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <input type="hidden" name="number_id" value="{{ $retainedNumber->id }}">
                        <x-button type="submit" variant="outline" size="sm">Request to port this number out</x-button>
                    </form>
                @endif
            </div>
        @endforeach
    </x-card>
@endif
