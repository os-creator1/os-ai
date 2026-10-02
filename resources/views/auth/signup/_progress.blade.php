{{--
    The locked V1 signup sequence, as one quiet line + bar: Plan, Account,
    Business, Payment. Provisioning, verification and onboarding come after
    the customer has committed and are not part of the guest steps.
--}}
@php
    $steps = [1 => __('Plan'), 2 => __('Account'), 3 => ($isAgency ?? false) ? __('Agency') : __('Business'), 4 => __('Payment')];
@endphp
<p class="text-muted small mb-50" data-role="signup-progress">
    {{ __('Step :current of :total', ['current' => $step, 'total' => count($steps)]) }} &middot; {{ $steps[$step] }}
</p>
<div class="progress mb-2" role="progressbar" aria-label="{{ __('Signup progress') }}"
     aria-valuenow="{{ $step }}" aria-valuemin="1" aria-valuemax="{{ count($steps) }}" style="height: 0.25rem;">
    <div class="progress-bar" style="width: {{ ($step / count($steps)) * 100 }}%"></div>
</div>
