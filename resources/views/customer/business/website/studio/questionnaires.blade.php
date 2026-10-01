{{--
    Website Builder redesign — the Business's own completed setup
    answers. Structurally ready for future business-authored
    questionnaires (a deferred follow-up); today this always shows the
    single Photobooth website-setup response.
--}}
@if ($responses->isEmpty())
    <x-empty-state icon="list-checks" title="No questionnaire responses yet" />
@else
    <div class="row">
        @foreach ($responses as $response)
            <div class="col-md-6 mb-3">
                <x-card title="Website setup">
                    <p class="text-caption mb-2">
                        Status: {{ ucfirst(str_replace('_', ' ', $response->status->value)) }}
                        @if ($response->completed_at)
                            &middot; completed {{ $response->completed_at->diffForHumans() }}
                        @endif
                    </p>
                    <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.edit-setup', [$workspaceUid, $businessUid]) }}">Edit answers</x-button>
                </x-card>
            </div>
        @endforeach
    </div>
@endif
