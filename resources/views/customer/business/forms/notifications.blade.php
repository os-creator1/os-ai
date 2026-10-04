@extends('layouts/contentLayoutMaster')

@section('title', $form->name.' — Notifications')

@section('content')
    @php $scope = [$workspace->uid, $business->uid]; @endphp

    <link rel="stylesheet" href="{{ asset('css/forms/form-builder.css') }}?v={{ filemtime(public_path('css/forms/form-builder.css')) }}">
    @include('customer.business.forms._messages')

    <div class="fb">
        @include('customer.business.forms._builder_header', ['tab' => 'notifications'])

        <div class="fb-settings" data-role="forms-notifications">
            <section>
                <h5>Notifications</h5>
                <p>
                    A form does not send its own notifications. When a response comes in, the form announces it
                    (<em>a form is submitted</em>), and your Automations decide who is told and how.
                </p>
                <ul class="mb-1">
                    <li>Choose the trigger <strong>A form is submitted</strong> and pick this form.</li>
                    <li>Add a <strong>Notify the team</strong> step to tell your team, or an email / SMS step to message the person.</li>
                    <li>The automation runs once per completed response — never for pages of a questionnaire that was abandoned.</li>
                </ul>
                <a class="btn btn-primary" href="{{ route('customer.workspaces.businesses.automations.workflows.create', $scope) }}" data-role="forms-notifications-automation">Create an automation</a>
            </section>
            <section>
                <h5>Not available yet</h5>
                <p class="mb-0 text-muted">
                    A built-in "email me every response" switch on the form itself, and a confirmation email to the visitor, don't exist yet.
                    Consent ticked on a form is recorded with the response; it does not by itself subscribe the person to messages.
                </p>
            </section>
        </div>
    </div>
@endsection
