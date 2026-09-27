{{--
    Messaging contract §13.4 — the exit path is discoverable whenever a
    retained (non-released) number exists, independent of registration
    status, suspension, or permission to buy a new number. $retainedNumber
    and $portOutRequest are computed once in TextMessagingController::situation()
    through PortOutRequestManager's ownership-safe lookup, never Slice 3's
    outbound-send resolver, so this section still appears for a Suspended
    number even when the rest of the page renders the "no number"/
    "registration required" states.
--}}
@if ($retainedNumber !== null)
    <x-card title="Port your number out" :padded="true">
        @if ($retainedNumber->status->value !== 'active')
            <p class="text-caption text-muted mb-1" data-role="retained-number-status">
                {{ $retainedNumber->phone_number }} — {{ ucfirst($retainedNumber->status->value) }}
            </p>
        @endif

        @if ($portOutRequest !== null)
            <p class="text-caption text-muted mb-2" data-role="port-out-status">
                Request received on {{ $portOutRequest->created_at?->format('Y-m-d') }}. Our team will follow up with next steps.
            </p>
            <form method="POST" action="{{ route('customer.workspaces.businesses.text-messaging.number.port-out.cancel', [$workspaceUid, $businessUid]) }}">
                @csrf
                <x-button type="submit" variant="outline" size="sm">Cancel request</x-button>
            </form>
        @else
            <p class="text-caption text-muted mb-2">You can move this number to a new carrier at any time.</p>
            <form method="POST" action="{{ route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspaceUid, $businessUid]) }}">
                @csrf
                <x-button type="submit" variant="outline" size="sm">Request to port this number out</x-button>
            </form>
        @endif
    </x-card>
@endif
