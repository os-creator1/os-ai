{{--
    Google Ads Module V1 contract §6 step 7 — the confirmation that precedes a
    campaign / keyword pause or resume. States exactly what changes, then posts
    to the mutation route. The submit button is disabled on the first click
    (see _once-script) and the service dedupes regardless.

        @include('customer.business.ads._confirm-dialog', [
            'dialogId' => 'ads-confirm-' . $uid,
            'action' => route(...),
            'title' => 'Pause campaign',
            'question' => 'Pause campaign X?',
            'consequence' => 'It will stop showing ads in Google Ads until resumed.',
            'confirmLabel' => 'Pause campaign',
            'from' => 'campaigns', 'returnQuery' => [...], 'returnCampaign' => null,
        ])

    Names are customer / provider data: escaped here, never raw.
--}}
<x-dialog :id="$dialogId" :title="$title" data-role="confirm-dialog">
    <form method="POST" action="{{ $action }}" data-ads-once>
        @csrf
        <input type="hidden" name="from" value="{{ $from }}">
        @if(! empty($returnQuery))
            <input type="hidden" name="q" value="{{ http_build_query($returnQuery) }}">
        @endif
        @if(! empty($returnCampaign))
            <input type="hidden" name="return_campaign" value="{{ $returnCampaign }}">
        @endif

        <p class="mb-1" data-role="confirm-question">{{ $question }}</p>
        <p class="text-caption mb-2" data-role="confirm-consequence">{{ $consequence }}</p>

        <div class="d-flex justify-content-end gap-1">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" data-role="confirm-submit">{{ $confirmLabel }}</button>
        </div>
    </form>
</x-dialog>
