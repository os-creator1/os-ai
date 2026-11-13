@extends('marketing.layout')

@push('head')
<link rel="stylesheet" href="{{ $mg->asset('css/help-pages.css') }}">
@endpush

@section('content')
@php
    $support = $mg->get('support');
    $email = $support['email'];
@endphp
<div class="mg-help-page">
    <section class="mg-help-hero">
        <div class="wrap mg-help-hero-inner">
            <nav class="mg-help-breadcrumb" aria-label="Breadcrumb"><a href="{{ $mg->url('home') }}">Home</a><span aria-hidden="true">/</span><span aria-current="page">Support</span></nav>
            <span class="mg-help-eyebrow">MOTIONGROVE SUPPORT</span>
            <h1>We're here to help.</h1>
            <p class="mg-help-lead">Questions about your workspace, bookings, payments, or a connected app? Reach our team directly or follow a step-by-step guide.</p>
            <div class="mg-help-hero-actions">
                <a class="mg-help-button" href="mailto:{{ $email }}?subject=MotionGrove%20support%20request">Email our team <span aria-hidden="true">↗</span></a>
                <a class="mg-help-button-secondary" href="#support-options">Explore support options</a>
            </div>
        </div>
    </section>

    <section class="wrap mg-help-section" id="support-options" aria-labelledby="support-options-heading">
        <div class="mg-help-section-heading">
            <span class="mg-help-eyebrow">GET IN TOUCH</span>
            <h2 id="support-options-heading">How can we help?</h2>
            <p>Use email to contact a real person. A paid support portal or additional account is not required.</p>
        </div>
        <div class="mg-help-grid">
            <article class="mg-help-card mg-help-card-featured">
                <div class="mg-help-card-icon" aria-hidden="true">✉</div>
                <h3>Email support</h3>
                <p>Send us a question, report an issue, or ask for help setting up your MotionGrove account.</p>
                <a class="mg-help-text-link" href="mailto:{{ $email }}">{{ $email }} <span aria-hidden="true">↗</span></a>
            </article>
            <article class="mg-help-card">
                <div class="mg-help-card-icon" aria-hidden="true">↗</div>
                <h3>Create a support request</h3>
                <p>Open a new email with a support subject line. Include the affected workspace, what happened, and any relevant error message. Please do not send passwords or API secrets.</p>
                <a class="mg-help-text-link" href="mailto:{{ $email }}?subject=MotionGrove%20support%20case">Start an email support case <span aria-hidden="true">↗</span></a>
            </article>
            <article class="mg-help-card">
                <div class="mg-help-card-icon" aria-hidden="true">▤</div>
                <h3>Zoom integration guide</h3>
                <p>Connect your Zoom account, add video meetings to bookings, fix common issues, and remove access.</p>
                <a class="mg-help-text-link" href="{{ $mg->url('zoom-docs') }}">Read the Zoom guide <span aria-hidden="true">→</span></a>
            </article>
            <article class="mg-help-card">
                <div class="mg-help-card-icon" aria-hidden="true">?</div>
                <h3>Other questions</h3>
                <p>See frequently asked questions about MotionGrove and explore the main product overview.</p>
                <a class="mg-help-text-link" href="{{ $mg->url('home') }}#faq">View common questions <span aria-hidden="true">→</span></a>
            </article>
        </div>
    </section>

    <section class="wrap mg-help-section mg-help-section-tight" aria-labelledby="hours-heading">
        <div class="mg-help-service-bar">
            <div>
                <span class="mg-help-eyebrow">SUPPORT AVAILABILITY</span>
                <h2 id="hours-heading">When to expect a response</h2>
                <p>Requests can be sent any time by email. Our published support hours and first-response commitment are below.</p>
            </div>
            <dl>
                <div><dt>Support hours</dt><dd>{{ $support['hours'] }}</dd></div>
                <div><dt>Maximum first response time</dt><dd>{{ $support['first_response'] }}</dd></div>
                <div><dt>Support channel</dt><dd><a href="mailto:{{ $email }}">Email support</a></dd></div>
            </dl>
        </div>
    </section>

    <section class="wrap mg-help-section mg-help-section-tight" aria-labelledby="more-help-heading">
        <div class="mg-help-bottom">
            <div>
                <span class="mg-help-eyebrow">ALSO AVAILABLE</span>
                <h2 id="more-help-heading">Privacy or legal questions?</h2>
                <p>For legal notices and personal-data requests, use our separate legal contact.</p>
                <a class="mg-help-text-link" href="mailto:{{ $support['legal_email'] }}">{{ $support['legal_email'] }} <span aria-hidden="true">↗</span></a>
            </div>
            <div class="mg-help-small-links">
                <a href="{{ $mg->url('legal') }}">Legal Center <span aria-hidden="true">→</span></a>
                <a href="{{ $mg->url('zoom-docs') }}">Zoom setup and removal <span aria-hidden="true">→</span></a>
            </div>
        </div>
    </section>
</div>
@endsection

@section('footer')
@include('marketing.partials.footer-full')
@endsection
