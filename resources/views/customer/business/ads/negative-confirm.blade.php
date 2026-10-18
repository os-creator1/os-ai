{{--
    Google Ads Module V1 contract §6 step 7 — the SERVER-DRIVEN confirmation
    for adding a negative keyword. Rendered from NegativeKeywordPreview (no
    provider call): it states exactly the search term, where it will be
    excluded and with which match type, and nothing is sent to Google until the
    owner confirms. Broad match is never offered. Campaign scope is the default
    (the safest: it blocks the term only in that campaign).

    Changing a choice re-renders this page (an "Update preview" submit, run
    automatically when JavaScript is available), so what is displayed is always
    what the confirm button will send. The search term text is shown here but
    NOT posted: the server reads it from its own cached row.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Add negative keyword')

@php
    $prefix = 'customer.workspaces.businesses.ads.';
    $matchLabel = ['EXACT' => 'exact', 'PHRASE' => 'phrase'][$match->value] ?? 'exact';
    $backUrl = route($prefix . 'search-terms.index', [$workspaceUid, $businessUid]);
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Add negative keyword', 'subtitle' => 'Check exactly what will change in Google Ads before you confirm.'])

    <x-flash-alert class="mb-2" />

    <x-card :padded="true" class="mb-2" data-role="negative-confirmation">
        <form method="POST" action="{{ route($prefix . 'search-terms.negative.store', [$workspaceUid, $businessUid]) }}" id="ads-negative-form" data-ads-once>
            @csrf
            <input type="hidden" name="search_term_id" value="{{ $term->id }}">
            <input type="hidden" name="campaign" value="{{ $campaignUid }}">
            @foreach($hidden as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            <dl class="row mb-2" data-role="negative-facts">
                <dt class="col-sm-4 text-label">Search term</dt>
                <dd class="col-sm-8" data-role="negative-term">"{{ $preview->term }}"</dd>

                <dt class="col-sm-4 text-label">Will be excluded from</dt>
                <dd class="col-sm-8" data-role="negative-target">
                    <strong>{{ $preview->parentName }}</strong>
                    <span class="text-caption text-muted">({{ strtolower($preview->scopeLabel) }})</span>
                </dd>

                <dt class="col-sm-4 text-label">Match</dt>
                <dd class="col-sm-8" data-role="negative-match">{{ $matchLabel }}</dd>
            </dl>

            @if($preview->alreadyExcluded)
                <x-alert variant="warning" icon="alert-triangle" role="status" class="mb-2" data-role="already-excluded">
                    This negative keyword is already in place for {{ strtolower($preview->scopeLabel) }} <strong>{{ $preview->parentName }}</strong> with {{ $matchLabel }} match. Adding it again would change nothing, so there is nothing to confirm.
                </x-alert>
            @endif

            <fieldset class="mb-2" data-role="match-choice">
                <legend class="text-label mb-1">Match type</legend>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="match" id="match-exact" value="exact" data-ads-refresh @checked($match->value === 'EXACT')>
                    <label class="form-check-label" for="match-exact">Exact: only this exact search</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="match" id="match-phrase" value="phrase" data-ads-refresh @checked($match->value === 'PHRASE')>
                    <label class="form-check-label" for="match-phrase">Phrase: searches that include these words in order</label>
                </div>
            </fieldset>

            <fieldset class="mb-2" data-role="scope-choice">
                <legend class="text-label mb-1">Where to exclude it</legend>
                @foreach($scopeOptions as $value => $option)
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="scope" id="scope-{{ $value }}" value="{{ $value }}" data-ads-refresh
                               @checked($scope->value === $value) @disabled(! $option['available'])>
                        <label class="form-check-label" for="scope-{{ $value }}">
                            {{ $option['label'] }}@if($option['name'] !== null): {{ $option['name'] }}@endif
                            @if($value === 'campaign')<span class="text-caption text-muted">(default, the safest)</span>@endif
                            @if($option['already_excluded'])<span class="text-caption text-muted">(already excluded)</span>@endif
                        </label>
                    </div>
                @endforeach
            </fieldset>

            <div class="d-flex flex-wrap gap-1 align-items-center">
                <button type="submit" class="btn btn-primary" data-role="confirm-negative" @disabled($preview->alreadyExcluded)>Add negative keyword</button>
                <button type="submit" class="btn btn-outline-secondary" id="ads-update-preview" formaction="{{ route($prefix . 'search-terms.negative.preview', [$workspaceUid, $businessUid]) }}" data-role="update-preview">Update preview</button>
                <a href="{{ $backUrl }}" class="btn btn-flat-secondary" data-role="cancel-negative">Cancel</a>
            </div>
            <p class="text-caption text-muted mt-1 mb-0">Nothing changes in Google Ads until you press "Add negative keyword".</p>
        </form>
    </x-card>
@endsection

@section('page-script')
    @include('customer.business.ads._once-script')
    <script>
        (function () {
            var form = document.getElementById('ads-negative-form');
            var update = document.getElementById('ads-update-preview');
            if (!form || !update || !form.requestSubmit) { return; }
            // Re-render the preview as soon as a choice changes, so the facts shown are always the ones confirmed.
            form.querySelectorAll('input[data-ads-refresh]').forEach(function (input) {
                input.addEventListener('change', function () { form.requestSubmit(update); });
            });
        })();
    </script>
@endsection
