@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@inject('authBranding', 'App\Library\Branding\AuthBrandPresenter')
@php $authBrand = $authBranding->for(request()); @endphp

@extends('layouts/fullLayoutMaster')

@section('title', __('locale.sub_accounts.accept_invitation'))

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/authentication.css')) }}">
@endsection

@section('content')

    {{-- Customer Experience Slice 2: neutral/Agency identity through the branding seam; no not-authorized* fallback. --}}
    <div class="auth-wrapper auth-cover">
        <div class="auth-inner row m-0">
            <!-- Brand logo-->
            <a class="brand-logo" href="{{route('login')}}" aria-label="{{ $authBrand->displayName }}">
                <x-branding-illustration surface="auth-mark" />
            </a>
            <!-- /Brand logo-->

            <!-- Brand panel-->
            <div class="d-none d-lg-flex col-lg-8 align-items-center p-5">
                <div class="w-100 d-lg-flex align-items-center justify-content-center px-5">
                    <x-branding-illustration surface="auth" :dark="$configData['theme'] === 'dark'" />
                </div>
            </div>
            <!-- /Brand panel-->

            <!-- Accept Invitation-->
            <div class="d-flex col-lg-4 align-items-center auth-bg px-2 p-lg-5">
                <div class="col-12 col-sm-8 col-md-6 col-lg-12 px-xl-2 mx-auto">
                    <h1 class="card-title fw-bold mb-1 h2">{{ __('locale.sub_accounts.accept_invitation') }}</h1>
                    <p class="card-text mb-2">{{ __('locale.sub_accounts.accept_invitation_description') }}</p>
                    @php
                        $authFailed = in_array(session('status'), ['error', 'warning'], true) && is_string(session('message')) && trim(session('message')) !== '';
                        $passwordDescribedBy = implode(' ', array_filter([$errors->has('password') ? 'password-error' : null, $authFailed ? 'auth-flash' : null]));
                    @endphp

                    @include('auth.partials._flash-summary')

                    <form class="auth-forgot-password-form mt-2" method="POST"
                          action="{{ route('sub_account.accept.submit', $token) }}" novalidate>
                        @csrf

                        <div class="col-12">
                            <div class="mb-1">
                                <label class="form-label required"
                                       for="password">{{ __('locale.labels.password') }}</label>
                                <div class="input-group input-group-merge form-password-toggle">
                                    <input type="password" id="password"
                                           class="form-control @error('password') is-invalid @enderror"
                                           name="password" required autocomplete="new-password"
                                           @if($passwordDescribedBy !== '') aria-describedby="{{ $passwordDescribedBy }}" @endif
                                           @if($errors->has('password') || $authFailed) aria-invalid="true" @endif />
                                    <button type="button" class="input-group-text cursor-pointer" data-role="password-toggle"
                                            aria-controls="password" aria-pressed="false"
                                            aria-label="{{ __('locale.auth.show_password') }}"
                                            data-label-show="{{ __('locale.auth.show_password') }}"
                                            data-label-hide="{{ __('locale.auth.hide_password') }}"><x-ds-icon name="eye" aria-hidden="true" /></button>
                                </div>
                                @error('password')
                                <div class="invalid-feedback d-block" id="password-error" role="alert">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="mb-1">
                                <label class="form-label required"
                                       for="password_confirmation">{{ __('locale.labels.password_confirmation') }}</label>
                                <div class="input-group input-group-merge form-password-toggle">
                                    <input type="password" id="password_confirmation"
                                           class="form-control @error('password_confirmation') is-invalid @enderror"
                                           name="password_confirmation" required autocomplete="new-password" />
                                    <button type="button" class="input-group-text cursor-pointer" data-role="password-toggle"
                                            aria-controls="password_confirmation" aria-pressed="false"
                                            aria-label="{{ __('locale.auth.show_password') }}"
                                            data-label-show="{{ __('locale.auth.show_password') }}"
                                            data-label-hide="{{ __('locale.auth.hide_password') }}"><x-ds-icon name="eye" aria-hidden="true" /></button>
                                </div>
                            </div>
                        </div>


                        <button type="submit" class="btn btn-primary w-100">{{ __('locale.sub_accounts.active_account') }}</button>
                    </form>
                    <p class="text-center mt-2">
                        <a href="{{ route('login') }}">
                            <x-ds-icon name="chevron-left" aria-hidden="true" /> {{ __('locale.auth.back_to_login') }}
                        </a>
                    </p>
                </div>
            </div>
            <!-- /Accept Invitation-->

        </div>
    </div>
@endsection
