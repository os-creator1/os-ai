@extends('marketing.layout')

@push('head')
<link rel="stylesheet" href="{{ $mg->asset('css/help-pages.css') }}">
@endpush

@section('content')
@php($email = $mg->get('support.email'))
<div class="mg-help-page">
    <section class="mg-help-hero mg-help-hero-docs">
        <div class="wrap mg-help-hero-inner">
            <nav class="mg-help-breadcrumb" aria-label="Breadcrumb"><a href="{{ $mg->url('home') }}">Home</a><span aria-hidden="true">/</span><a href="{{ $mg->url('support') }}">Support</a><span aria-hidden="true">/</span><span aria-current="page">Zoom guide</span></nav>
            <span class="mg-help-eyebrow">INTEGRATION DOCUMENTATION</span>
            <h1>Connect Zoom to MotionGrove.</h1>
            <p class="mg-help-lead">Add Zoom meetings to your calendar bookings, keep meeting links up to date, and control your account's connection.</p>
            <div class="mg-help-hero-actions">
                <a class="mg-help-button" href="{{ $mg->loginUrl() }}">Open MotionGrove <span aria-hidden="true">↗</span></a>
                <a class="mg-help-button-secondary" href="#connect">Start with setup</a>
            </div>
        </div>
    </section>

    <div class="wrap mg-help-docs-shell">
        <aside class="mg-help-docs-nav" aria-label="In this guide">
            <div class="mg-help-docs-nav-inner">
                <strong>On this page</strong>
                <a href="#before-you-start">Before you start</a>
                <a href="#connect">1. Connect Zoom</a>
                <a href="#use">2. Use Zoom meetings</a>
                <a href="#remove">3. Disconnect or remove</a>
                <a href="#data">What happens to data</a>
                <a href="#troubleshoot">Troubleshooting</a>
                <a href="#contact">Contact support</a>
            </div>
        </aside>
        <article class="mg-help-docs-body">
            <section id="before-you-start" class="mg-help-docs-section">
                <span class="mg-help-eyebrow">PREREQUISITES</span>
                <h2>Before you start</h2>
                <p>You'll need a MotionGrove account with access to a Business calendar, your own Zoom account, and a plan that includes native Zoom meetings.</p>
                <div class="mg-help-note"><strong>Plan availability:</strong> Zoom meetings are available to eligible Growth and Agency workspaces and eligible clients managed by an Agency. Core workspaces without an eligible Agency relationship do not have this feature. Some Zoom account types may have meeting limits.</div>
                <p>For security, each Business authorizes its own Zoom host. Connecting an Agency's Zoom account does not automatically connect a managed client's Zoom account.</p>
            </section>

            <section id="connect" class="mg-help-docs-section">
                <span class="mg-help-eyebrow">ADDING THE APP</span>
                <h2>1. Connect your Zoom account</h2>
                <ol class="mg-help-steps">
                    <li><strong>Sign in to MotionGrove.</strong> Open the workspace and Business where you manage bookings.</li>
                    <li><strong>Open Calendar → Settings → Integrations → Zoom.</strong> If the Zoom option is not visible, confirm your plan and Business access.</li>
                    <li><strong>Choose Connect Zoom.</strong> You'll be redirected to Zoom to sign in and authorize MotionGrove. You never enter your Zoom password in MotionGrove.</li>
                    <li><strong>Approve the requested permissions in Zoom.</strong> You'll return to MotionGrove, where your account should show <strong>Connected</strong>.</li>
                    <li><strong>Configure a booking type.</strong> In Calendar, edit a booking type, set <strong>Meeting location</strong> to <strong>Zoom meeting</strong>, choose an eligible connected host, and save.</li>
                </ol>
                <p>Repeat the connection process for another staff host when that staff member's own Zoom account will host bookings. If Zoom authorization fails, see <a href="#troubleshoot">Troubleshooting</a>.</p>
            </section>

            <section id="use" class="mg-help-docs-section">
                <span class="mg-help-eyebrow">USING THE APP</span>
                <h2>2. Use Zoom meetings in bookings</h2>
                <p>For a booking type configured to use Zoom, MotionGrove creates a meeting in the authorized host's Zoom account after an eligible appointment is booked.</p>
                <ul>
                    <li><strong>Share the join link:</strong> Once the meeting is ready, the appointment and supported guest confirmations or reminders include the participant join link.</li>
                    <li><strong>Reschedule:</strong> Rescheduling an appointment updates the linked Zoom meeting when the connection is still authorized.</li>
                    <li><strong>Cancel:</strong> Cancelling an appointment attempts to remove the associated Zoom meeting.</li>
                    <li><strong>Connection failure:</strong> A meeting may show a pending or failed status. Reconnect Zoom and use <strong>Retry Zoom</strong> on the appointment if offered.</li>
                    <li><strong>Choose another location:</strong> You can use in-person, phone, or a custom location instead of Zoom on a booking type or eligible appointment.</li>
                </ul>
                <div class="mg-help-note"><strong>Important:</strong> If a Zoom host is not connected, a booking type requiring that host's Zoom account may be unavailable for new bookings until the connection is fixed.</div>
            </section>

            <section id="remove" class="mg-help-docs-section">
                <span class="mg-help-eyebrow">REMOVING THE APP</span>
                <h2>3. Disconnect or remove MotionGrove from Zoom</h2>
                <h3>Disconnect inside MotionGrove</h3>
                <ol class="mg-help-steps">
                    <li>Sign in to MotionGrove and open the relevant Business.</li>
                    <li>Go to <strong>Calendar → Settings → Integrations → Zoom</strong>.</li>
                    <li>Find your connected account and click <strong>Disconnect</strong>. Confirm the action.</li>
                </ol>
                <p>You can disconnect your own account. A workspace owner may also disconnect a connected host in that Business.</p>
                <h3>Remove access through Zoom</h3>
                <ol class="mg-help-steps">
                    <li>Sign in to the <a href="https://marketplace.zoom.us/" rel="noopener noreferrer" target="_blank">Zoom App Marketplace</a>.</li>
                    <li>Open <strong>Manage → Added Apps</strong> (or find MotionGrove in your installed apps).</li>
                    <li>Select <strong>MotionGrove</strong>, then choose <strong>Remove</strong> and follow Zoom's confirmation steps.</li>
                    <li>Return to MotionGrove and disconnect the connection there as well, if it still appears, to clear the locally stored authorization credentials.</li>
                </ol>
                <p>Removing authorization prevents MotionGrove from creating, updating or cancelling Zoom meetings on your behalf until you connect again.</p>
            </section>

            <section id="data" class="mg-help-docs-section">
                <span class="mg-help-eyebrow">DATA AFTER REMOVAL</span>
                <h2>What happens to existing meetings and data?</h2>
                <p>Disconnecting Zoom in MotionGrove clears the stored access and refresh credentials for that connection. Zoom meetings already created in your Zoom account are not automatically deleted by disconnecting, and existing appointment records may still contain their previously saved participant join links.</p>
                <p>Your underlying MotionGrove workspace, appointments, contacts and subscription are not deleted merely because you disconnect Zoom. You can review our <a href="{{ $mg->url('legal') }}">legal and privacy documents</a> or contact <a href="mailto:{{ $mg->get('support.legal_email') }}">{{ $mg->get('support.legal_email') }}</a> for a personal-data request.</p>
                <p>For additional cleanup, contact our team with the affected workspace and Zoom account. Do not send access tokens or passwords.</p>
            </section>

            <section id="troubleshoot" class="mg-help-docs-section">
                <span class="mg-help-eyebrow">TROUBLESHOOTING</span>
                <h2>Common issues</h2>
                <div class="mg-help-faq">
                    <details><summary>I can't see the Zoom connection option</summary><p>Confirm you are in the correct Business calendar, have Calendar access, and have an eligible Growth/Agency entitlement or an eligible Agency-managed workspace. If the platform reports Zoom is not configured, contact support.</p></details>
                    <details><summary>Zoom authorization failed or expired</summary><p>Return to Calendar → Settings → Integrations → Zoom and select Connect Zoom or Reconnect Zoom. Make sure you sign in to the Zoom account that should host appointments.</p></details>
                    <details><summary>My guest didn't receive a Zoom link</summary><p>Open the appointment and check whether the meeting is pending or failed. If your Zoom account needs reconnecting, reconnect it first, then select Retry Zoom where available. Contact support if the link remains unavailable.</p></details>
                    <details><summary>Will disconnecting Zoom cancel my existing meetings?</summary><p>No. Disconnecting the account itself does not automatically delete previously created Zoom meetings. Future automatic updates and cancellations will no longer work until a connection is restored.</p></details>
                </div>
            </section>

            <section id="contact" class="mg-help-docs-section">
                <div class="mg-help-docs-contact">
                    <span class="mg-help-eyebrow">NEED A HAND?</span>
                    <h2>Talk to support.</h2>
                    <p>Tell us what you tried, the relevant Business, and any error message. We'll help you diagnose the connection.</p>
                    <a class="mg-help-button" href="mailto:{{ $email }}?subject=MotionGrove%20Zoom%20support">Email {{ $email }} <span aria-hidden="true">↗</span></a>
                    <p><a href="{{ $mg->url('support') }}">See support hours and response commitments →</a></p>
                </div>
            </section>
        </article>
    </div>
</div>
@endsection

@section('footer')
@include('marketing.partials.footer-full')
@endsection
