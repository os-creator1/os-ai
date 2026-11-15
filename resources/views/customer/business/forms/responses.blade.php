@extends('layouts/contentLayoutMaster')

@section('title', 'Form responses')

@section('content')
    @php
        // All form responses (design: FormResponses.dc.html) — distinct from the per-form
        // Submissions tab, though both read through FormSubmissionReader. `$locations` and
        // `$submissions` are ALREADY bounded to the Locations this actor may reach. Nothing
        // here can list, count or name any other Location's responses, and no overall
        // total is shown: a total would disclose how many responses the actor cannot see.
        $scope = [$workspace->uid, $business->uid];
        $locationOptions = ['' => 'All locations'] + collect($locations)->mapWithKeys(fn ($l) => [$l->uid => $l->name ?: 'Unnamed location'])->all();
        $formOptions = ['' => 'All forms'] + $forms->mapWithKeys(fn ($f) => [$f->uid => $f->name])->all();
    @endphp

    @include('customer.business.forms._assets')
    @include('customer.business.forms._messages')

    <div class="mgf" data-role="forms-all-responses">
        <form method="GET" action="{{ route('customer.workspaces.businesses.forms.submissions.index', $scope) }}" class="mgf-pagehead" data-role="forms-location-filter">
            <div>
                <h1>Form responses</h1>
                <p>Every response from every form, newest first.</p>
            </div>
            @include('customer.business.forms._filter_select', ['icon' => 'form', 'name' => 'form', 'label' => 'Filter by form', 'role' => 'forms-form-select', 'options' => $formOptions, 'selected' => ''])
            @include('customer.business.forms._filter_select', ['icon' => 'pin', 'name' => 'location', 'label' => 'Filter by location', 'role' => 'forms-location-select', 'options' => $locationOptions, 'selected' => $selectedLocationUid])
            <noscript><button type="submit" class="mgf-btn is-outline">Filter</button></noscript>
        </form>

        @if ($submissions->isEmpty())
            <section class="mgf-card mgf-empty" data-role="forms-submissions-empty">
                <span class="mgf-empty-ic">@include('customer.business.forms._icon', ['n' => 'inbox'])</span>
                <div class="mgf-empty-copy">
                    <b>No responses yet</b>
                    <span>When someone fills out one of your forms, it shows up here and they're added to Contacts.</span>
                </div>
                <a class="mgf-btn is-primary" href="{{ route('customer.workspaces.businesses.forms.index', $scope) }}" data-role="forms-go-to-forms">@include('customer.business.forms._icon', ['n' => 'form']) Go to your forms</a>
            </section>
        @else
            @include('customer.business.forms._responses_list', ['showForm' => true])
        @endif
    </div>
@endsection
