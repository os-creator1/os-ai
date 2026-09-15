@extends('layouts/fullLayoutMaster')

@section('title', $decision->heading ?? 'Account access')

@section('content')
    <section id="account-locked" class="d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 2rem 1rem;">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5">
            <div class="card">
                <div class="card-body text-center p-2 p-md-3">
                    <div class="mb-2">
                        <x-ds-icon
                            :name="$decision->state->value === 'locked_suspended' ? 'shield-off' : 'lock'"
                            size="40"
                            class="text-warning"
                        />
                    </div>

                    <h2 class="mb-1" data-role="account-locked-heading">{{ $decision->heading }}</h2>

                    <p class="text-body mb-2" data-role="account-locked-message">{{ $decision->message }}</p>

                    @if ($businessName || $planName || $planPrice)
                        <ul class="list-unstyled text-start mb-2 mx-auto" style="max-width: 22rem;" data-role="account-locked-facts">
                            @if ($businessName)
                                <li class="mb-50"><strong>Business:</strong> {{ $businessName }}</li>
                            @endif
                            @if ($planName)
                                <li class="mb-50"><strong>Plan:</strong> {{ $planName }}</li>
                            @endif
                            @if ($planPrice)
                                <li class="mb-50"><strong>Price:</strong> {{ $planPrice }}</li>
                            @endif
                        </ul>
                    @endif

                    <div class="d-grid gap-2 mx-auto" style="max-width: 22rem;">
                        @if ($recoveryUrl)
                            <a href="{{ $recoveryUrl }}" class="btn btn-primary" data-role="account-locked-recovery">
                                {{ $decision->recoveryLabel }}
                            </a>
                        @endif

                        <a href="{{ route('logout') }}"
                           class="btn btn-outline-secondary"
                           data-role="account-locked-logout"
                           onclick="event.preventDefault(); document.getElementById('account-locked-logout-form').submit();">
                            {{ __('locale.menu.Logout') }}
                        </a>
                        <form id="account-locked-logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                            {{ csrf_field() }}
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
