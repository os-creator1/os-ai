{{--
    One "Generate my website" control. Every instance posts the same form to the same authorised route (setup.generate)
    with the same answers revision, so the sidebar, the alert and the bottom button are interchangeable; the shared
    generation-progress script disables all of them as soon as one is used.
    Expects $workspaceUid, $businessUid, $answersRevision, $lastAttemptFailed; optional $arrow, $block, $testid.
--}}
<form method="POST" data-generation-form action="{{ route('customer.workspaces.businesses.website.setup.generate', [$workspaceUid, $businessUid]) }}" class="{{ ! empty($block) ? 'd-block' : 'd-inline-block' }}">
    @csrf
    <input type="hidden" name="answers_revision" value="{{ $answersRevision }}">
    <x-button type="submit" variant="primary" :class="! empty($block) ? 'w-100 justify-content-center' : ''" :data-testid="$testid ?? 'generate-website'">{{ $lastAttemptFailed ? 'Try again' : 'Generate my website' }}@if (! empty($arrow)) &rarr;@endif</x-button>
</form>
