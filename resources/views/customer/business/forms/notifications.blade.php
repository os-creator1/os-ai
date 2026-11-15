@extends('layouts/contentLayoutMaster')

@section('title', $form->name.' — Notifications')

@section('content')
    @php
        // Notifications run through Automations (the "A form is submitted" trigger).
        // Each card creates a DRAFT automation already pointed at this form and opens it
        // in the real workflow editor; nothing is on until it is published there.
        // $linked lists only workflows that really are live for this form.
        $scope = [$workspace->uid, $business->uid];
        $formScope = array_merge($scope, [$form->uid]);
        $cards = [
            'team' => ['title' => 'Notify my team', 'desc' => 'Your team gets a notification with the answers.', 'icon' => 'bell', 'tone' => 'blue'],
            'sms' => ['title' => 'Text them back', 'desc' => 'Send the person a text right after they submit.', 'icon' => 'chat', 'tone' => 'green'],
            'email' => ['title' => 'Email them back', 'desc' => 'Send a thank-you email with next steps.', 'icon' => 'mail', 'tone' => 'red'],
        ];
    @endphp

    @include('customer.business.forms._assets')
    @include('customer.business.forms._messages')

    <div class="mgf" data-role="forms-builder-notifications">
        @include('customer.business.forms._builder_header', ['tab' => 'notifications'])

        <section class="mgf-card mgf-narrow" data-role="forms-notifications" aria-labelledby="mgf-n-title">
            <div style="display:flex;flex-direction:column;gap:4px">
                <b id="mgf-n-title" style="font-size:18px;font-weight:800">Get told when someone fills this out</b>
                <span style="font-size:14px;color:var(--mgf-mute2)">Notifications run through Automations. Pick one to start — it's set up for this form already.</span>
            </div>

            <div class="mgf-flow" aria-hidden="true">
                <div class="mgf-chip"><span class="mgf-chip-ic is-tone-red">@include('customer.business.forms._icon', ['n' => 'form'])</span><b>{{ $form->name }} is submitted</b></div>
                @include('customer.business.forms._icon', ['n' => 'arrow', 'style' => 'width:18px;height:18px;color:#B9AFA8'])
                <div class="mgf-chip"><span class="mgf-chip-ic is-tone-blue">@include('customer.business.forms._icon', ['n' => 'bell'])</span><b>Notify your team</b></div>
                <span style="font-size:13px;color:var(--mgf-faint)">or</span>
                <div class="mgf-chip"><span class="mgf-chip-ic is-tone-green">@include('customer.business.forms._icon', ['n' => 'chat'])</span><b>Text or email the person</b></div>
            </div>
            <span class="sr-only">When a response comes in, the "A form is submitted" trigger starts your automation.</span>

            @if (! $canAutomate)
                <div class="mgf-callout" data-role="forms-notifications-locked">
                    @include('customer.business.forms._icon', ['n' => 'info'])
                    <span>You don't have access to Automations here, so notifications can't be set up from this page. Ask someone who manages automations for this business to set one up with the trigger <b>A form is submitted</b>.</span>
                </div>
            @else
                <div class="mgf-acts">
                    @foreach ($cards as $key => $card)
                        @php $ok = $eligibility[$key]['available'] ?? false; @endphp
                        <form method="POST" action="{{ route('customer.workspaces.businesses.forms.notifications.start', array_merge($formScope, [$key])) }}" style="display:contents">
                            @csrf
                            <button type="{{ $ok ? 'submit' : 'button' }}" class="mgf-act" data-role="forms-notify-{{ $key }}" @disabled(! $ok) style="{{ $ok ? '' : 'opacity:.6;cursor:not-allowed' }}">
                                <span class="mgf-act-ic is-tone-{{ $card['tone'] }}">@include('customer.business.forms._icon', ['n' => $card['icon']])</span>
                                <b>{{ $card['title'] }}</b>
                                <span class="d">{{ $card['desc'] }}</span>
                                @if ($ok)
                                    <span class="go">Set up @include('customer.business.forms._icon', ['n' => 'chevron'])</span>
                                @else
                                    <span class="mgf-hint" data-role="forms-notify-unavailable">{{ $eligibility[$key]['reason'] ?: 'Not set up for this business yet.' }}</span>
                                @endif
                            </button>
                        </form>
                    @endforeach
                </div>

                <span style="font-size:13px;color:var(--mgf-mute)">Runs once per finished response. Unfinished forms never trigger it.
                    <a href="{{ route('customer.workspaces.businesses.automations.workflows.create', $scope) }}" style="color:var(--mgf-brand-d);font-weight:600" data-role="forms-notifications-automation">Build your own automation</a></span>
                <span class="mgf-hint" data-role="forms-notify-note">Set up opens a new draft automation for this form with a starter step. Nothing is sent until you review it and publish it there. Texts and emails only reach people who can be messaged — consent is checked each time it runs.</span>

                <div class="mgf-live" data-role="forms-notifications-live">
                    @forelse ($linked as $workflow)
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
                            <span class="mgf-pill {{ $workflow['paused'] ? 'is-warn' : 'is-ok' }}">{{ $workflow['paused'] ? 'Paused' : 'Live' }}</span>
                            <a href="{{ route('customer.workspaces.businesses.automations.workflows.show', array_merge($scope, [$workflow['uid']])) }}" style="font-weight:600;color:var(--mgf-brand-d)">{{ $workflow['name'] }}</a>
                        </div>
                    @empty
                        <span class="mgf-hint" data-role="forms-notifications-none">No live automation is notifying anyone about this form yet.</span>
                    @endforelse
                </div>
            @endif
        </section>

        <div class="mgf-callout mgf-narrow">
            @include('customer.business.forms._icon', ['n' => 'info'])
            <span><b>Coming later:</b> an “email me every response” switch and automatic confirmation emails. A ticked consent box is saved with the response but doesn't subscribe the person by itself.</span>
        </div>
    </div>
@endsection
