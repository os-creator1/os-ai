@extends('layouts/contentLayoutMaster')

@section('title', 'Forms')

@section('content')
    @php
        // Forms V1 — presentation only. Every authorization answer (tenancy,
        // capability, entitlement) was decided before this view rendered; every
        // control below posts to a route that runs the full chain again. A
        // public link is offered only for an Active form with an enabled direct
        // link at an active Location this actor may reach ($liveLinks), and response
        // counts are bounded to the Locations this actor may reach ($responseCounts).
        $scope = [$workspace->uid, $business->uid];
        $liveLinks = $liveLinks ?? collect();
        $responseCounts = $responseCounts ?? collect();
        $createUrl = route('customer.workspaces.businesses.forms.create', $scope);
        $responsesUrl = route('customer.workspaces.businesses.forms.submissions.index', $scope);
        // A server-side refusal from the dialog's POST (bounced back here) re-opens it.
        $reopen = $errors->has('name') || $errors->has('forms') || old('name') !== null;
    @endphp

    @include('customer.business.forms._assets')
    @include('customer.business.forms._messages')

    <div class="mgf" data-role="forms-index">
        <div class="mgf-pagehead">
            <div>
                <h1>Forms</h1>
                <p data-role="forms-count">{{ $forms->count() }} {{ \Illuminate\Support\Str::plural('form', $forms->count()) }}</p>
            </div>
            <div class="mgf-index-actions">
                <a class="mgf-btn is-outline" href="{{ $responsesUrl }}" data-role="forms-submissions">@include('customer.business.forms._icon', ['n' => 'inbox']) View all responses</a>
                <a class="mgf-btn is-primary" href="{{ $createUrl }}" data-role="forms-add" data-new-form>@include('customer.business.forms._icon', ['n' => 'plus']) New form</a>
            </div>
        </div>

        <div class="mgf-callout" style="padding:10px 16px">
            @include('customer.business.forms._icon', ['n' => 'pin'])
            <span>Each response belongs to the location it came through.</span>
        </div>

        @if ($forms->isEmpty())
            <section class="mgf-card mgf-empty">
                <span class="mgf-empty-ic">@include('customer.business.forms._icon', ['n' => 'form'])</span>
                <div class="mgf-empty-copy">
                    <b>No forms yet</b>
                    <span data-role="forms-empty">No forms yet. Create your first form.</span>
                </div>
                <a class="mgf-btn is-primary" href="{{ $createUrl }}" data-new-form>@include('customer.business.forms._icon', ['n' => 'plus']) New form</a>
            </section>
        @else
            <section class="mgf-list" data-role="forms-list" aria-label="Your forms">
                @foreach ($forms as $form)
                    @php
                        $state = $form->lifecycle_state;
                        $links = $liveLinks->get($form->id, collect());
                        $editUrl = route('customer.workspaces.businesses.forms.edit', array_merge($scope, [$form->uid]));
                        $count = (int) ($responseCounts[$form->id] ?? 0);
                        $isLive = $state === \App\Enums\Forms\FormLifecycleState::Active;
                    @endphp
                    <div class="mgf-row" data-form="{{ $form->uid }}">
                        <span class="mgf-avatar" aria-hidden="true">@include('customer.business.forms._icon', ['n' => 'form'])</span>
                        <div class="mgf-row-main">
                            <a href="{{ $editUrl }}" data-role="forms-open" style="color:inherit"><b>{{ $form->name }}</b></a>
                            <span data-role="forms-readiness">
                                @if ($state === \App\Enums\Forms\FormLifecycleState::Draft)
                                    Not public yet. Open it, choose where to offer it, then activate it.
                                @elseif ($state === \App\Enums\Forms\FormLifecycleState::Inactive)
                                    Switched off, so it accepts no responses. Past responses stay readable.
                                @elseif ($links->isEmpty())
                                    Active, but not offered at any of your locations yet.
                                @else
                                    Live at {{ $links->count() }} {{ \Illuminate\Support\Str::plural('location', $links->count()) }}.
                                @endif
                                · {{ $count }} {{ \Illuminate\Support\Str::plural('response', $count) }}
                            </span>
                        </div>
                        <div class="mgf-row-meta">
                            <span class="mgf-pill {{ $isLive ? 'is-ok' : ($state === \App\Enums\Forms\FormLifecycleState::Inactive ? 'is-warn' : '') }}" data-role="forms-state">{{ $isLive ? 'Live' : $state->label() }}</span>
                            <a class="mgf-btn is-sm is-outline" href="{{ $responsesUrl.'?form='.$form->uid }}">Responses</a>
                            @if ($links->count() === 1)
                                <a class="mgf-btn is-sm is-outline" href="{{ route('public.forms.show', [$links->first()->uid]) }}" target="_blank" rel="noopener" data-role="forms-open-live" aria-label="Open the live {{ $form->name }} form">View live</a>
                            @endif
                            <a class="mgf-btn is-sm is-outline" href="{{ $editUrl }}">Open form</a>
                        </div>
                    </div>
                @endforeach
            </section>
        @endif
    </div>

    {{-- New form dialog. The `forms.create` page stays as the no-JS / direct-link fallback;
         with JS, every [data-new-form] link opens this instead. --}}
    <div class="mgf mgf-dlg-back" id="mgf-nf-back" hidden data-open="{{ $reopen ? '1' : '0' }}" data-role="forms-new-backdrop">
        @include('customer.business.forms._new_form', ['dialog' => true])
    </div>
@endsection

@section('page-script')
    <script src="{{ asset('js/forms/new-form-dialog.js') }}?v={{ filemtime(public_path('js/forms/new-form-dialog.js')) }}"></script>
@endsection
