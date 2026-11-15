@extends('layouts/contentLayoutMaster')

@section('title', $form->name.' — Submissions')

@section('content')
    @php
        // Per-form Submissions tab (design: FormSubmissions.dc.html). `$locations` and
        // `$submissions` are ALREADY bounded to the Locations this actor may reach
        // (FormSubmissionReader over LocationAccessGuard) and to THIS form. Nothing here
        // can list, count or name another Location's responses, and no overall total is
        // shown, because a total would disclose how many responses the actor cannot see.
        $scope = [$workspace->uid, $business->uid];
        $formScope = array_merge($scope, [$form->uid]);
        $locationOptions = ['' => 'All locations'] + collect($locations)->mapWithKeys(fn ($l) => [$l->uid => $l->name ?: 'Unnamed location'])->all();
    @endphp

    @include('customer.business.forms._assets')
    @include('customer.business.forms._messages')

    <div class="mgf" data-role="forms-builder-submissions">
        @include('customer.business.forms._builder_header', ['tab' => 'submissions'])

        <form method="GET" action="{{ route('customer.workspaces.businesses.forms.submissions.index', $scope) }}" class="mgf-toolbar" data-role="forms-location-filter">
            <input type="hidden" name="form" value="{{ $form->uid }}">
            <h2>Responses</h2>
            @include('customer.business.forms._filter_select', ['icon' => 'pin', 'name' => 'location', 'label' => 'Filter by location', 'role' => 'forms-location-select', 'options' => $locationOptions, 'selected' => $selectedLocationUid])
            <noscript><button type="submit" class="mgf-btn is-outline">Filter</button></noscript>
        </form>

        @if ($submissions->isEmpty())
            <section class="mgf-card mgf-empty" data-role="forms-submissions-empty">
                <span class="mgf-empty-ic">@include('customer.business.forms._icon', ['n' => 'inbox'])</span>
                <div class="mgf-empty-copy">
                    <b>No responses yet</b>
                    <span>Share the form or add it to your website. New responses show up here and in Contacts.</span>
                </div>
                <div class="mgf-empty-actions">
                    @if ($shareUrl)
                        <button type="button" class="mgf-btn is-primary" data-copy="{{ $shareUrl }}" data-role="forms-copy-link">@include('customer.business.forms._icon', ['n' => 'link']) Copy form link</button>
                    @else
                        <a class="mgf-btn is-primary" href="{{ route('customer.workspaces.businesses.forms.edit', $formScope) }}#settings" data-role="forms-offer-first">@include('customer.business.forms._icon', ['n' => 'link']) Choose where to offer it</a>
                    @endif
                    <a class="mgf-btn is-outline" href="{{ route('customer.workspaces.businesses.forms.edit', $formScope) }}#integrate">Add to website</a>
                </div>
            </section>
        @else
            @include('customer.business.forms._responses_list', ['showForm' => false])
        @endif
    </div>
@endsection

@section('page-script')
    @include('customer.business.forms._copy_script')
@endsection
