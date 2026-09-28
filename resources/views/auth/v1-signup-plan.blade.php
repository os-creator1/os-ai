{{--
    Implementation Contract 21 §7 — the RESUMABLE state.

    An account whose customer backed out of Checkout is not deleted and is not
    pretended to be paid: it exists, holds no plan, and lands here. Restarting
    checkout re-drives the same durable Workspace, Business and Primary
    Location rather than creating a second set.

    Public Marketing Homepage contract: restyled onto the same
    layouts/marketing shell as v1-signup.blade.php so this feels like the
    same product mid-signup. Field names, the form action, and the plan
    loop are unchanged.
--}}
<x-layouts.marketing :title="__('Choose a plan')">
    <div class="marketing-auth">
        <div class="marketing-auth__card">
            <h1 class="marketing-auth__title">{{ __('Choose a plan') }}</h1>

            @if ($businessName)
                <p class="marketing-auth__intro">{{ __('Your account for :name is saved. Pick a plan to finish setting it up.', ['name' => $businessName]) }}</p>
            @endif

            @if (session('message'))
                <p role="status" class="marketing-auth__message">{{ session('message') }}</p>
            @endif

            @if ($errors->any())
                <div role="alert" class="marketing-auth__errors">
                    <ul class="mb-0" style="margin:0;padding-left:18px;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (count($plans) === 0)
                <p>{{ __('No plans are available right now. Please check back shortly.') }}</p>
            @else
                <form method="POST" action="{{ route('signup.resume') }}">
                    @csrf

                    <div class="marketing-auth__plan-list">
                        @foreach ($plans as $plan)
                            <label class="marketing-auth__plan" for="resume_{{ $plan['tier_value'] }}">
                                <input id="resume_{{ $plan['tier_value'] }}"
                                       name="tier"
                                       type="radio"
                                       value="{{ $plan['tier_value'] }}"
                                       @checked($loop->first)
                                       required>
                                <span>
                                    <strong>{{ $plan['display_name'] }}</strong>
                                    <span class="marketing-auth__plan-meta">
                                        {{ $plan['price'] }} {{ $plan['currency_code'] }} / {{ $plan['billing_cycle'] }}
                                        @if ($plan['trial_days'] !== null)
                                            &middot; {{ trans_choice('{1} :count day free trial|[2,*] :count day free trial', $plan['trial_days'], ['count' => $plan['trial_days']]) }}
                                        @endif
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <button type="submit" class="marketing-btn marketing-btn--primary marketing-btn--large" style="margin-top:24px;">{{ __('Continue to payment') }}</button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.marketing>
