{{--
    Website Builder redesign — the Business's own completed setup
    answers. Structurally ready for future business-authored
    questionnaires (a deferred follow-up); today this always shows the
    single Photobooth website-setup response.
--}}
@if ($responses->isEmpty())
    <div class="wb-empty" data-testid="questionnaires-empty">
        <span class="wb-tile" aria-hidden="true"><x-ds-icon name="list-checks" size="28" /></span>
        <h2>No questionnaire answers yet</h2>
        <p>Send a questionnaire after booking to collect event details &mdash; venue, timing, guest count. Answers show up here.</p>
        <span class="wb-empty-hint">You can send it automatically with an automation.</span>
    </div>
@else
    <div class="wb-list">
        @foreach ($responses as $response)
            <article class="wb-list-card">
                <h2>Website setup</h2>
                <p>
                    Status: {{ ucfirst(str_replace('_', ' ', $response->status->value)) }}
                    @if ($response->completed_at)
                        &middot; completed {{ $response->completed_at->diffForHumans() }}
                    @endif
                </p>
                <div class="wb-list-actions">
                    <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.edit-setup', [$workspaceUid, $businessUid]) }}">Edit answers</x-button>
                </div>
            </article>
        @endforeach
    </div>
@endif
