@extends('marketing.layout')

{{--
    White Label — sign up for a plan on an Agency's site. Uses the marketing shell so it carries the Agency's
    header, footer and colours. The form posts to the Agency site's own signup route; nothing about which Agency or
    which price is a field.
--}}

@section('content')
<section style="padding: 72px 0 112px">
<div class="wrap" style="display: flex; flex-wrap: wrap; gap: 48px; align-items: flex-start">
<div style="flex: 1 1 340px; display: flex; flex-direction: column; gap: 16px; max-width: 460px">
<span class="eyebrow">Your plan</span>
<h1 style="margin: 0; font-size: clamp(32px, 4vw, 46px); line-height: 1.05; font-weight: 800; letter-spacing: -0.035em">{{ $plan['name'] }}</h1>
@if ($plan['price_label'] !== null)<div><span style="font-size: 44px; font-weight: 800; letter-spacing: -0.04em">{{ $plan['price_label'] }}</span> <span style="color: #5E5652">{{ $plan['cycle_label'] }}</span></div>@endif
@if ($plan['tagline'])<p class="lead">{{ $plan['tagline'] }}</p>@endif
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px">
@foreach ($plan['features'] as $feature)<li class="li">{{ $feature }}</li>@endforeach
</ul>
<p style="margin: 0; font-size: 14px; color: #5E5652">You will pay securely with Stripe on the next step. Your account is only activated once your payment is confirmed.</p>
</div>

<form method="POST" action="{{ route('agency.site.signup.store', [$publicId, $planUid]) }}" class="card" style="flex: 1 1 400px; max-width: 560px; padding: 32px; display: flex; flex-direction: column; gap: 16px" data-role="agency-signup-form">
@csrf
<h2 style="margin: 0; font-size: 22px">Create your account</h2>
@if ($errors->any())
<div role="alert" style="padding: 12px 14px; border-radius: 12px; background: #FDECEC; color: #8A1C1C; font-size: 14.5px" data-role="signup-errors">{{ $errors->first() }}</div>
@endif
@foreach ([['first_name', 'First name', 'text', 'given-name'], ['last_name', 'Last name (optional)', 'text', 'family-name'], ['business_name', 'Business name', 'text', 'organization'], ['email', 'Email', 'email', 'email'], ['password', 'Password (8+ characters)', 'password', 'new-password'], ['password_confirmation', 'Confirm password', 'password', 'new-password']] as [$name, $label, $type, $auto])
<label style="display: flex; flex-direction: column; gap: 6px; font-weight: 600; font-size: 14.5px">{{ $label }}
<input name="{{ $name }}" type="{{ $type }}" autocomplete="{{ $auto }}" value="{{ $type === 'password' ? '' : old($name) }}" @if ($name !== 'last_name') required @endif maxlength="191" style="height: 46px; border: 1px solid #DCD5CE; border-radius: 12px; padding: 0 14px; font: inherit; font-weight: 400">
</label>
@endforeach
<label style="display: flex; gap: 10px; align-items: flex-start; font-size: 14px">
<input type="checkbox" name="accept" value="1" required style="margin-top: 3px">
<span>I agree to the @if ($terms)<a href="{{ $terms }}" target="_blank" rel="noopener" style="text-decoration: underline">terms of service</a>@else terms of service @endif @if ($privacy)and <a href="{{ $privacy }}" target="_blank" rel="noopener" style="text-decoration: underline">privacy policy</a>@endif.</span>
</label>
@if ($disclosure)<p style="margin: 0; font-size: 13px; color: #5E5652">{{ $disclosure }}</p>@endif
<button type="submit" class="btn btn-p" style="min-height: 52px" data-role="signup-submit">Continue to payment</button>
</form>
</div>
</section>
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@endsection
